#include "jinx_oracle_batch2_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <arpa/inet.h>
#include <errno.h>
#include <fnmatch.h>
#include <grp.h>
#include <libintl.h>
#include <limits.h>
#include <locale.h>
#include <netdb.h>
#include <pwd.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/ipc.h>
#include <sys/resource.h>
#include <sys/socket.h>
#include <sys/statvfs.h>
#include <sys/time.h>
#include <sys/types.h>
#include <syslog.h>
#include <time.h>
#include <unistd.h>
#include <zlib.h>

extern char **environ;

static int64_t jinx_oracle_batch2_error_reporting = INT64_MAX;

static char *b2_dup(JinxValue value) {
    uint32_t len;
    char *out;
    if (value.type != 3u || value.as.ptr == NULL) return NULL;
    len = jinx_oracle_string_len(value);
    out = (char *)malloc((size_t)len + 1u);
    if (out == NULL) return NULL;
    if (len != 0u) memcpy(out, jinx_oracle_string_bytes(value), len);
    out[len] = '\0';
    return out;
}

static JinxValue b2_copy(const char *bytes, size_t len) {
    char *out;
    if (len > UINT32_MAX) return jinx_oracle_zero_value();
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u && bytes != NULL) memcpy(out, bytes, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

static int b2_append_string(JinxZendArray *array, const char *text) {
    JinxZendString *string;
    int ok;
    if (array == NULL || text == NULL) return 0;
    string = jinx_zend_string_new(text, strlen(text));
    if (string == NULL) return 0;
    ok = jinx_zend_array_append(array, jinx_zend_string_value(string));
    jinx_zend_string_release(string);
    return ok;
}

static JinxValue b2_string_list(const char *const *items, size_t count) {
    JinxZendArray *array = jinx_zend_array_new_packed(count == 0u ? 1u : count);
    if (array == NULL) return jinx_oracle_zero_value();
    for (size_t i = 0u; i < count; i++) {
        if (items[i] != NULL && !b2_append_string(array, items[i])) {
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
    }
    return jinx_oracle_zend_array_value_owned(array);
}

static int b2_name_in_list(
    const char *name,
    const char *const *items,
    size_t count
) {
    if (name == NULL || items == NULL) return 0;
    for (size_t i = 0u; i < count; i++) {
        if (items[i] != NULL && strcasecmp(name, items[i]) == 0) return 1;
    }
    return 0;
}

static const JinxNativeExtensionMeta *b2_extension(const char *name) {
    if (name == NULL) return NULL;
    for (size_t i = 0u; i < jinx_native_extension_metadata_count; i++) {
        if (strcasecmp(name, jinx_native_extension_metadata[i].name) == 0) {
            return &jinx_native_extension_metadata[i];
        }
    }
    return NULL;
}

static const JinxNativeClassMeta *b2_class(const char *name) {
    if (name == NULL) return NULL;
    while (*name == '\\') name++;
    for (size_t i = 0u; i < jinx_native_class_metadata_count; i++) {
        if (strcasecmp(name, jinx_native_class_metadata[i].name) == 0) {
            return &jinx_native_class_metadata[i];
        }
    }
    return NULL;
}

static JinxValue b2_constant_value(const JinxNativeConstantMeta *meta) {
    if (meta == NULL) return jinx_oracle_zero_value();
    if (meta->type == 1u) return jinx_oracle_int_value((int64_t)meta->i64);
    if (meta->type == 2u) return jinx_oracle_bool_value(meta->i64 != 0);
    if (meta->type == 3u) return jinx_oracle_string_value(meta->str != NULL ? meta->str : "");
    if (meta->type == 5u) return jinx_oracle_float_value(meta->f64);
    return jinx_oracle_zero_value();
}

static JinxValue b2_defined_constants(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(
        jinx_native_constant_metadata_count == 0u ? 1u : jinx_native_constant_metadata_count
    );
    if (array == NULL) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
        JinxValue jv = b2_constant_value(&jinx_native_constant_metadata[i]);
        JinxZendValue zv;
        JinxZendString *owned = NULL;
        if (!jinx_oracle_jinx_value_to_zend(jv, &zv, &owned) ||
            !jinx_zend_array_add_assoc(
                array,
                jinx_native_constant_metadata[i].name,
                strlen(jinx_native_constant_metadata[i].name),
                zv
            )) {
            jinx_zend_string_release(owned);
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
        jinx_zend_string_release(owned);
    }
    return jinx_oracle_zend_array_value_owned(array);
}

static char *b2_escape_arg(const char *input) {
    size_t len = input != NULL ? strlen(input) : 0u;
    char *out = (char *)malloc(len * 4u + 3u);
    size_t pos = 0u;
    if (out == NULL) return NULL;
    out[pos++] = '\'';
    for (size_t i = 0u; i < len; i++) {
        if (input[i] == '\'') {
            memcpy(out + pos, "'\\''", 4u);
            pos += 4u;
        } else {
            out[pos++] = input[i];
        }
    }
    out[pos++] = '\'';
    out[pos] = '\0';
    return out;
}

static char *b2_escape_cmd(const char *input) {
    static const char *special = "#&;|*?~<>^()[]{}$\\\n\r";
    size_t len = input != NULL ? strlen(input) : 0u;
    char *out = (char *)malloc(len * 2u + 1u);
    size_t pos = 0u;
    if (out == NULL) return NULL;
    for (size_t i = 0u; i < len; i++) {
        unsigned char ch = (unsigned char)input[i];
        if (strchr(special, (int)ch) != NULL || ch == 96u) out[pos++] = '\\';
        out[pos++] = (char)ch;
    }
    out[pos] = '\0';
    return out;
}


typedef struct JinxOracleBatch2Stream {
    FILE *fp;
} JinxOracleBatch2Stream;

typedef struct JinxOracleBatch2Gzip {
    gzFile gz;
} JinxOracleBatch2Gzip;

typedef struct JinxOracleBatch2Deflate {
    z_stream stream;
    int initialized;
} JinxOracleBatch2Deflate;

static int b2_object_set_resource(JinxZendObject *object, const char *key, void *ptr) {
    JinxZendValue value = jinx_zend_null();
    if (object == NULL || object->properties == NULL || key == NULL) return 0;
    value.type = JINX_ZEND_RESOURCE;
    value.value.ptr = ptr;
    return jinx_zend_array_add_assoc(object->properties, key, strlen(key), value);
}

static void *b2_object_resource(
    JinxValue value,
    const char *class_name,
    const char *property
) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *slot;
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, class_name) != 0 ||
        object->properties == NULL) return NULL;
    slot = jinx_zend_array_find(object->properties, property, strlen(property));
    if (slot == NULL || slot->type != JINX_ZEND_RESOURCE) return NULL;
    return slot->value.ptr;
}

