#include "jinx_oracle_batch2_builtins.h"
#include "jinx_oracle_hash_builtins.h"
#include "jinx_oracle_finfo_builtins.h"
#include "jinx_oracle_resource_registry.h"
#include "jinx_oracle_solar_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <arpa/inet.h>
#include <dirent.h>
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
#ifdef __linux__
#include <sys/prctl.h>
#endif
#include <time.h>
#include <unistd.h>
#include <zlib.h>
#ifdef JINX_HAVE_CRYPT
#include <crypt.h>
#endif
#ifdef JINX_HAVE_RESOLV
#include <arpa/nameser.h>
#include <resolv.h>
#endif

extern char **environ;

static int64_t jinx_oracle_batch2_error_reporting = JINX_NATIVE_PHP_ERROR_REPORTING;
static int jinx_oracle_batch2_assert_active = JINX_NATIVE_ASSERT_ACTIVE;
static int jinx_oracle_batch2_assert_warning = JINX_NATIVE_ASSERT_WARNING;
static int jinx_oracle_batch2_assert_bail = JINX_NATIVE_ASSERT_BAIL;
static int jinx_oracle_batch2_assert_exception = JINX_NATIVE_ASSERT_EXCEPTION;
static char *jinx_oracle_batch2_assert_callback = NULL;
static char *jinx_oracle_batch2_process_title = NULL;


static int b2_assert_option_slot(
    int option,
    int **numeric_slot,
    char ***callback_slot
) {
    if (numeric_slot != NULL) *numeric_slot = NULL;
    if (callback_slot != NULL) *callback_slot = NULL;

#ifdef ASSERT_ACTIVE
    if (option == ASSERT_ACTIVE) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_active;
        return 1;
    }
#endif
#ifdef ASSERT_WARNING
    if (option == ASSERT_WARNING) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_warning;
        return 1;
    }
#endif
#ifdef ASSERT_BAIL
    if (option == ASSERT_BAIL) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_bail;
        return 1;
    }
#endif
#ifdef ASSERT_EXCEPTION
    if (option == ASSERT_EXCEPTION) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_exception;
        return 1;
    }
#endif
#ifdef ASSERT_CALLBACK
    if (option == ASSERT_CALLBACK) {
        if (callback_slot != NULL) *callback_slot = &jinx_oracle_batch2_assert_callback;
        return 1;
    }
#endif

    /* PHP's assert constants are stable integers, but use fallback numeric
     * values when the C compiler does not see PHP headers. */
    if (option == 1) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_active;
        return 1;
    }
    if (option == 4) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_warning;
        return 1;
    }
    if (option == 3) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_bail;
        return 1;
    }
    if (option == 6) {
        if (numeric_slot != NULL) *numeric_slot = &jinx_oracle_batch2_assert_exception;
        return 1;
    }
    if (option == 2) {
        if (callback_slot != NULL) *callback_slot = &jinx_oracle_batch2_assert_callback;
        return 1;
    }
    return 0;
}

static const char *b2_process_title_current(void) {
    if (jinx_oracle_batch2_process_title != NULL) {
        return jinx_oracle_batch2_process_title;
    }
#ifdef __linux__
    {
        FILE *fp = fopen("/proc/self/cmdline", "rb");
        if (fp != NULL) {
            char buffer[4096];
            size_t n = fread(buffer, 1u, sizeof(buffer) - 1u, fp);
            fclose(fp);
            if (n != 0u) {
                for (size_t i = 0u; i < n; i++) {
                    if (buffer[i] == '\0') buffer[i] = ' ';
                }
                while (n != 0u && buffer[n - 1u] == ' ') n--;
                buffer[n] = '\0';
                jinx_oracle_batch2_process_title = (char *)malloc(n + 1u);
                if (jinx_oracle_batch2_process_title != NULL) {
                    memcpy(jinx_oracle_batch2_process_title, buffer, n + 1u);
                    return jinx_oracle_batch2_process_title;
                }
            }
        }
    }
#endif
    return NULL;
}

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

static int b2_filter_id(const char *name) {
    if (name == NULL) return -1;
    for (size_t i = 0u; i < jinx_native_filter_metadata_count; i++) {
        if (strcasecmp(name, jinx_native_filter_metadata[i].name) == 0) {
            return jinx_native_filter_metadata[i].id;
        }
    }
    return -1;
}

static const JinxNativeFilterMeta *b2_filter_by_id(int id) {
    for (size_t i = 0u; i < jinx_native_filter_metadata_count; i++) {
        if (jinx_native_filter_metadata[i].id == id) {
            return &jinx_native_filter_metadata[i];
        }
    }
    return NULL;
}


static const JinxNativeClassVarsMeta *b2_class_vars_meta(const char *name) {
    if (name == NULL) return NULL;
    for (size_t i = 0u; i < jinx_native_class_vars_metadata_count; i++) {
        if (strcasecmp(name, jinx_native_class_vars_metadata[i].class_name) == 0) {
            return &jinx_native_class_vars_metadata[i];
        }
    }
    return NULL;
}

static const char *b2_cfg_value(const char *name) {
    if (name == NULL) return NULL;
    for (size_t i = 0u; i < jinx_native_cfg_metadata_count; i++) {
        if (strcmp(name, jinx_native_cfg_metadata[i].name) == 0) {
            return jinx_native_cfg_metadata[i].value;
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

typedef struct JinxOracleBatch2Dir {
    DIR *dir;
} JinxOracleBatch2Dir;

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

static void *b2_registered_resource_pointer(JinxValue value) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    const char *keys[] = { "__stream", "__gzip", "__dir" };
    if (object == NULL || object->properties == NULL) return NULL;
    for (size_t i = 0u; i < sizeof(keys) / sizeof(keys[0]); i++) {
        JinxZendValue *slot = jinx_zend_array_find(
            object->properties, keys[i], strlen(keys[i])
        );
        if (slot != NULL && slot->type == JINX_ZEND_RESOURCE &&
            slot->value.ptr != NULL) {
            return slot->value.ptr;
        }
    }
    return NULL;
}

static JinxOracleBatch2Dir *b2_dir(JinxValue value) {
    return (JinxOracleBatch2Dir *)b2_object_resource(
        value, "directory-stream", "__dir"
    );
}

static JinxValue b2_new_dir(DIR *dir) {
    JinxOracleBatch2Dir *resource;
    JinxZendObject *object;
    if (dir == NULL) return jinx_oracle_zero_value();
    resource = (JinxOracleBatch2Dir *)calloc(1u, sizeof(*resource));
    if (resource == NULL) {
        closedir(dir);
        return jinx_oracle_zero_value();
    }
    resource->dir = dir;
    object = jinx_zend_object_new("directory-stream");
    if (object == NULL || !b2_object_set_resource(object, "__dir", resource)) {
        closedir(dir);
        free(resource);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    if (jinx_oracle_resource_register(resource, "stream") == 0) {
        closedir(dir);
        free(resource);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
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
    if (jinx_oracle_resource_register(stream, "stream") == 0) {
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
    if (jinx_oracle_resource_register(stream, "stream") == 0) {
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



typedef struct JinxOracleImageInfo {
    int width;
    int height;
    int type;
    int bits;
    int channels;
    const char *mime;
} JinxOracleImageInfo;

static uint16_t b2_u16be(const unsigned char *p) {
    return (uint16_t)(((uint16_t)p[0] << 8) | p[1]);
}

static uint32_t b2_u32be(const unsigned char *p) {
    return ((uint32_t)p[0] << 24) |
        ((uint32_t)p[1] << 16) |
        ((uint32_t)p[2] << 8) |
        (uint32_t)p[3];
}

static uint16_t b2_u16le(const unsigned char *p) {
    return (uint16_t)((uint16_t)p[0] | ((uint16_t)p[1] << 8));
}

static uint32_t b2_u32le(const unsigned char *p) {
    return (uint32_t)p[0] |
        ((uint32_t)p[1] << 8) |
        ((uint32_t)p[2] << 16) |
        ((uint32_t)p[3] << 24);
}

static int b2_image_info(
    const unsigned char *bytes,
    size_t len,
    JinxOracleImageInfo *info
) {
    if (bytes == NULL || info == NULL) return 0;
    memset(info, 0, sizeof(*info));

    if (len >= 10u &&
        (memcmp(bytes, "GIF87a", 6u) == 0 ||
         memcmp(bytes, "GIF89a", 6u) == 0)) {
        info->width = (int)b2_u16le(bytes + 6u);
        info->height = (int)b2_u16le(bytes + 8u);
        info->type = 1;
        info->mime = "image/gif";
        return info->width > 0 && info->height > 0;
    }

    if (len >= 26u &&
        memcmp(bytes, "\x89PNG\r\n\x1a\n", 8u) == 0 &&
        memcmp(bytes + 12u, "IHDR", 4u) == 0) {
        info->width = (int)b2_u32be(bytes + 16u);
        info->height = (int)b2_u32be(bytes + 20u);
        info->bits = bytes[24u];
        info->type = 3;
        info->mime = "image/png";
        return info->width > 0 && info->height > 0;
    }

    if (len >= 30u && bytes[0] == 'B' && bytes[1] == 'M') {
        info->width = (int)b2_u32le(bytes + 18u);
        info->height = (int)b2_u32le(bytes + 22u);
        if (info->height < 0) info->height = -info->height;
        info->bits = (int)b2_u16le(bytes + 28u);
        info->type = 6;
        info->mime = "image/bmp";
        return info->width > 0 && info->height > 0;
    }

    if (len >= 4u && bytes[0] == 0xffu && bytes[1] == 0xd8u) {
        size_t pos = 2u;
        while (pos + 4u <= len) {
            uint8_t marker;
            uint16_t seglen;
            while (pos < len && bytes[pos] != 0xffu) pos++;
            while (pos < len && bytes[pos] == 0xffu) pos++;
            if (pos >= len) break;
            marker = bytes[pos++];
            if (marker == 0xd8u || marker == 0xd9u ||
                (marker >= 0xd0u && marker <= 0xd7u) ||
                marker == 0x01u) {
                continue;
            }
            if (pos + 2u > len) break;
            seglen = b2_u16be(bytes + pos);
            if (seglen < 2u || pos + seglen > len) break;
            if ((marker >= 0xc0u && marker <= 0xc3u) ||
                (marker >= 0xc5u && marker <= 0xc7u) ||
                (marker >= 0xc9u && marker <= 0xcbu) ||
                (marker >= 0xcdu && marker <= 0xcfu)) {
                if (seglen < 8u) return 0;
                info->bits = bytes[pos + 2u];
                info->height = (int)b2_u16be(bytes + pos + 3u);
                info->width = (int)b2_u16be(bytes + pos + 5u);
                info->channels = bytes[pos + 7u];
                info->type = 2;
                info->mime = "image/jpeg";
                return info->width > 0 && info->height > 0;
            }
            pos += seglen;
        }
        return 0;
    }

    return 0;
}

static JinxValue b2_image_info_value(const JinxOracleImageInfo *info) {
    JinxZendArray *array;
    char geometry[96];
    JinxZendString *string;
    if (info == NULL || info->width <= 0 || info->height <= 0) {
        return jinx_oracle_bool_value(0);
    }
    array = jinx_zend_array_new_packed(8u);
    if (array == NULL) return jinx_oracle_zero_value();
    jinx_zend_array_add_index(array, 0u, jinx_zend_long(info->width));
    jinx_zend_array_add_index(array, 1u, jinx_zend_long(info->height));
    jinx_zend_array_add_index(array, 2u, jinx_zend_long(info->type));
    snprintf(
        geometry, sizeof(geometry),
        "width=\"%d\" height=\"%d\"",
        info->width, info->height
    );
    string = jinx_zend_string_new(geometry, strlen(geometry));
    if (string == NULL ||
        !jinx_zend_array_add_index(
            array, 3u, jinx_zend_string_value(string)
        )) {
        jinx_zend_string_release(string);
        jinx_zend_array_release(array);
        return jinx_oracle_zero_value();
    }
    jinx_zend_string_release(string);

    if (info->bits > 0) {
        jinx_zend_array_add_assoc(
            array, "bits", 4u, jinx_zend_long(info->bits)
        );
    }
    if (info->channels > 0) {
        jinx_zend_array_add_assoc(
            array, "channels", 8u, jinx_zend_long(info->channels)
        );
    }
    if (info->mime != NULL) {
        string = jinx_zend_string_new(info->mime, strlen(info->mime));
        if (string == NULL ||
            !jinx_zend_array_add_assoc(
                array, "mime", 4u, jinx_zend_string_value(string)
            )) {
            jinx_zend_string_release(string);
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
        jinx_zend_string_release(string);
    }
    return jinx_oracle_zend_array_value_owned(array);
}

static unsigned char *b2_read_file_bytes(
    const char *path,
    size_t *len_out
) {
    FILE *fp;
    long size;
    unsigned char *bytes;
    size_t got;
    if (path == NULL || len_out == NULL) return NULL;
    fp = fopen(path, "rb");
    if (fp == NULL) return NULL;
    if (fseek(fp, 0, SEEK_END) != 0 ||
        (size = ftell(fp)) < 0 ||
        fseek(fp, 0, SEEK_SET) != 0) {
        fclose(fp);
        return NULL;
    }
    bytes = (unsigned char *)malloc((size_t)size + 1u);
    if (bytes == NULL) {
        fclose(fp);
        return NULL;
    }
    got = size == 0 ? 0u : fread(bytes, 1u, (size_t)size, fp);
    if (got != (size_t)size && ferror(fp)) {
        free(bytes);
        fclose(fp);
        return NULL;
    }
    fclose(fp);
    *len_out = got;
    return bytes;
}

JinxValue jinx_oracle_batch2_fixture(const char *spec) {
    if (spec == NULL) return jinx_oracle_zero_value();

    if (strcmp(spec, "gz:tmp") == 0) {
        char path[] = "/tmp/jinx-gzip-fixture-XXXXXX";
        int fd = mkstemp(path);
        gzFile out;
        gzFile in;
        if (fd < 0) return jinx_oracle_zero_value();
        close(fd);
        out = gzopen(path, "wb");
        if (out == NULL) {
            unlink(path);
            return jinx_oracle_zero_value();
        }
        if (gzwrite(out, "a,b\nsecond line\n", 16u) != 16) {
            gzclose(out);
            unlink(path);
            return jinx_oracle_zero_value();
        }
        gzclose(out);
        in = gzopen(path, "rb");
        unlink(path);
        return in != NULL ? b2_new_gzip(in) : jinx_oracle_zero_value();
    }

    if (strcmp(spec, "deflate:gzip") == 0) {
        return b2_new_deflate(31);
    }

    if (strcmp(spec, "deflate:zlib") == 0) {
        return b2_new_deflate(15);
    }

    if (strncmp(spec, "hash:", 5u) == 0) {
        JinxValue hash_args[1];
        int hash_handled = 0;
        hash_args[0] = jinx_oracle_string_value(spec + 5u);
        return jinx_oracle_hash_builtin(
            "hash_init", hash_args, 1u, &hash_handled
        );
    }

    if (strcmp(spec, "finfo:default") == 0) {
        int finfo_handled = 0;
        return jinx_oracle_finfo_builtin(
            "finfo_open", NULL, 0u, &finfo_handled
        );
    }

    if (strcmp(spec, "dir:tmp") == 0) {
        return b2_new_dir(opendir("."));
    }

    return jinx_oracle_zero_value();
}


JinxValue jinx_oracle_batch2_builtin_with_context(
    JinxOracleAsmContext *ctx,
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    if (handled != NULL) *handled = 0;
    if (ctx == NULL || name == NULL) return result;

#ifdef JINX_HAVE_RESOLV
    if (strcmp(name, "dns_get_mx") == 0 ||
        strcmp(name, "getmxrr") == 0) {
        char *hostname;
        unsigned char answer[65536];
        unsigned char *cp;
        unsigned char *end;
        HEADER *header;
        int response_len;
        int questions;
        int answers;
        JinxZendArray *hosts;
        JinxZendArray *weights = NULL;
        size_t found = 0u;

        if (args == NULL || argc < 2u || args[0].type != 3u) return result;
        hostname = b2_dup(args[0]);
        if (hostname == NULL || hostname[0] == '\0') {
            free(hostname);
            return result;
        }

        response_len = res_query(
            hostname, ns_c_in, ns_t_mx, answer, (int)sizeof(answer)
        );
        free(hostname);

        hosts = jinx_zend_array_new_packed(4u);
        if (hosts == NULL) return result;
        if (argc >= 3u) {
            weights = jinx_zend_array_new_packed(4u);
            if (weights == NULL) {
                jinx_zend_array_release(hosts);
                return result;
            }
        }

        if (response_len < 0) {
            JinxValue hosts_value = jinx_oracle_zend_array_value_owned(hosts);
            if (!jinx_oracle_write_ref_arg(ctx, 1u, hosts_value)) {
                jinx_zend_array_release(hosts);
                if (weights != NULL) jinx_zend_array_release(weights);
                return result;
            }
            if (weights != NULL) {
                JinxValue weights_value = jinx_oracle_zend_array_value_owned(weights);
                if (!jinx_oracle_write_ref_arg(ctx, 2u, weights_value)) {
                    jinx_zend_array_release(weights);
                    return result;
                }
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        header = (HEADER *)answer;
        cp = answer + HFIXEDSZ;
        end = answer + response_len;
        questions = ntohs(header->qdcount);
        answers = ntohs(header->ancount);

        while (questions-- > 0) {
            int skipped = dn_skipname(cp, end);
            if (skipped < 0 || cp + skipped + QFIXEDSZ > end) {
                jinx_zend_array_release(hosts);
                if (weights != NULL) jinx_zend_array_release(weights);
                return result;
            }
            cp += skipped + QFIXEDSZ;
        }

        while (answers-- > 0 && cp < end) {
            int skipped = dn_skipname(cp, end);
            uint16_t type;
            uint16_t data_len;
            unsigned char *record_end;

            if (skipped < 0 || cp + skipped + 10u > end) {
                jinx_zend_array_release(hosts);
                if (weights != NULL) jinx_zend_array_release(weights);
                return result;
            }
            cp += skipped;
            type = ns_get16(cp);
            cp += 2u;
            cp += 2u; /* class */
            cp += 4u; /* ttl */
            data_len = ns_get16(cp);
            cp += 2u;
            if (cp + data_len > end) {
                jinx_zend_array_release(hosts);
                if (weights != NULL) jinx_zend_array_release(weights);
                return result;
            }
            record_end = cp + data_len;

            if (type == ns_t_mx && data_len >= 3u) {
                uint16_t preference = ns_get16(cp);
                char target[1024];
                int expanded = dn_expand(
                    answer, end, cp + 2u, target, sizeof(target) - 1u
                );
                if (expanded < 0) {
                    jinx_zend_array_release(hosts);
                    if (weights != NULL) jinx_zend_array_release(weights);
                    return result;
                }
                if (!b2_append_string(hosts, target) ||
                    (weights != NULL &&
                     !jinx_zend_array_append(
                         weights, jinx_zend_long((int64_t)preference)
                     ))) {
                    jinx_zend_array_release(hosts);
                    if (weights != NULL) jinx_zend_array_release(weights);
                    return result;
                }
                found++;
            }
            cp = record_end;
        }

        {
            JinxValue hosts_value = jinx_oracle_zend_array_value_owned(hosts);
            if (!jinx_oracle_write_ref_arg(ctx, 1u, hosts_value)) {
                jinx_zend_array_release(hosts);
                if (weights != NULL) jinx_zend_array_release(weights);
                return result;
            }
            if (weights != NULL) {
                JinxValue weights_value = jinx_oracle_zend_array_value_owned(weights);
                if (!jinx_oracle_write_ref_arg(ctx, 2u, weights_value)) {
                    jinx_zend_array_release(weights);
                    return result;
                }
            }
        }

        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(found != 0u);
    }
#endif

    return result;
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
        array = jinx_zend_array_new_packed(18u);
        if (array == NULL) return result;
#define B2_RUSAGE_ADD(k, v) \
        do { if (!jinx_zend_array_add_assoc(array, k, sizeof(k) - 1u, jinx_zend_long((int64_t)(v)))) { jinx_zend_array_release(array); return result; } } while (0)
        B2_RUSAGE_ADD("ru_utime.tv_sec", u.ru_utime.tv_sec);
        B2_RUSAGE_ADD("ru_utime.tv_usec", u.ru_utime.tv_usec);
        B2_RUSAGE_ADD("ru_stime.tv_sec", u.ru_stime.tv_sec);
        B2_RUSAGE_ADD("ru_stime.tv_usec", u.ru_stime.tv_usec);
        B2_RUSAGE_ADD("ru_maxrss", u.ru_maxrss);
        B2_RUSAGE_ADD("ru_ixrss", u.ru_ixrss);
        B2_RUSAGE_ADD("ru_idrss", u.ru_idrss);
        B2_RUSAGE_ADD("ru_isrss", u.ru_isrss);
        B2_RUSAGE_ADD("ru_minflt", u.ru_minflt);
        B2_RUSAGE_ADD("ru_majflt", u.ru_majflt);
        B2_RUSAGE_ADD("ru_nswap", u.ru_nswap);
        B2_RUSAGE_ADD("ru_inblock", u.ru_inblock);
        B2_RUSAGE_ADD("ru_oublock", u.ru_oublock);
        B2_RUSAGE_ADD("ru_msgsnd", u.ru_msgsnd);
        B2_RUSAGE_ADD("ru_msgrcv", u.ru_msgrcv);
        B2_RUSAGE_ADD("ru_nsignals", u.ru_nsignals);
        B2_RUSAGE_ADD("ru_nvcsw", u.ru_nvcsw);
        B2_RUSAGE_ADD("ru_nivcsw", u.ru_nivcsw);
#undef B2_RUSAGE_ADD
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
        (void)fflush(stdout);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
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


    if (strcmp(name, "gethostbyname") == 0 ||
        strcmp(name, "gethostbynamel") == 0) {
        char *host;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        host = b2_dup(args[0]);
        if (host == NULL) return result;
        result = b2_hostbyname_value(
            host, strcmp(name, "gethostbynamel") == 0
        );
        free(host);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "gethostbyaddr") == 0) {
        char *ip;
        struct sockaddr_storage storage;
        socklen_t length;
        char host[NI_MAXHOST];
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        ip = b2_dup(args[0]);
        if (ip == NULL) return result;
        memset(&storage, 0, sizeof(storage));
        if (inet_pton(AF_INET, ip, &((struct sockaddr_in *)&storage)->sin_addr) == 1) {
            struct sockaddr_in *sin = (struct sockaddr_in *)&storage;
            sin->sin_family = AF_INET;
            length = sizeof(*sin);
        } else if (inet_pton(AF_INET6, ip, &((struct sockaddr_in6 *)&storage)->sin6_addr) == 1) {
            struct sockaddr_in6 *sin6 = (struct sockaddr_in6 *)&storage;
            sin6->sin6_family = AF_INET6;
            length = sizeof(*sin6);
        } else {
            free(ip);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (getnameinfo(
            (struct sockaddr *)&storage,
            length,
            host,
            sizeof(host),
            NULL,
            0,
            NI_NAMEREQD
        ) != 0) {
            result = b2_copy(ip, strlen(ip));
        } else {
            result = b2_copy(host, strlen(host));
        }
        free(ip);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "fsockopen") == 0) {
        char *host;
        char port_text[16];
        struct addrinfo hints;
        struct addrinfo *results = NULL;
        struct addrinfo *it;
        FILE *fp = NULL;
        int port;
        if (args == NULL || argc < 2u || args[0].type != 3u) return result;
        if (argc > 2u) return result;
        host = b2_dup(args[0]);
        if (host == NULL) return result;
        port = (int)jinx_oracle_intish(args[1]);
        snprintf(port_text, sizeof(port_text), "%d", port);
        memset(&hints, 0, sizeof(hints));
        hints.ai_family = AF_UNSPEC;
        hints.ai_socktype = SOCK_STREAM;
        if (getaddrinfo(host, port_text, &hints, &results) == 0) {
            for (it = results; it != NULL; it = it->ai_next) {
                int fd = socket(it->ai_family, it->ai_socktype, it->ai_protocol);
                if (fd < 0) continue;
                if (connect(fd, it->ai_addr, it->ai_addrlen) == 0) {
                    fp = fdopen(fd, "r+");
                    if (fp == NULL) close(fd);
                    break;
                }
                close(fd);
            }
        }
        if (results != NULL) freeaddrinfo(results);
        free(host);
        if (handled != NULL) *handled = 1;
        return fp != NULL ? b2_new_stream(fp) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "ftruncate") == 0) {
        JinxOracleBatch2Stream *stream;
        if (args == NULL || argc < 2u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(
            ftruncate(
                fileno(stream->fp),
                (off_t)jinx_oracle_intish(args[1])
            ) == 0
        );
    }

    if (strcmp(name, "fputs") == 0) {
        JinxOracleBatch2Stream *stream;
        const unsigned char *bytes;
        uint32_t len;
        size_t take;
        size_t written;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        bytes = jinx_oracle_string_bytes(args[1]);
        len = jinx_oracle_string_len(args[1]);
        take = len;
        if (argc >= 3u && args[2].type != 0u) {
            int64_t limit = jinx_oracle_intish(args[2]);
            if (limit < 0) return result;
            if ((uint64_t)limit < (uint64_t)take) take = (size_t)limit;
        }
        written = take == 0u ? 0u : fwrite(bytes, 1u, take, stream->fp);
        if (handled != NULL) *handled = 1;
        return written == 0u && take != 0u && ferror(stream->fp)
            ? jinx_oracle_bool_value(0)
            : jinx_oracle_int_value((int64_t)written);
    }

    if (strcmp(name, "fprintf") == 0) {
        JinxOracleBatch2Stream *stream;
        JinxValue formatted;
        int format_ok = 0;
        size_t written;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        formatted = jinx_oracle_sprintf_values(
            args[1],
            argc > 2u ? args + 2u : NULL,
            argc > 2u ? (uint32_t)(argc - 2u) : 0u,
            &format_ok
        );
        if (!format_ok || formatted.type != 3u) return result;
        written = formatted.flags == 0u
            ? 0u
            : fwrite(formatted.as.ptr, 1u, formatted.flags, stream->fp);
        if (handled != NULL) *handled = 1;
        return written == formatted.flags
            ? jinx_oracle_int_value((int64_t)written)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "fpassthru") == 0) {
        JinxOracleBatch2Stream *stream;
        unsigned char buffer[8192];
        size_t total = 0u;
        size_t n;
        if (args == NULL || argc < 1u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        while ((n = fread(buffer, 1u, sizeof(buffer), stream->fp)) != 0u) {
            if (fwrite(buffer, 1u, n, stdout) != n) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            total += n;
        }
        if (handled != NULL) *handled = 1;
        return ferror(stream->fp)
            ? jinx_oracle_bool_value(0)
            : jinx_oracle_int_value((int64_t)total);
    }

    if (strcmp(name, "getdate") == 0) {
        time_t raw = args != NULL && argc >= 1u && args[0].type != 0u
            ? (time_t)jinx_oracle_intish(args[0])
            : time(NULL);
        struct tm tmv;
        JinxZendArray *array;
        if (localtime_r(&raw, &tmv) == NULL) return result;
        array = jinx_zend_array_new_packed(11u);
        if (array == NULL) return result;
        jinx_zend_array_add_assoc(array, "seconds", 7u, jinx_zend_long(tmv.tm_sec));
        jinx_zend_array_add_assoc(array, "minutes", 7u, jinx_zend_long(tmv.tm_min));
        jinx_zend_array_add_assoc(array, "hours", 5u, jinx_zend_long(tmv.tm_hour));
        jinx_zend_array_add_assoc(array, "mday", 4u, jinx_zend_long(tmv.tm_mday));
        jinx_zend_array_add_assoc(array, "wday", 4u, jinx_zend_long(tmv.tm_wday));
        jinx_zend_array_add_assoc(array, "mon", 3u, jinx_zend_long(tmv.tm_mon + 1));
        jinx_zend_array_add_assoc(array, "year", 4u, jinx_zend_long(tmv.tm_year + 1900));
        jinx_zend_array_add_assoc(array, "yday", 4u, jinx_zend_long(tmv.tm_yday));
        {
            char weekday[32];
            char month[32];
            JinxZendString *s;
            strftime(weekday, sizeof(weekday), "%A", &tmv);
            strftime(month, sizeof(month), "%B", &tmv);
            s = jinx_zend_string_new(weekday, strlen(weekday));
            if (s == NULL || !jinx_zend_array_add_assoc(
                array, "weekday", 7u, jinx_zend_string_value(s)
            )) {
                jinx_zend_string_release(s);
                jinx_zend_array_release(array);
                return result;
            }
            jinx_zend_string_release(s);
            s = jinx_zend_string_new(month, strlen(month));
            if (s == NULL || !jinx_zend_array_add_assoc(
                array, "month", 5u, jinx_zend_string_value(s)
            )) {
                jinx_zend_string_release(s);
                jinx_zend_array_release(array);
                return result;
            }
            jinx_zend_string_release(s);
        }
        jinx_zend_array_add_index(array, 0u, jinx_zend_long((int64_t)raw));
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "gmmktime") == 0) {
        time_t now = time(NULL);
        struct tm tmv;
        time_t out;
        if (gmtime_r(&now, &tmv) == NULL) return result;
        if (args != NULL && argc >= 1u) tmv.tm_hour = (int)jinx_oracle_intish(args[0]);
        if (args != NULL && argc >= 2u) tmv.tm_min = (int)jinx_oracle_intish(args[1]);
        if (args != NULL && argc >= 3u) tmv.tm_sec = (int)jinx_oracle_intish(args[2]);
        if (args != NULL && argc >= 4u) tmv.tm_mon = (int)jinx_oracle_intish(args[3]) - 1;
        if (args != NULL && argc >= 5u) tmv.tm_mday = (int)jinx_oracle_intish(args[4]);
        if (args != NULL && argc >= 6u) tmv.tm_year = (int)jinx_oracle_intish(args[5]) - 1900;
        tmv.tm_isdst = 0;
        out = timegm(&tmv);
        if (handled != NULL) *handled = 1;
        return out == (time_t)-1
            ? jinx_oracle_bool_value(0)
            : jinx_oracle_int_value((int64_t)out);
    }

    if (strcmp(name, "gmstrftime") == 0) {
        char *format;
        time_t raw;
        struct tm tmv;
        char buffer[4096];
        size_t len;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        format = b2_dup(args[0]);
        if (format == NULL) return result;
        raw = argc >= 2u && args[1].type != 0u
            ? (time_t)jinx_oracle_intish(args[1])
            : time(NULL);
        if (gmtime_r(&raw, &tmv) == NULL) {
            free(format);
            return result;
        }
        len = strftime(buffer, sizeof(buffer), format, &tmv);
        free(format);
        if (handled != NULL) *handled = 1;
        return b2_copy(buffer, len);
    }

    if (strcmp(name, "gzopen") == 0) {
        char *path;
        char *mode;
        gzFile gz;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        path = b2_dup(args[0]);
        mode = b2_dup(args[1]);
        if (path == NULL || mode == NULL) {
            free(path); free(mode); return result;
        }
        gz = gzopen(path, mode);
        free(path); free(mode);
        if (handled != NULL) *handled = 1;
        return gz != NULL ? b2_new_gzip(gz) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "gzclose") == 0 ||
        strcmp(name, "gzeof") == 0 ||
        strcmp(name, "gzgetc") == 0 ||
        strcmp(name, "gzgets") == 0 ||
        strcmp(name, "gzpassthru") == 0 ||
        strcmp(name, "gzputs") == 0 ||
        strcmp(name, "gzread") == 0 ||
        strcmp(name, "gzrewind") == 0 ||
        strcmp(name, "gzseek") == 0 ||
        strcmp(name, "gztell") == 0 ||
        strcmp(name, "gzwrite") == 0) {
        JinxOracleBatch2Gzip *stream;
        if (args == NULL || argc < 1u) return result;
        stream = b2_gzip(args[0]);
        if (stream == NULL || stream->gz == NULL) return result;

        if (strcmp(name, "gzeof") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(gzeof(stream->gz));
        }

        if (strcmp(name, "gzgetc") == 0) {
            int ch = gzgetc(stream->gz);
            if (handled != NULL) *handled = 1;
            if (ch == -1) return jinx_oracle_bool_value(0);
            {
                char *out = jinx_oracle_scratch_string(1u);
                out[0] = (char)ch;
                return jinx_oracle_string_value_len(out, 1u);
            }
        }

        if (strcmp(name, "gzgets") == 0) {
            int length = argc >= 2u ? (int)jinx_oracle_intish(args[1]) : 1024;
            char *buffer;
            char *got;
            if (length < 2) return result;
            buffer = (char *)malloc((size_t)length);
            if (buffer == NULL) return result;
            got = gzgets(stream->gz, buffer, length);
            if (handled != NULL) *handled = 1;
            if (got == NULL) {
                free(buffer);
                return jinx_oracle_bool_value(0);
            }
            result = b2_copy(buffer, strlen(buffer));
            free(buffer);
            return result;
        }

        if (strcmp(name, "gzread") == 0) {
            int length;
            char *buffer;
            int got;
            if (argc < 2u) return result;
            length = (int)jinx_oracle_intish(args[1]);
            if (length <= 0) return result;
            buffer = (char *)malloc((size_t)length);
            if (buffer == NULL) return result;
            got = gzread(stream->gz, buffer, (unsigned int)length);
            if (handled != NULL) *handled = 1;
            if (got < 0) {
                free(buffer);
                return jinx_oracle_bool_value(0);
            }
            result = b2_copy(buffer, (size_t)got);
            free(buffer);
            return result;
        }

        if (strcmp(name, "gzwrite") == 0 || strcmp(name, "gzputs") == 0) {
            const unsigned char *bytes;
            uint32_t len;
            uint32_t take;
            int written;
            if (argc < 2u || args[1].type != 3u) return result;
            bytes = jinx_oracle_string_bytes(args[1]);
            len = jinx_oracle_string_len(args[1]);
            take = len;
            if (argc >= 3u && args[2].type != 0u) {
                int64_t limit = jinx_oracle_intish(args[2]);
                if (limit < 0) return result;
                if ((uint64_t)limit < (uint64_t)take) take = (uint32_t)limit;
            }
            written = gzwrite(stream->gz, bytes, take);
            if (handled != NULL) *handled = 1;
            return written < 0
                ? jinx_oracle_bool_value(0)
                : jinx_oracle_int_value((int64_t)written);
        }

        if (strcmp(name, "gzrewind") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(gzrewind(stream->gz) == 0);
        }

        if (strcmp(name, "gzseek") == 0) {
            z_off_t pos;
            int whence = argc >= 3u ? (int)jinx_oracle_intish(args[2]) : SEEK_SET;
            if (argc < 2u) return result;
            pos = gzseek(stream->gz, (z_off_t)jinx_oracle_intish(args[1]), whence);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(pos < 0 ? -1 : 0);
        }

        if (strcmp(name, "gztell") == 0) {
            z_off_t pos = gztell(stream->gz);
            if (handled != NULL) *handled = 1;
            return pos < 0
                ? jinx_oracle_bool_value(0)
                : jinx_oracle_int_value((int64_t)pos);
        }

        if (strcmp(name, "gzpassthru") == 0) {
            unsigned char buffer[8192];
            int n;
            int64_t total = 0;
            while ((n = gzread(stream->gz, buffer, sizeof(buffer))) > 0) {
                if (fwrite(buffer, 1u, (size_t)n, stdout) != (size_t)n) {
                    if (handled != NULL) *handled = 1;
                    return jinx_oracle_bool_value(0);
                }
                total += n;
            }
            if (handled != NULL) *handled = 1;
            return n < 0
                ? jinx_oracle_bool_value(0)
                : jinx_oracle_int_value(total);
        }

        if (strcmp(name, "gzclose") == 0) {
            int rc = gzclose(stream->gz);
            jinx_oracle_resource_unregister(stream);
            stream->gz = NULL;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(rc == Z_OK);
        }
    }

    if (strcmp(name, "gzfile") == 0) {
        char *path;
        gzFile gz;
        JinxZendArray *array;
        char buffer[8192];
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        gz = gzopen(path, "rb");
        free(path);
        if (gz == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        array = jinx_zend_array_new_packed(16u);
        if (array == NULL) {
            gzclose(gz);
            return result;
        }
        while (gzgets(gz, buffer, sizeof(buffer)) != NULL) {
            if (!b2_append_string(array, buffer)) {
                jinx_zend_array_release(array);
                gzclose(gz);
                return result;
            }
        }
        gzclose(gz);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "deflate_init") == 0) {
        int encoding;
        if (args == NULL || argc < 1u) return result;
        if (argc >= 2u && args[1].type != 0u) return result;
        encoding = (int)jinx_oracle_intish(args[0]);
        result = b2_new_deflate(encoding);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "deflate_add") == 0) {
        JinxOracleBatch2Deflate *ctx;
        const unsigned char *input;
        uint32_t input_len;
        int flush_mode = Z_SYNC_FLUSH;
        size_t capacity;
        unsigned char *buffer;
        int rc;
        size_t produced;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        ctx = b2_deflate(args[0]);
        if (ctx == NULL || !ctx->initialized) return result;
        if (argc >= 3u) flush_mode = (int)jinx_oracle_intish(args[2]);
        input = jinx_oracle_string_bytes(args[1]);
        input_len = jinx_oracle_string_len(args[1]);
        capacity = (size_t)compressBound(input_len) + 64u;
        buffer = (unsigned char *)malloc(capacity);
        if (buffer == NULL) return result;
        ctx->stream.next_in = (Bytef *)input;
        ctx->stream.avail_in = input_len;
        ctx->stream.next_out = buffer;
        ctx->stream.avail_out = (uInt)capacity;
        rc = deflate(&ctx->stream, flush_mode);
        if (rc != Z_OK && rc != Z_STREAM_END) {
            free(buffer);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        produced = capacity - ctx->stream.avail_out;
        result = b2_copy((const char *)buffer, produced);
        free(buffer);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "hash_equals") == 0) {
        const unsigned char *known;
        const unsigned char *user;
        uint32_t known_len;
        uint32_t user_len;
        unsigned char diff = 0u;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        known = jinx_oracle_string_bytes(args[0]);
        user = jinx_oracle_string_bytes(args[1]);
        known_len = jinx_oracle_string_len(args[0]);
        user_len = jinx_oracle_string_len(args[1]);
        if (known_len != user_len) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        for (uint32_t i = 0u; i < known_len; i++) diff |= known[i] ^ user[i];
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(diff == 0u);
    }


    if (strcmp(name, "get_class_methods") == 0) {
        const JinxNativeClassMeta *meta = NULL;
        char *class_name = NULL;
        if (args == NULL || argc < 1u) return result;
        if (args[0].type == 3u) {
            class_name = b2_dup(args[0]);
            if (class_name == NULL) return result;
            meta = b2_class(class_name);
            free(class_name);
        } else if (args[0].type == JINX_ORACLE_VALUE_ZEND_OBJECT) {
            JinxZendObject *object = jinx_oracle_zend_object_ptr(args[0]);
            if (object != NULL && object->class_name != NULL) {
                meta = b2_class(object->class_name);
            }
        } else {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return meta != NULL
            ? b2_string_list(meta->methods, meta->method_count)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "filter_list") == 0) {
        JinxZendArray *array = jinx_zend_array_new_packed(
            jinx_native_filter_metadata_count == 0u ? 1u : jinx_native_filter_metadata_count
        );
        if (array == NULL) return result;
        for (size_t i = 0u; i < jinx_native_filter_metadata_count; i++) {
            if (!b2_append_string(array, jinx_native_filter_metadata[i].name)) {
                jinx_zend_array_release(array);
                return result;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "filter_id") == 0) {
        char *filter_name;
        int id;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        filter_name = b2_dup(args[0]);
        if (filter_name == NULL) return result;
        id = b2_filter_id(filter_name);
        free(filter_name);
        if (handled != NULL) *handled = 1;
        return id >= 0 ? jinx_oracle_int_value(id) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "filter_var") == 0) {
        int filter = 516;
        const JinxNativeFilterMeta *meta;
        if (args == NULL || argc < 1u) return result;
        if (argc >= 2u && args[1].type != 0u) filter = (int)jinx_oracle_intish(args[1]);
        if (argc >= 3u && args[2].type != 0u) return result;
        meta = b2_filter_by_id(filter);
        if (meta == NULL) return result;

        if (strcasecmp(meta->name, "unsafe_raw") == 0 ||
            strcasecmp(meta->name, "string") == 0) {
            result = jinx_oracle_strval_value(args[0]);
            if (result.type != 0u && handled != NULL) *handled = 1;
            return result;
        }

        if (strcasecmp(meta->name, "int") == 0) {
            if (args[0].type == 1u) {
                if (handled != NULL) *handled = 1;
                return args[0];
            }
            if (args[0].type == 3u) {
                char *text = b2_dup(args[0]);
                char *end = NULL;
                long long value;
                if (text == NULL) return result;
                errno = 0;
                value = strtoll(text, &end, 10);
                ok = errno == 0 && end != text && *end == '\0';
                free(text);
                if (handled != NULL) *handled = 1;
                return ok
                    ? jinx_oracle_int_value((int64_t)value)
                    : jinx_oracle_bool_value(0);
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (strcasecmp(meta->name, "boolean") == 0) {
            if (args[0].type == 2u) {
                if (handled != NULL) *handled = 1;
                return args[0];
            }
            if (args[0].type == 1u) {
                if (handled != NULL) *handled = 1;
                return args[0].as.i64 == 0
                    ? jinx_oracle_bool_value(0)
                    : (args[0].as.i64 == 1
                        ? jinx_oracle_bool_value(1)
                        : jinx_oracle_bool_value(0));
            }
            if (args[0].type == 3u) {
                char *text = b2_dup(args[0]);
                int truth = 0;
                int recognized = 1;
                if (text == NULL) return result;
                if (strcasecmp(text, "1") == 0 ||
                    strcasecmp(text, "true") == 0 ||
                    strcasecmp(text, "on") == 0 ||
                    strcasecmp(text, "yes") == 0) {
                    truth = 1;
                } else if (text[0] == '\0' ||
                    strcasecmp(text, "0") == 0 ||
                    strcasecmp(text, "false") == 0 ||
                    strcasecmp(text, "off") == 0 ||
                    strcasecmp(text, "no") == 0) {
                    truth = 0;
                } else {
                    recognized = 0;
                }
                free(text);
                if (handled != NULL) *handled = 1;
                return recognized
                    ? jinx_oracle_bool_value(truth)
                    : jinx_oracle_bool_value(0);
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (strcasecmp(meta->name, "float") == 0) {
            if (args[0].type == 1u || args[0].type == 5u) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_float_value(jinx_oracle_floatish(args[0]));
            }
            if (args[0].type == 3u) {
                char *text = b2_dup(args[0]);
                char *end = NULL;
                double value;
                if (text == NULL) return result;
                errno = 0;
                value = strtod(text, &end);
                ok = errno == 0 && end != text && *end == '\0';
                free(text);
                if (handled != NULL) *handled = 1;
                return ok ? jinx_oracle_float_value(value) : jinx_oracle_bool_value(0);
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        return result;
    }

    if (strcmp(name, "get_include_path") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value(JINX_NATIVE_PHP_INCLUDE_PATH);
    }

    if (strcmp(name, "get_resource_id") == 0 ||
        strcmp(name, "get_resource_type") == 0) {
        JinxZendObject *object;
        void *ptr;
        int64_t id;
        const char *type_name;
        if (args == NULL || argc < 1u ||
            args[0].type != JINX_ORACLE_VALUE_ZEND_OBJECT) return result;
        object = jinx_oracle_zend_object_ptr(args[0]);
        if (object == NULL || object->class_name == NULL) return result;

        if (strcmp(object->class_name, "closed-resource") == 0) {
            if (strcmp(name, "get_resource_type") == 0) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_string_value("Unknown");
            }
            return result;
        }

        ptr = b2_registered_resource_pointer(args[0]);
        if (ptr == NULL) return result;
        id = jinx_oracle_resource_id(ptr);
        type_name = jinx_oracle_resource_type(ptr);
        if (id <= 0 || type_name == NULL) return result;

        if (handled != NULL) *handled = 1;
        return strcmp(name, "get_resource_id") == 0
            ? jinx_oracle_int_value(id)
            : jinx_oracle_string_value(type_name);
    }

    if (strcmp(name, "get_resources") == 0) {
        char *type_name = NULL;
        if (argc >= 1u && args != NULL && args[0].type != 0u) {
            if (args[0].type != 3u) return result;
            type_name = b2_dup(args[0]);
            if (type_name == NULL) return result;
        }
        result = jinx_oracle_resource_list_value(type_name);
        free(type_name);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "exec") == 0) {
        char *command;
        FILE *pipe;
        char line[4096];
        char last[4096] = "";
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        command = b2_dup(args[0]);
        if (command == NULL) return result;
        pipe = popen(command, "r");
        free(command);
        if (pipe == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        while (fgets(line, sizeof(line), pipe) != NULL) {
            size_t n = strlen(line);
            while (n != 0u && (line[n - 1u] == '\n' || line[n - 1u] == '\r')) {
                line[--n] = '\0';
            }
            snprintf(last, sizeof(last), "%s", line);
        }
        (void)pclose(pipe);
        if (handled != NULL) *handled = 1;
        return b2_copy(last, strlen(last));
    }

    if (strcmp(name, "fputcsv") == 0) {
        JinxOracleBatch2Stream *stream;
        JinxZendArray *array;
        char delimiter = ',';
        char enclosure = '"';
        char escape = '\\';
        const char *eol = "\n";
        size_t live;
        size_t total = 0u;
        if (args == NULL || argc < 2u ||
            !jinx_oracle_value_is_zend_array(args[1])) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        array = jinx_oracle_zend_array_ptr(args[1]);
        if (argc >= 3u && args[2].type == 3u && args[2].flags == 1u) delimiter = ((char *)args[2].as.ptr)[0];
        if (argc >= 4u && args[3].type == 3u && args[3].flags == 1u) enclosure = ((char *)args[3].as.ptr)[0];
        if (argc >= 5u && args[4].type == 3u && args[4].flags <= 1u) escape = args[4].flags == 0u ? '\0' : ((char *)args[4].as.ptr)[0];
        if (argc >= 6u && args[5].type == 3u) {
            char *tmp = b2_dup(args[5]);
            if (tmp == NULL) return result;
            eol = tmp;
        }

        live = jinx_zend_array_live_count(array);
        for (size_t i = 0u; i < live; i++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
            JinxValue jv;
            JinxValue sv;
            const unsigned char *bytes;
            uint32_t len;
            int quote = 0;
            if (i != 0u) {
                if (fputc(delimiter, stream->fp) == EOF) goto csv_fail;
                total++;
            }
            if (bucket == NULL ||
                !jinx_oracle_zend_to_jinx_borrowed(bucket->value, &jv)) goto csv_fail;
            if (jv.type == 0u) {
                bytes = (const unsigned char *)"";
                len = 0u;
            } else {
                sv = jinx_oracle_strval_value(jv);
                if (sv.type != 3u) goto csv_fail;
                bytes = jinx_oracle_string_bytes(sv);
                len = jinx_oracle_string_len(sv);
            }
            for (uint32_t p = 0u; p < len; p++) {
                if (bytes[p] == (unsigned char)delimiter ||
                    bytes[p] == (unsigned char)enclosure ||
                    bytes[p] == '\n' || bytes[p] == '\r' ||
                    bytes[p] == ' ' || bytes[p] == '\t') {
                    quote = 1;
                    break;
                }
            }
            if (quote) {
                if (fputc(enclosure, stream->fp) == EOF) goto csv_fail;
                total++;
            }
            for (uint32_t p = 0u; p < len; p++) {
                if (bytes[p] == (unsigned char)enclosure) {
                    if (fputc(enclosure, stream->fp) == EOF) goto csv_fail;
                    total++;
                } else if (escape != '\0' && bytes[p] == (unsigned char)escape) {
                    if (fputc(escape, stream->fp) == EOF) goto csv_fail;
                    total++;
                }
                if (fputc(bytes[p], stream->fp) == EOF) goto csv_fail;
                total++;
            }
            if (quote) {
                if (fputc(enclosure, stream->fp) == EOF) goto csv_fail;
                total++;
            }
        }
        if (fwrite(eol, 1u, strlen(eol), stream->fp) != strlen(eol)) goto csv_fail;
        total += strlen(eol);
        if (argc >= 6u && args[5].type == 3u) free((void *)eol);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)total);

csv_fail:
        if (argc >= 6u && args[5].type == 3u) free((void *)eol);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(0);
    }

    {
        int hash_handled = 0;
        JinxValue hash_result = jinx_oracle_hash_builtin(
            name, args, argc, &hash_handled
        );
        if (hash_handled) {
            if (handled != NULL) *handled = 1;
            return hash_result;
        }
    }

    {
        int finfo_handled = 0;
        JinxValue finfo_result = jinx_oracle_finfo_builtin(
            name, args, argc, &finfo_handled
        );
        if (finfo_handled) {
            if (handled != NULL) *handled = 1;
            return finfo_result;
        }
    }


    if (strcmp(name, "getimagesizefromstring") == 0) {
        JinxOracleImageInfo info;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (handled != NULL) *handled = 1;
        if (!b2_image_info(
            jinx_oracle_string_bytes(args[0]),
            jinx_oracle_string_len(args[0]),
            &info
        )) {
            return jinx_oracle_bool_value(0);
        }
        return b2_image_info_value(&info);
    }

    if (strcmp(name, "getimagesize") == 0 ||
        strcmp(name, "exif_imagetype") == 0) {
        char *path;
        unsigned char *bytes;
        size_t len = 0u;
        JinxOracleImageInfo info;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        bytes = b2_read_file_bytes(path, &len);
        free(path);
        if (handled != NULL) *handled = 1;
        if (bytes == NULL || !b2_image_info(bytes, len, &info)) {
            free(bytes);
            return jinx_oracle_bool_value(0);
        }
        free(bytes);
        if (strcmp(name, "exif_imagetype") == 0) {
            return jinx_oracle_int_value(info.type);
        }
        return b2_image_info_value(&info);
    }

    if (strcmp(name, "exif_tagname") == 0) {
        int64_t tag;
        const char *name_out = NULL;
        if (args == NULL || argc < 1u) return result;
        tag = jinx_oracle_intish(args[0]);
        switch (tag) {
            case 0x010e: name_out = "ImageDescription"; break;
            case 0x010f: name_out = "Make"; break;
            case 0x0110: name_out = "Model"; break;
            case 0x0112: name_out = "Orientation"; break;
            case 0x011a: name_out = "XResolution"; break;
            case 0x011b: name_out = "YResolution"; break;
            case 0x0128: name_out = "ResolutionUnit"; break;
            case 0x0131: name_out = "Software"; break;
            case 0x0132: name_out = "DateTime"; break;
            case 0x013b: name_out = "Artist"; break;
            case 0x8298: name_out = "Copyright"; break;
            case 0x829a: name_out = "ExposureTime"; break;
            case 0x829d: name_out = "FNumber"; break;
            case 0x8827: name_out = "ISOSpeedRatings"; break;
            case 0x9003: name_out = "DateTimeOriginal"; break;
            case 0x9004: name_out = "DateTimeDigitized"; break;
            case 0x9201: name_out = "ShutterSpeedValue"; break;
            case 0x9202: name_out = "ApertureValue"; break;
            case 0x9204: name_out = "ExposureBiasValue"; break;
            case 0x9209: name_out = "Flash"; break;
            case 0x920a: name_out = "FocalLength"; break;
            case 0xa002: name_out = "ExifImageWidth"; break;
            case 0xa003: name_out = "ExifImageLength"; break;
            default: break;
        }
        if (handled != NULL) *handled = 1;
        return name_out != NULL
            ? jinx_oracle_string_value(name_out)
            : jinx_oracle_bool_value(0);
    }


    if (strcmp(name, "get_cfg_var") == 0) {
        char *key;
        const char *value;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        key = b2_dup(args[0]);
        if (key == NULL) return result;
        value = b2_cfg_value(key);
        free(key);
        if (handled != NULL) *handled = 1;
        return value != NULL
            ? jinx_oracle_string_value(value)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "get_class_vars") == 0) {
        char *class_name;
        const JinxNativeClassVarsMeta *meta;
        JinxZendArray *array;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        class_name = b2_dup(args[0]);
        if (class_name == NULL) return result;
        meta = b2_class_vars_meta(class_name);
        free(class_name);
        if (meta == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (!meta->complete) return result;
        array = jinx_zend_array_new_packed(meta->var_count == 0u ? 1u : meta->var_count);
        if (array == NULL) return result;
        for (size_t i = 0u; i < meta->var_count; i++) {
            JinxValue jv = b2_constant_value(&meta->vars[i]);
            JinxZendValue zv;
            JinxZendString *owned = NULL;
            if (!jinx_oracle_jinx_value_to_zend(jv, &zv, &owned) ||
                !jinx_zend_array_add_assoc(
                    array,
                    meta->vars[i].name,
                    strlen(meta->vars[i].name),
                    zv
                )) {
                jinx_zend_string_release(owned);
                jinx_zend_array_release(array);
                return result;
            }
            jinx_zend_string_release(owned);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "get_html_translation_table") == 0) {
        JinxZendArray *array;
        if (argc != 0u) return result;
        array = jinx_zend_array_new_packed(
            jinx_native_html_translation_default_count == 0u
                ? 1u
                : jinx_native_html_translation_default_count
        );
        if (array == NULL) return result;
        for (size_t i = 0u; i < jinx_native_html_translation_default_count; i++) {
            JinxZendString *value = jinx_zend_string_new(
                jinx_native_html_translation_default[i].value,
                strlen(jinx_native_html_translation_default[i].value)
            );
            if (value == NULL ||
                !jinx_zend_array_add_assoc(
                    array,
                    jinx_native_html_translation_default[i].name,
                    strlen(jinx_native_html_translation_default[i].name),
                    jinx_zend_string_value(value)
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

    if (strcmp(name, "filter_var_array") == 0) {
        JinxZendArray *input;
        int filter = 516;
        size_t live;
        JinxZendArray *out;
        if (args == NULL || argc < 1u ||
            !jinx_oracle_value_is_zend_array(args[0])) return result;
        if (argc >= 2u && args[1].type != 0u) {
            if (args[1].type != 1u) return result;
            filter = (int)args[1].as.i64;
        }
        if (argc >= 3u && args[2].type != 0u) return result;
        input = jinx_oracle_zend_array_ptr(args[0]);
        live = jinx_zend_array_live_count(input);
        out = jinx_zend_array_new_packed(live == 0u ? 1u : live);
        if (out == NULL) return result;

        for (size_t i = 0u; i < live; i++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(input, i);
            JinxValue in_value;
            JinxValue filter_args[2];
            JinxValue filtered;
            int inner_handled = 0;
            JinxZendValue zv;
            JinxZendString *owned = NULL;
            if (bucket == NULL ||
                !jinx_oracle_zend_to_jinx_borrowed(bucket->value, &in_value)) {
                jinx_zend_array_release(out);
                return result;
            }
            filter_args[0] = in_value;
            filter_args[1] = jinx_oracle_int_value(filter);
            filtered = jinx_oracle_batch2_builtin(
                "filter_var", filter_args, 2u, &inner_handled
            );
            if (!inner_handled ||
                !jinx_oracle_jinx_value_to_zend(filtered, &zv, &owned)) {
                jinx_zend_string_release(owned);
                jinx_zend_array_release(out);
                return result;
            }
            if (bucket->key != NULL) {
                ok = jinx_zend_array_add_assoc(
                    out,
                    bucket->key->bytes,
                    bucket->key->len,
                    zv
                );
            } else {
                ok = jinx_zend_array_add_index(out, (size_t)bucket->h, zv);
            }
            jinx_zend_string_release(owned);
            if (!ok) {
                jinx_zend_array_release(out);
                return result;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(out);
    }

    if (strcmp(name, "fscanf") == 0) {
        JinxOracleBatch2Stream *stream;
        char *format;
        char line[8192];
        JinxZendArray *out;
        const char *p;
        const char *s;
        if (args == NULL || argc != 2u || args[1].type != 3u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        format = b2_dup(args[1]);
        if (format == NULL) return result;
        if (fgets(line, sizeof(line), stream->fp) == NULL) {
            free(format);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        out = jinx_zend_array_new_packed(8u);
        if (out == NULL) { free(format); return result; }
        p = format;
        s = line;
        while (*p != '\0') {
            if (*p != '%') {
                if (*s != *p) { jinx_zend_array_release(out); free(format); return result; }
                p++; s++; continue;
            }
            p++;
            if (*p == '%') {
                if (*s != '%') { jinx_zend_array_release(out); free(format); return result; }
                p++; s++; continue;
            }
            if (*p == 'c') {
                char tmp[2] = { *s, '\0' };
                JinxZendString *zs = jinx_zend_string_new(tmp, 1u);
                if (*s == '\0' || zs == NULL ||
                    !jinx_zend_array_append(out, jinx_zend_string_value(zs))) {
                    jinx_zend_string_release(zs);
                    jinx_zend_array_release(out); free(format); return result;
                }
                jinx_zend_string_release(zs);
                s++; p++; continue;
            }
            if (*p == 'd' || *p == 'u') {
                char *end = NULL;
                long long value = strtoll(s, &end, 10);
                if (end == s ||
                    !jinx_zend_array_append(out, jinx_zend_long((int64_t)value))) {
                    jinx_zend_array_release(out); free(format); return result;
                }
                s = end; p++; continue;
            }
            if (*p == 'f' || *p == 'g' || *p == 'e') {
                char *end = NULL;
                double value = strtod(s, &end);
                if (end == s ||
                    !jinx_zend_array_append(out, jinx_zend_double(value))) {
                    jinx_zend_array_release(out); free(format); return result;
                }
                s = end; p++; continue;
            }
            if (*p == 's') {
                const char *start = s;
                JinxZendString *zs;
                while (*s != '\0' && *s != ' ' && *s != '\t' &&
                       *s != '\r' && *s != '\n') s++;
                zs = jinx_zend_string_new(start, (size_t)(s - start));
                if (zs == NULL ||
                    !jinx_zend_array_append(out, jinx_zend_string_value(zs))) {
                    jinx_zend_string_release(zs);
                    jinx_zend_array_release(out); free(format); return result;
                }
                jinx_zend_string_release(zs);
                p++; continue;
            }
            jinx_zend_array_release(out);
            free(format);
            return result;
        }
        free(format);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(out);
    }

#ifdef JINX_HAVE_CRYPT
    if (strcmp(name, "crypt") == 0) {
        char *password;
        char *salt;
        char *out;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        password = b2_dup(args[0]);
        salt = b2_dup(args[1]);
        if (password == NULL || salt == NULL) {
            free(password); free(salt); return result;
        }
        out = crypt(password, salt);
        free(password); free(salt);
        if (handled != NULL) *handled = 1;
        return out != NULL
            ? b2_copy(out, strlen(out))
            : jinx_oracle_bool_value(0);
    }
#endif


    if (strcmp(name, "opendir") == 0) {
        char *path;
        DIR *dir;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc >= 2u && args[1].type != 0u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        dir = opendir(path);
        free(path);
        if (handled != NULL) *handled = 1;
        return dir != NULL ? b2_new_dir(dir) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "closedir") == 0 ||
        strcmp(name, "readdir") == 0 ||
        strcmp(name, "rewinddir") == 0) {
        JinxOracleBatch2Dir *resource;
        if (args == NULL || argc < 1u) return result;
        resource = b2_dir(args[0]);
        if (resource == NULL || resource->dir == NULL) return result;

        if (strcmp(name, "closedir") == 0) {
            (void)closedir(resource->dir);
            jinx_oracle_resource_unregister(resource);
            resource->dir = NULL;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zero_value();
        }

        if (strcmp(name, "rewinddir") == 0) {
            rewinddir(resource->dir);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zero_value();
        }

        {
            struct dirent *entry = readdir(resource->dir);
            if (handled != NULL) *handled = 1;
            return entry != NULL
                ? b2_copy(entry->d_name, strlen(entry->d_name))
                : jinx_oracle_bool_value(0);
        }
    }


#ifdef JINX_HAVE_RESOLV
    if (strcmp(name, "checkdnsrr") == 0 ||
        strcmp(name, "dns_check_record") == 0) {
        char *host;
        char *type_name = NULL;
        int type = ns_t_mx;
        unsigned char answer[65536];
        int rc;

        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        host = b2_dup(args[0]);
        if (host == NULL) return result;

        if (argc >= 2u && args[1].type != 0u) {
            if (args[1].type != 3u) {
                free(host);
                return result;
            }
            type_name = b2_dup(args[1]);
            if (type_name == NULL) {
                free(host);
                return result;
            }

            if (strcasecmp(type_name, "A") == 0) type = ns_t_a;
            else if (strcasecmp(type_name, "AAAA") == 0) type = ns_t_aaaa;
            else if (strcasecmp(type_name, "MX") == 0) type = ns_t_mx;
            else if (strcasecmp(type_name, "NS") == 0) type = ns_t_ns;
            else if (strcasecmp(type_name, "SOA") == 0) type = ns_t_soa;
            else if (strcasecmp(type_name, "PTR") == 0) type = ns_t_ptr;
            else if (strcasecmp(type_name, "CNAME") == 0) type = ns_t_cname;
            else if (strcasecmp(type_name, "TXT") == 0) type = ns_t_txt;
            else if (strcasecmp(type_name, "SRV") == 0) type = ns_t_srv;
#ifdef ns_t_caa
            else if (strcasecmp(type_name, "CAA") == 0) type = ns_t_caa;
#endif
            else if (strcasecmp(type_name, "ANY") == 0) type = ns_t_any;
            else {
                free(type_name);
                free(host);
                return result;
            }
        }

        rc = res_query(host, ns_c_in, type, answer, sizeof(answer));
        free(type_name);
        free(host);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc >= 0);
    }
#endif


    if (strcmp(name, "assert") == 0) {
        int truth;
        if (args == NULL || argc < 1u) return result;
        truth = jinx_oracle_boolish(args[0]);
        if (!jinx_oracle_batch2_assert_active || truth) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(1);
        }

        /*
         * A failed active assertion can warn, invoke a callback, throw
         * AssertionError, or bail. The direct builtin bridge does not yet own
         * the native call-frame/throwable path, so fail closed instead of
         * returning a fabricated false/null success.
         */
        return result;
    }

    if (strcmp(name, "assert_options") == 0) {
        int option;
        int *numeric_slot = NULL;
        char **callback_slot = NULL;

        if (args == NULL || argc < 1u) return result;
        option = (int)jinx_oracle_intish(args[0]);
        if (!b2_assert_option_slot(option, &numeric_slot, &callback_slot)) {
            return result;
        }

        if (numeric_slot != NULL) {
            int previous = *numeric_slot;
            if (argc >= 2u && args[1].type != 0u) {
                *numeric_slot = jinx_oracle_boolish(args[1]) ? 1 : 0;
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(previous);
        }

        if (callback_slot != NULL) {
            JinxValue previous = *callback_slot != NULL
                ? jinx_oracle_string_value(*callback_slot)
                : jinx_oracle_zero_value();

            if (argc >= 2u) {
                char *replacement = NULL;
                if (args[1].type == 3u) {
                    replacement = b2_dup(args[1]);
                    if (replacement == NULL) return result;
                    if (replacement[0] == '\0') {
                        free(replacement);
                        replacement = NULL;
                    }
                } else if (args[1].type != 0u) {
                    return result;
                }
                free(*callback_slot);
                *callback_slot = replacement;
            }

            if (handled != NULL) *handled = 1;
            return previous;
        }

        return result;
    }

    if (strcmp(name, "cli_get_process_title") == 0) {
        const char *title = b2_process_title_current();
        if (handled != NULL) *handled = 1;
        return title != NULL
            ? b2_copy(title, strlen(title))
            : jinx_oracle_zero_value();
    }

    if (strcmp(name, "cli_set_process_title") == 0) {
        char *title;
        int os_ok = 0;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        title = b2_dup(args[0]);
        if (title == NULL) return result;
#ifdef __linux__
        os_ok = prctl(PR_SET_NAME, (unsigned long)title, 0ul, 0ul, 0ul) == 0;
#else
        os_ok = 0;
#endif
        if (!os_ok) {
            free(title);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        free(jinx_oracle_batch2_process_title);
        jinx_oracle_batch2_process_title = title;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
    }

    {
        int solar_handled = 0;
        JinxValue solar_result = jinx_oracle_solar_builtin(
            name, args, argc, &solar_handled
        );
        if (solar_handled) {
            if (handled != NULL) *handled = 1;
            return solar_result;
        }
    }

    return result;
}