static JinxOracleBatch2Stream *b2_stream(JinxValue value) {
    return (JinxOracleBatch2Stream *)b2_object_resource(value, "stream", "__stream");
}

static JinxValue b2_new_stream(FILE *fp) {
    JinxOracleBatch2Stream *stream;
    JinxZendObject *object;
    if (fp == NULL) return jinx_oracle_zero_value();
    stream = (JinxOracleBatch2Stream *)calloc(1u, sizeof(*stream));
    if (stream == NULL) {
        fclose(fp);
        return jinx_oracle_zero_value();
    }
    stream->fp = fp;
    object = jinx_zend_object_new("stream");
    if (object == NULL || !b2_object_set_resource(object, "__stream", stream)) {
        fclose(fp);
        free(stream);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static JinxOracleBatch2Gzip *b2_gzip(JinxValue value) {
    return (JinxOracleBatch2Gzip *)b2_object_resource(value, "gzip-stream", "__gzip");
}

static JinxValue b2_new_gzip(gzFile gz) {
    JinxOracleBatch2Gzip *stream;
    JinxZendObject *object;
    if (gz == NULL) return jinx_oracle_zero_value();
    stream = (JinxOracleBatch2Gzip *)calloc(1u, sizeof(*stream));
    if (stream == NULL) {
        gzclose(gz);
        return jinx_oracle_zero_value();
    }
    stream->gz = gz;
    object = jinx_zend_object_new("gzip-stream");
    if (object == NULL || !b2_object_set_resource(object, "__gzip", stream)) {
        gzclose(gz);
        free(stream);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static JinxOracleBatch2Deflate *b2_deflate(JinxValue value) {
    return (JinxOracleBatch2Deflate *)b2_object_resource(value, "DeflateContext", "__deflate");
}

static JinxValue b2_new_deflate(int encoding) {
    JinxOracleBatch2Deflate *ctx;
    JinxZendObject *object;
    int rc;
    ctx = (JinxOracleBatch2Deflate *)calloc(1u, sizeof(*ctx));
    if (ctx == NULL) return jinx_oracle_zero_value();
    rc = deflateInit2(
        &ctx->stream,
        Z_DEFAULT_COMPRESSION,
        Z_DEFLATED,
        encoding,
        8,
        Z_DEFAULT_STRATEGY
    );
    if (rc != Z_OK) {
        free(ctx);
        return jinx_oracle_bool_value(0);
    }
    ctx->initialized = 1;
    object = jinx_zend_object_new("DeflateContext");
    if (object == NULL || !b2_object_set_resource(object, "__deflate", ctx)) {
        deflateEnd(&ctx->stream);
        free(ctx);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static JinxValue b2_hostbyname_value(const char *host, int all) {
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *it;
    int rc;
    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_INET;
    hints.ai_socktype = SOCK_STREAM;
    rc = getaddrinfo(host, NULL, &hints, &results);
    if (rc != 0 || results == NULL) {
        if (all) return jinx_oracle_bool_value(0);
        return jinx_oracle_string_value(host != NULL ? host : "");
    }

    if (!all) {
        char ip[INET_ADDRSTRLEN];
        struct sockaddr_in *sin = (struct sockaddr_in *)results->ai_addr;
        const char *p = inet_ntop(AF_INET, &sin->sin_addr, ip, sizeof(ip));
        JinxValue out = p != NULL
            ? b2_copy(ip, strlen(ip))
            : jinx_oracle_string_value(host);
        freeaddrinfo(results);
        return out;
    }

    {
        JinxZendArray *array = jinx_zend_array_new_packed(4u);
        if (array == NULL) {
            freeaddrinfo(results);
            return jinx_oracle_zero_value();
        }
        for (it = results; it != NULL; it = it->ai_next) {
            char ip[INET_ADDRSTRLEN];
            struct sockaddr_in *sin;
            if (it->ai_family != AF_INET) continue;
            sin = (struct sockaddr_in *)it->ai_addr;
            if (inet_ntop(AF_INET, &sin->sin_addr, ip, sizeof(ip)) == NULL) continue;
            if (!b2_append_string(array, ip)) {
                jinx_zend_array_release(array);
                freeaddrinfo(results);
                return jinx_oracle_zero_value();
            }
        }
        freeaddrinfo(results);
        return jinx_oracle_zend_array_value_owned(array);
    }
}

JinxValue jinx_oracle_batch2_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    int ok = 0;

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "function_exists") == 0 ||
        strcmp(name, "enum_exists") == 0 ||
        strcmp(name, "interface_exists") == 0 ||
        strcmp(name, "extension_loaded") == 0) {
        char *query;
        int found;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        query = b2_dup(args[0]);
        if (query == NULL) return result;
        if (strcmp(name, "function_exists") == 0) {
            found = b2_name_in_list(
                query,
                jinx_native_internal_function_names,
                jinx_native_internal_function_names_count
            );
        } else if (strcmp(name, "enum_exists") == 0) {
            found = b2_name_in_list(
                query, jinx_native_enum_names, jinx_native_enum_names_count
            );
        } else if (strcmp(name, "interface_exists") == 0) {
            found = b2_name_in_list(
                query, jinx_native_interface_names, jinx_native_interface_names_count
            );
        } else {
            found = b2_extension(query) != NULL;
        }
        free(query);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(found);
    }

    if (strcmp(name, "get_declared_classes") == 0) {
        JinxZendArray *array = jinx_zend_array_new_packed(
            jinx_native_class_metadata_count == 0u ? 1u : jinx_native_class_metadata_count
        );
        if (array == NULL) return result;
        for (size_t i = 0u; i < jinx_native_class_metadata_count; i++) {
            if (!b2_append_string(array, jinx_native_class_metadata[i].name)) {
                jinx_zend_array_release(array);
                return result;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "get_declared_interfaces") == 0) {
        result = b2_string_list(
            jinx_native_interface_names, jinx_native_interface_names_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "get_declared_traits") == 0) {
        result = b2_string_list(
            jinx_native_trait_names, jinx_native_trait_names_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "get_loaded_extensions") == 0) {
        result = b2_string_list(
            jinx_native_extension_names, jinx_native_extension_names_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "get_extension_funcs") == 0) {
        char *extension;
        const JinxNativeExtensionMeta *meta;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        extension = b2_dup(args[0]);
        if (extension == NULL) return result;
        meta = b2_extension(extension);
        free(extension);
        if (handled != NULL) *handled = 1;
        return meta != NULL
            ? b2_string_list(meta->functions, meta->function_count)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "get_defined_constants") == 0) {
        result = b2_defined_constants();
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "get_defined_functions") == 0) {
        JinxZendArray *outer = jinx_zend_array_new_packed(2u);
        JinxZendArray *internal = jinx_zend_array_new_packed(
            jinx_native_internal_function_names_count == 0u
                ? 1u
                : jinx_native_internal_function_names_count
        );
        JinxZendArray *user = jinx_zend_array_new_packed(1u);
        if (outer == NULL || internal == NULL || user == NULL) {
            jinx_zend_array_release(outer);
            jinx_zend_array_release(internal);
            jinx_zend_array_release(user);
            return result;
        }
        for (size_t i = 0u; i < jinx_native_internal_function_names_count; i++) {
            if (!b2_append_string(internal, jinx_native_internal_function_names[i])) {
                jinx_zend_array_release(outer);
                jinx_zend_array_release(internal);
                jinx_zend_array_release(user);
                return result;
            }
        }
        if (!jinx_zend_array_add_assoc(
                outer, "internal", 8u, jinx_zend_array_value(internal)
            ) ||
            !jinx_zend_array_add_assoc(
                outer, "user", 4u, jinx_zend_array_value(user)
            )) {
            jinx_zend_array_release(outer);
            jinx_zend_array_release(internal);
            jinx_zend_array_release(user);
            return result;
        }
        jinx_zend_array_release(internal);
        jinx_zend_array_release(user);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(outer);
    }

    if (strcmp(name, "get_class") == 0 ||
        strcmp(name, "get_parent_class") == 0 ||
        strcmp(name, "get_object_vars") == 0 ||
        strcmp(name, "get_mangled_object_vars") == 0) {
        JinxZendObject *object;
        if (args == NULL || argc < 1u ||
            args[0].type != JINX_ORACLE_VALUE_ZEND_OBJECT) return result;
        object = jinx_oracle_zend_object_ptr(args[0]);
        if (object == NULL || object->class_name == NULL) return result;

        if (strcmp(name, "get_class") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_string_value(object->class_name);
        }

        if (strcmp(name, "get_parent_class") == 0) {
            const JinxNativeClassMeta *meta = b2_class(object->class_name);
            if (handled != NULL) *handled = 1;
            return meta != NULL && meta->parent_count != 0u
                ? jinx_oracle_string_value(meta->parents[0])
                : jinx_oracle_bool_value(0);
        }

        if (object->properties == NULL) return result;
        {
            size_t live = jinx_zend_array_live_count(object->properties);
            JinxZendArray *copy = jinx_zend_array_new_packed(live == 0u ? 1u : live);
            if (copy == NULL) return result;
            for (size_t i = 0u; i < live; i++) {
                const JinxZendBucket *bucket =
                    jinx_zend_array_live_iter_at(object->properties, i);
                if (bucket == NULL ||
                    !jinx_oracle_zend_add_bucket(copy, bucket, 1)) {
                    jinx_zend_array_release(copy);
                    return result;
                }
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(copy);
        }
    }

    if (strcmp(name, "_") == 0 || strcmp(name, "gettext") == 0) {
        char *message;
        const char *translated;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        message = b2_dup(args[0]);
        if (message == NULL) return result;
        translated = gettext(message);
        result = b2_copy(
            translated != NULL ? translated : message,
            strlen(translated != NULL ? translated : message)
        );
        free(message);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "dgettext") == 0 || strcmp(name, "dcgettext") == 0) {
        char *domain;
        char *message;
        const char *translated;
        int category = LC_MESSAGES;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        domain = b2_dup(args[0]);
        message = b2_dup(args[1]);
        if (domain == NULL || message == NULL) {
            free(domain); free(message); return result;
        }
        if (strcmp(name, "dcgettext") == 0 && argc >= 3u) {
            category = (int)jinx_oracle_intish(args[2]);
        }
        translated = dcgettext(domain, message, category);
        result = b2_copy(
            translated != NULL ? translated : message,
            strlen(translated != NULL ? translated : message)
        );
        free(domain); free(message);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "dngettext") == 0 || strcmp(name, "dcngettext") == 0) {
        char *domain;
        char *one;
        char *many;
        unsigned long n;
        const char *translated;
        int category = LC_MESSAGES;
        if (args == NULL || argc < 4u ||
            args[0].type != 3u || args[1].type != 3u ||
            args[2].type != 3u) return result;
        domain = b2_dup(args[0]);
        one = b2_dup(args[1]);
        many = b2_dup(args[2]);
        n = (unsigned long)jinx_oracle_intish(args[3]);
        if (domain == NULL || one == NULL || many == NULL) {
            free(domain); free(one); free(many); return result;
        }
        if (strcmp(name, "dcngettext") == 0 && argc >= 5u) {
            category = (int)jinx_oracle_intish(args[4]);
        }
        translated = dcngettext(domain, one, many, n, category);
        if (translated == NULL) translated = n == 1u ? one : many;
        result = b2_copy(translated, strlen(translated));
        free(domain); free(one); free(many);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "bindtextdomain") == 0 ||
        strcmp(name, "bind_textdomain_codeset") == 0) {
        char *domain;
        char *value;
        char *bound;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        domain = b2_dup(args[0]);
        value = b2_dup(args[1]);
        if (domain == NULL || value == NULL) {
            free(domain); free(value); return result;
        }
        bound = strcmp(name, "bindtextdomain") == 0
            ? bindtextdomain(domain, value)
            : bind_textdomain_codeset(domain, value);
        if (bound != NULL) result = b2_copy(bound, strlen(bound));
        free(domain); free(value);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "connection_aborted") == 0 ||
        strcmp(name, "connection_status") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(0);
    }

    if (strcmp(name, "error_reporting") == 0) {
        int64_t previous = jinx_oracle_batch2_error_reporting;
        if (args != NULL && argc >= 1u && args[0].type != 0u) {
            jinx_oracle_batch2_error_reporting = jinx_oracle_intish(args[0]);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(previous);
    }

    if (strcmp(name, "getcwd") == 0) {
        char buffer[PATH_MAX];
        if (getcwd(buffer, sizeof(buffer)) == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return b2_copy(buffer, strlen(buffer));
    }

    if (strcmp(name, "getenv") == 0) {
        if (argc == 0u) {
            JinxZendArray *array = jinx_zend_array_new_packed(32u);
            if (array == NULL) return result;
            for (char **p = environ; p != NULL && *p != NULL; p++) {
                char *eq = strchr(*p, '=');
                JinxZendString *value;
                size_t key_len;
                if (eq == NULL) continue;
                key_len = (size_t)(eq - *p);
                value = jinx_zend_string_new(eq + 1, strlen(eq + 1));
                if (value == NULL ||
                    !jinx_zend_array_add_assoc(
                        array, *p, key_len, jinx_zend_string_value(value)
                    )) {
                    jinx_zend_string_release(value);
                    jinx_zend_array_release(array);
                    return result;
                }
                jinx_zend_string_release(value);
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(array);
        }
        if (args == NULL || args[0].type != 3u) return result;
        {
            char *key = b2_dup(args[0]);
            const char *value;
            if (key == NULL) return result;
            value = getenv(key);
            free(key);
            if (handled != NULL) *handled = 1;
            return value != NULL
                ? jinx_oracle_string_value(value)
                : jinx_oracle_bool_value(0);
        }
    }

    if (strcmp(name, "getmypid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getpid());
    }
    if (strcmp(name, "getmyuid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getuid());
    }
    if (strcmp(name, "getmygid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getgid());
    }
    if (strcmp(name, "get_current_user") == 0) {
        struct passwd *pw = getpwuid(geteuid());
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value(
            pw != NULL && pw->pw_name != NULL ? pw->pw_name : ""
        );
    }

    if (strcmp(name, "gethostname") == 0) {
        char host[256];
        if (gethostname(host, sizeof(host)) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        host[sizeof(host) - 1u] = '\0';
        if (handled != NULL) *handled = 1;
        return b2_copy(host, strlen(host));
    }

    if (strcmp(name, "getprotobyname") == 0) {
        char *query;
        struct protoent *entry;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        query = b2_dup(args[0]);
        if (query == NULL) return result;
        entry = getprotobyname(query);
        free(query);
        if (handled != NULL) *handled = 1;
        return entry != NULL
            ? jinx_oracle_int_value((int64_t)entry->p_proto)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "getprotobynumber") == 0) {
        struct protoent *entry;
        if (args == NULL || argc < 1u) return result;
        entry = getprotobynumber((int)jinx_oracle_intish(args[0]));
        if (handled != NULL) *handled = 1;
        return entry != NULL
            ? jinx_oracle_string_value(entry->p_name)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "getservbyname") == 0 ||
        strcmp(name, "getservbyport") == 0) {
        char *protocol;
        struct servent *entry = NULL;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        protocol = b2_dup(args[1]);
        if (protocol == NULL) return result;
        if (strcmp(name, "getservbyname") == 0) {
            char *service;
            if (args[0].type != 3u) { free(protocol); return result; }
            service = b2_dup(args[0]);
            if (service == NULL) { free(protocol); return result; }
            entry = getservbyname(service, protocol);
            free(service);
            free(protocol);
            if (handled != NULL) *handled = 1;
            return entry != NULL
                ? jinx_oracle_int_value((int64_t)ntohs((uint16_t)entry->s_port))
                : jinx_oracle_bool_value(0);
        }
        entry = getservbyport(
            htons((uint16_t)jinx_oracle_intish(args[0])), protocol
        );
        free(protocol);
        if (handled != NULL) *handled = 1;
        return entry != NULL
            ? jinx_oracle_string_value(entry->s_name)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "gettimeofday") == 0) {
        struct timeval tv;
        if (gettimeofday(&tv, NULL) != 0) return result;
        if (args != NULL && argc >= 1u && jinx_oracle_boolish(args[0])) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_float_value(
                (double)tv.tv_sec + (double)tv.tv_usec / 1000000.0
            );
        }
        {
            JinxZendArray *array = jinx_zend_array_new_packed(4u);
            if (array == NULL) return result;
            jinx_zend_array_add_assoc(
                array, "sec", 3u, jinx_zend_long((int64_t)tv.tv_sec)
            );
            jinx_zend_array_add_assoc(
                array, "usec", 4u, jinx_zend_long((int64_t)tv.tv_usec)
            );
            jinx_zend_array_add_assoc(array, "minuteswest", 11u, jinx_zend_long(0));
            jinx_zend_array_add_assoc(array, "dsttime", 7u, jinx_zend_long(0));
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(array);
        }
    }

    if (strcmp(name, "getrusage") == 0) {
        struct rusage u;
        JinxZendArray *array;
        int who = RUSAGE_SELF;
        if (args != NULL && argc >= 1u && jinx_oracle_intish(args[0]) == 1) {
            who = RUSAGE_CHILDREN;
        }
        if (getrusage(who, &u) != 0) return result;
        array = jinx_zend_array_new_packed(6u);
        if (array == NULL) return result;
        jinx_zend_array_add_assoc(array, "ru_utime.tv_sec", 15u, jinx_zend_long((int64_t)u.ru_utime.tv_sec));
        jinx_zend_array_add_assoc(array, "ru_utime.tv_usec", 16u, jinx_zend_long((int64_t)u.ru_utime.tv_usec));
        jinx_zend_array_add_assoc(array, "ru_stime.tv_sec", 15u, jinx_zend_long((int64_t)u.ru_stime.tv_sec));
        jinx_zend_array_add_assoc(array, "ru_stime.tv_usec", 16u, jinx_zend_long((int64_t)u.ru_stime.tv_usec));
        jinx_zend_array_add_assoc(array, "ru_maxrss", 9u, jinx_zend_long((int64_t)u.ru_maxrss));
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "disk_free_space") == 0 ||
        strcmp(name, "diskfreespace") == 0 ||
        strcmp(name, "disk_total_space") == 0) {
        char *path;
        struct statvfs fs;
        double bytes;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        ok = statvfs(path, &fs) == 0;
        free(path);
        if (handled != NULL) *handled = 1;
        if (!ok) return jinx_oracle_bool_value(0);
        bytes = strcmp(name, "disk_total_space") == 0
            ? (double)fs.f_blocks * (double)fs.f_frsize
            : (double)fs.f_bavail * (double)fs.f_frsize;
        return jinx_oracle_float_value(bytes);
    }

    if (strcmp(name, "ftok") == 0) {
        char *path;
        key_t key;
        int project;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u ||
            jinx_oracle_string_len(args[1]) != 1u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        project = (int)jinx_oracle_string_bytes(args[1])[0];
        key = ftok(path, project);
        free(path);
        if (handled != NULL) *handled = 1;
        return key == (key_t)-1
            ? jinx_oracle_bool_value(0)
            : jinx_oracle_int_value((int64_t)key);
    }

    if (strcmp(name, "fnmatch") == 0) {
        char *pattern;
        char *subject;
        int flags;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        pattern = b2_dup(args[0]);
        subject = b2_dup(args[1]);
        flags = argc >= 3u ? (int)jinx_oracle_intish(args[2]) : 0;
        if (pattern == NULL || subject == NULL) {
            free(pattern); free(subject); return result;
        }
        ok = fnmatch(pattern, subject, flags) == 0;
        free(pattern); free(subject);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(ok);
    }

    if (strcmp(name, "flush") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(fflush(stdout) == 0);
    }

    if (strcmp(name, "closelog") == 0) {
        closelog();
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "error_log") == 0) {
        char *message;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc >= 2u && jinx_oracle_intish(args[1]) != 0) return result;
        message = b2_dup(args[0]);
        if (message == NULL) return result;
        fprintf(stderr, "%s\n", message);
        free(message);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
    }

    if (strcmp(name, "escapeshellarg") == 0 ||
        strcmp(name, "escapeshellcmd") == 0) {
        char *input;
        char *escaped;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        input = b2_dup(args[0]);
        if (input == NULL) return result;
        escaped = strcmp(name, "escapeshellarg") == 0
            ? b2_escape_arg(input)
            : b2_escape_cmd(input);
        free(input);
        if (escaped == NULL) return result;
        result = b2_copy(escaped, strlen(escaped));
        free(escaped);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "chroot") == 0) {
        char *path;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        ok = chroot(path) == 0;
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(ok);
    }

    return result;
}
