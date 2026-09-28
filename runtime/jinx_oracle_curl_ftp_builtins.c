#include "jinx_oracle_curl_ftp_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <errno.h>
#include <netdb.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <unistd.h>

#ifdef JINX_HAVE_LIBCURL
#include <curl/curl.h>

typedef struct JinxOracleFtpConnection {
    char *host;
    long port;
    long timeout;
    int tls;
    int passive;
    int use_pasv_address;
    int autoseek;
    int closed;
    char *user;
    char *pass;
    char *cwd;
} JinxOracleFtpConnection;

typedef struct JinxOracleCurlBuffer {
    unsigned char *data;
    size_t len;
    size_t cap;
} JinxOracleCurlBuffer;

typedef struct JinxOracleFtpStream {
    FILE *fp;
} JinxOracleFtpStream;

static char *ftp_strdup(const char *text) {
    size_t len;
    char *copy;
    if (text == NULL) return NULL;
    len = strlen(text);
    copy = (char *)malloc(len + 1u);
    if (copy == NULL) return NULL;
    memcpy(copy, text, len + 1u);
    return copy;
}

static char *ftp_dup_value(JinxValue value) {
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

static int64_t ftp_constant(const char *name, int64_t fallback) {
    for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
        const JinxNativeConstantMeta *meta = &jinx_native_constant_metadata[i];
        if (meta->type == 1u && strcmp(meta->name, name) == 0) {
            return meta->i64;
        }
    }
    return fallback;
}

static int ftp_object_set_resource(
    JinxZendObject *object,
    const char *key,
    void *ptr
) {
    JinxZendValue value = jinx_zend_null();
    if (object == NULL || object->properties == NULL || key == NULL) return 0;
    value.type = JINX_ZEND_RESOURCE;
    value.value.ptr = ptr;
    return jinx_zend_array_add_assoc(
        object->properties, key, strlen(key), value
    );
}

static JinxOracleFtpConnection *ftp_connection(JinxValue value) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *slot;
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "FTP\\Connection") != 0 ||
        object->properties == NULL) {
        return NULL;
    }
    slot = jinx_zend_array_find(object->properties, "__ftp", 5u);
    if (slot == NULL || slot->type != JINX_ZEND_RESOURCE ||
        slot->value.ptr == NULL) {
        return NULL;
    }
    return (JinxOracleFtpConnection *)slot->value.ptr;
}

static FILE *ftp_stream_file(JinxValue value) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *slot;
    JinxOracleFtpStream *stream;
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "stream") != 0 ||
        object->properties == NULL) {
        return NULL;
    }
    slot = jinx_zend_array_find(object->properties, "__stream", 8u);
    if (slot == NULL || slot->type != JINX_ZEND_RESOURCE ||
        slot->value.ptr == NULL) {
        return NULL;
    }
    stream = (JinxOracleFtpStream *)slot->value.ptr;
    return stream->fp;
}

static int ftp_tcp_probe(const char *host, long port) {
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *it;
    char service[16];
    int ok = 0;

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    snprintf(service, sizeof(service), "%ld", port);

    if (getaddrinfo(host, service, &hints, &results) != 0) return 0;

    for (it = results; it != NULL; it = it->ai_next) {
        int fd = socket(it->ai_family, it->ai_socktype, it->ai_protocol);
        if (fd < 0) continue;
        if (connect(fd, it->ai_addr, it->ai_addrlen) == 0) {
            ok = 1;
            close(fd);
            break;
        }
        close(fd);
    }

    freeaddrinfo(results);
    return ok;
}

static JinxValue ftp_new_connection(
    const char *host,
    long port,
    long timeout,
    int tls
) {
    JinxOracleFtpConnection *connection;
    JinxZendObject *object;

    connection = (JinxOracleFtpConnection *)calloc(1u, sizeof(*connection));
    if (connection == NULL) return jinx_oracle_zero_value();

    connection->host = ftp_strdup(host);
    connection->cwd = ftp_strdup("/");
    connection->port = port;
    connection->timeout = timeout;
    connection->tls = tls;
    connection->passive = 0;
    connection->use_pasv_address = 1;
    connection->autoseek = 1;

    if (connection->host == NULL || connection->cwd == NULL) {
        free(connection->host);
        free(connection->cwd);
        free(connection);
        return jinx_oracle_zero_value();
    }

    object = jinx_zend_object_new("FTP\\Connection");
    if (object == NULL ||
        !ftp_object_set_resource(object, "__ftp", connection)) {
        free(connection->host);
        free(connection->cwd);
        free(connection);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_object_value_owned(object);
}

static void ftp_connection_free(JinxOracleFtpConnection *connection) {
    if (connection == NULL) return;
    free(connection->host);
    free(connection->user);
    free(connection->pass);
    free(connection->cwd);
    connection->host = NULL;
    connection->user = NULL;
    connection->pass = NULL;
    connection->cwd = NULL;
    connection->closed = 1;
}

static size_t ftp_buffer_write(
    char *ptr,
    size_t size,
    size_t nmemb,
    void *userdata
) {
    JinxOracleCurlBuffer *buffer = (JinxOracleCurlBuffer *)userdata;
    size_t bytes = size * nmemb;
    size_t needed;
    unsigned char *next;

    if (buffer == NULL || bytes == 0u) return bytes;
    needed = buffer->len + bytes + 1u;
    if (needed > buffer->cap) {
        size_t cap = buffer->cap == 0u ? 1024u : buffer->cap;
        while (cap < needed) cap *= 2u;
        next = (unsigned char *)realloc(buffer->data, cap);
        if (next == NULL) return 0u;
        buffer->data = next;
        buffer->cap = cap;
    }
    memcpy(buffer->data + buffer->len, ptr, bytes);
    buffer->len += bytes;
    buffer->data[buffer->len] = '\0';
    return bytes;
}

static size_t ftp_file_write(
    char *ptr,
    size_t size,
    size_t nmemb,
    void *userdata
) {
    FILE *fp = (FILE *)userdata;
    return fp != NULL ? fwrite(ptr, size, nmemb, fp) : 0u;
}

static size_t ftp_file_read(
    char *ptr,
    size_t size,
    size_t nmemb,
    void *userdata
) {
    FILE *fp = (FILE *)userdata;
    return fp != NULL ? fread(ptr, size, nmemb, fp) : 0u;
}

static char *ftp_join_path(
    const JinxOracleFtpConnection *connection,
    const char *path
) {
    const char *cwd;
    size_t cwd_len;
    size_t path_len;
    char *out;

    if (connection == NULL || path == NULL) return NULL;
    if (path[0] == '/') return ftp_strdup(path);

    cwd = connection->cwd != NULL ? connection->cwd : "/";
    cwd_len = strlen(cwd);
    path_len = strlen(path);
    out = (char *)malloc(cwd_len + path_len + 2u);
    if (out == NULL) return NULL;

    memcpy(out, cwd, cwd_len);
    if (cwd_len == 0u || out[cwd_len - 1u] != '/') out[cwd_len++] = '/';
    memcpy(out + cwd_len, path, path_len);
    out[cwd_len + path_len] = '\0';
    return out;
}

static char *ftp_url(
    const JinxOracleFtpConnection *connection,
    const char *path,
    int directory
) {
    char *joined;
    size_t len;
    size_t cap;
    char *url;
    const char *scheme;

    if (connection == NULL || connection->host == NULL) return NULL;
    joined = ftp_join_path(connection, path != NULL ? path : "");
    if (joined == NULL) return NULL;

    len = strlen(joined);
    cap = strlen(connection->host) + len + 64u;
    url = (char *)malloc(cap);
    if (url == NULL) {
        free(joined);
        return NULL;
    }

    scheme = connection->tls ? "ftps" : "ftp";
    snprintf(
        url,
        cap,
        "%s://%s:%ld%s%s",
        scheme,
        connection->host,
        connection->port,
        joined[0] == '/' ? "" : "/",
        joined
    );

    if (directory) {
        size_t url_len = strlen(url);
        if (url_len + 2u < cap && (url_len == 0u || url[url_len - 1u] != '/')) {
            url[url_len] = '/';
            url[url_len + 1u] = '\0';
        }
    }

    free(joined);
    return url;
}

static CURL *ftp_easy(
    JinxOracleFtpConnection *connection,
    const char *url
) {
    CURL *curl;
    if (connection == NULL || connection->closed || url == NULL) return NULL;

    curl = curl_easy_init();
    if (curl == NULL) return NULL;

    curl_easy_setopt(curl, CURLOPT_URL, url);
    curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, connection->timeout);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, connection->timeout);
    curl_easy_setopt(curl, CURLOPT_FTP_FILEMETHOD, CURLFTPMETHOD_SINGLECWD);

    if (connection->user != NULL) {
        curl_easy_setopt(curl, CURLOPT_USERNAME, connection->user);
        curl_easy_setopt(
            curl,
            CURLOPT_PASSWORD,
            connection->pass != NULL ? connection->pass : ""
        );
    }

    if (connection->tls) {
        curl_easy_setopt(curl, CURLOPT_USE_SSL, (long)CURLUSESSL_ALL);
    }

    if (!connection->passive) {
        curl_easy_setopt(curl, CURLOPT_FTPPORT, "-");
    }
#ifdef CURLOPT_FTP_SKIP_PASV_IP
    curl_easy_setopt(
        curl,
        CURLOPT_FTP_SKIP_PASV_IP,
        connection->use_pasv_address ? 0L : 1L
    );
#endif
    return curl;
}

static int ftp_perform_simple(
    JinxOracleFtpConnection *connection,
    const char *path,
    int directory,
    struct curl_slist *quote,
    JinxOracleCurlBuffer *headers
) {
    char *url = ftp_url(connection, path, directory);
    CURL *curl;
    CURLcode code;

    if (url == NULL) return 0;
    curl = ftp_easy(connection, url);
    free(url);
    if (curl == NULL) return 0;

    curl_easy_setopt(curl, CURLOPT_NOBODY, 1L);
    if (quote != NULL) curl_easy_setopt(curl, CURLOPT_QUOTE, quote);
    if (headers != NULL) {
        curl_easy_setopt(curl, CURLOPT_HEADERFUNCTION, ftp_buffer_write);
        curl_easy_setopt(curl, CURLOPT_HEADERDATA, headers);
    }

    code = curl_easy_perform(curl);
    curl_easy_cleanup(curl);
    return code == CURLE_OK;
}

static int ftp_quote_command(
    JinxOracleFtpConnection *connection,
    const char *command,
    JinxOracleCurlBuffer *headers
) {
    struct curl_slist *quote = NULL;
    int ok;
    quote = curl_slist_append(quote, command);
    if (quote == NULL) return 0;
    ok = ftp_perform_simple(connection, "", 1, quote, headers);
    curl_slist_free_all(quote);
    return ok;
}

static JinxValue ftp_lines_value(
    const unsigned char *bytes,
    size_t len
) {
    JinxZendArray *array = jinx_zend_array_new_packed(8u);
    size_t start = 0u;
    if (array == NULL) return jinx_oracle_zero_value();

    while (start < len) {
        size_t end = start;
        JinxZendString *string;
        while (end < len && bytes[end] != '\n') end++;
        while (end > start &&
               (bytes[end - 1u] == '\r' || bytes[end - 1u] == '\n')) {
            end--;
        }
        if (end > start) {
            string = jinx_zend_string_new(
                (const char *)bytes + start,
                end - start
            );
            if (string == NULL ||
                !jinx_zend_array_append(
                    array,
                    jinx_zend_string_value(string)
                )) {
                jinx_zend_string_release(string);
                jinx_zend_array_release(array);
                return jinx_oracle_zero_value();
            }
            jinx_zend_string_release(string);
        }
        start = end;
        while (start < len &&
               (bytes[start] == '\r' || bytes[start] == '\n')) {
            start++;
        }
    }

    return jinx_oracle_zend_array_value_owned(array);
}

static int ftp_update_cwd(
    JinxOracleFtpConnection *connection,
    const char *path
) {
    char *next = ftp_join_path(connection, path);
    size_t len;
    if (next == NULL) return 0;

    len = strlen(next);
    while (len > 1u && next[len - 1u] == '/') next[--len] = '\0';

    free(connection->cwd);
    connection->cwd = next;
    return 1;
}

static int ftp_parent_cwd(JinxOracleFtpConnection *connection) {
    char *copy;
    char *slash;
    if (connection == NULL || connection->cwd == NULL) return 0;
    if (strcmp(connection->cwd, "/") == 0) return 1;

    copy = ftp_strdup(connection->cwd);
    if (copy == NULL) return 0;
    slash = strrchr(copy, '/');
    if (slash == NULL || slash == copy) {
        copy[0] = '/';
        copy[1] = '\0';
    } else {
        *slash = '\0';
    }

    free(connection->cwd);
    connection->cwd = copy;
    return 1;
}

static int ftp_status_finished(void) {
    return (int)ftp_constant("FTP_FINISHED", 1);
}

static int ftp_status_failed(void) {
    return (int)ftp_constant("FTP_FAILED", 0);
}

static int ftp_is_binary_mode(int64_t mode) {
    return mode == ftp_constant("FTP_BINARY", 2);
}

JinxValue jinx_oracle_curl_ftp_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "ftp_connect") == 0 ||
        strcmp(name, "ftp_ssl_connect") == 0) {
        char *host;
        long port = 21;
        long timeout = 90;
        int tls = strcmp(name, "ftp_ssl_connect") == 0;

        if (args == NULL || argc < 1u || argc > 3u ||
            args[0].type != 3u) {
            return result;
        }
        host = ftp_dup_value(args[0]);
        if (host == NULL) return result;
        if (argc >= 2u && args[1].type != 0u) {
            port = (long)jinx_oracle_intish(args[1]);
        }
        if (argc >= 3u && args[2].type != 0u) {
            timeout = (long)jinx_oracle_intish(args[2]);
        }
        if (port <= 0 || port > 65535 || timeout <= 0) {
            free(host);
            return result;
        }

        if (!ftp_tcp_probe(host, port)) {
            free(host);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        result = ftp_new_connection(host, port, timeout, tls);
        free(host);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "ftp_nb_continue") == 0) {
        if (args == NULL || argc < 1u ||
            ftp_connection(args[0]) == NULL) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(ftp_status_finished());
    }

    if (args == NULL || argc < 1u) return result;

    {
        JinxOracleFtpConnection *connection = ftp_connection(args[0]);
        if (connection == NULL || connection->closed) return result;

        if (strcmp(name, "ftp_login") == 0) {
            char *user;
            char *pass;
            char *url;
            CURL *curl;
            CURLcode code;

            if (argc < 3u || args[1].type != 3u || args[2].type != 3u) {
                return result;
            }
            user = ftp_dup_value(args[1]);
            pass = ftp_dup_value(args[2]);
            if (user == NULL || pass == NULL) {
                free(user);
                free(pass);
                return result;
            }

            free(connection->user);
            free(connection->pass);
            connection->user = user;
            connection->pass = pass;

            url = ftp_url(connection, "", 1);
            if (url == NULL) return result;
            curl = ftp_easy(connection, url);
            free(url);
            if (curl == NULL) return result;
            curl_easy_setopt(curl, CURLOPT_NOBODY, 1L);
            code = curl_easy_perform(curl);
            curl_easy_cleanup(curl);

            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(code == CURLE_OK);
        }

        if (strcmp(name, "ftp_close") == 0 ||
            strcmp(name, "ftp_quit") == 0) {
            ftp_connection_free(connection);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(1);
        }

        if (strcmp(name, "ftp_pasv") == 0) {
            if (argc < 2u) return result;
            connection->passive = jinx_oracle_boolish(args[1]) ? 1 : 0;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(1);
        }

        if (strcmp(name, "ftp_get_option") == 0) {
            int64_t option;
            if (argc < 2u) return result;
            option = jinx_oracle_intish(args[1]);
            if (option == ftp_constant("FTP_TIMEOUT_SEC", 0)) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_int_value(connection->timeout);
            }
            if (option == ftp_constant("FTP_AUTOSEEK", 1)) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(connection->autoseek);
            }
            if (option == ftp_constant("FTP_USEPASVADDRESS", 2)) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(connection->use_pasv_address);
            }
            return result;
        }

        if (strcmp(name, "ftp_set_option") == 0) {
            int64_t option;
            if (argc < 3u) return result;
            option = jinx_oracle_intish(args[1]);
            if (option == ftp_constant("FTP_TIMEOUT_SEC", 0)) {
                long timeout = (long)jinx_oracle_intish(args[2]);
                if (timeout <= 0) return result;
                connection->timeout = timeout;
            } else if (option == ftp_constant("FTP_AUTOSEEK", 1)) {
                connection->autoseek = jinx_oracle_boolish(args[2]);
            } else if (option == ftp_constant("FTP_USEPASVADDRESS", 2)) {
                connection->use_pasv_address = jinx_oracle_boolish(args[2]);
            } else {
                return result;
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(1);
        }

        if (strcmp(name, "ftp_pwd") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_string_value(
                connection->cwd != NULL ? connection->cwd : "/"
            );
        }

        if (strcmp(name, "ftp_chdir") == 0) {
            char *path;
            int ok;
            if (argc < 2u || args[1].type != 3u) return result;
            path = ftp_dup_value(args[1]);
            if (path == NULL) return result;
            ok = ftp_perform_simple(connection, path, 1, NULL, NULL);
            if (ok) ok = ftp_update_cwd(connection, path);
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }

        if (strcmp(name, "ftp_cdup") == 0) {
            char *candidate;
            char *slash;
            int ok;
            if (connection->cwd == NULL) return result;
            candidate = ftp_strdup(connection->cwd);
            if (candidate == NULL) return result;
            slash = strrchr(candidate, '/');
            if (slash == NULL || slash == candidate) {
                candidate[0] = '/';
                candidate[1] = '\0';
            } else {
                *slash = '\0';
            }
            ok = ftp_perform_simple(
                connection, candidate, 1, NULL, NULL
            );
            if (ok) ok = ftp_parent_cwd(connection);
            free(candidate);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }

        if (strcmp(name, "ftp_raw") == 0 ||
            strcmp(name, "ftp_site") == 0 ||
            strcmp(name, "ftp_exec") == 0 ||
            strcmp(name, "ftp_systype") == 0) {
            char *command = NULL;
            JinxOracleCurlBuffer headers = {0};
            int ok;

            if (strcmp(name, "ftp_systype") == 0) {
                command = ftp_strdup("SYST");
            } else {
                char *payload;
                const char *prefix =
                    strcmp(name, "ftp_site") == 0 ? "SITE " :
                    (strcmp(name, "ftp_exec") == 0 ? "SITE EXEC " : "");
                size_t cap;
                if (argc < 2u || args[1].type != 3u) return result;
                payload = ftp_dup_value(args[1]);
                if (payload == NULL) return result;
                cap = strlen(prefix) + strlen(payload) + 1u;
                command = (char *)malloc(cap);
                if (command != NULL) {
                    snprintf(command, cap, "%s%s", prefix, payload);
                }
                free(payload);
            }
            if (command == NULL) return result;

            ok = ftp_quote_command(connection, command, &headers);
            free(command);

            if (handled != NULL) *handled = 1;
            if (!ok) {
                free(headers.data);
                return strcmp(name, "ftp_raw") == 0
                    ? jinx_oracle_bool_value(0)
                    : jinx_oracle_bool_value(0);
            }

            if (strcmp(name, "ftp_raw") == 0) {
                result = ftp_lines_value(headers.data, headers.len);
                free(headers.data);
                return result;
            }

            if (strcmp(name, "ftp_systype") == 0) {
                const char *text = headers.data != NULL
                    ? (const char *)headers.data : "";
                const char *p = strstr(text, "215 ");
                if (p != NULL) {
                    const char *start = p + 4;
                    const char *end = start;
                    char *out;
                    while (*end != '\0' && *end != '\r' && *end != '\n') end++;
                    out = jinx_oracle_scratch_string((uint32_t)(end - start));
                    memcpy(out, start, (size_t)(end - start));
                    free(headers.data);
                    return jinx_oracle_string_value_len(
                        out, (uint32_t)(end - start)
                    );
                }
                free(headers.data);
                return jinx_oracle_bool_value(0);
            }

            free(headers.data);
            return jinx_oracle_bool_value(1);
        }

        if (strcmp(name, "ftp_alloc") == 0) {
            char command[96];
            int64_t size;
            int ok;
            if (argc < 2u) return result;
            size = jinx_oracle_intish(args[1]);
            if (size < 0) return result;
            snprintf(command, sizeof(command), "ALLO %lld", (long long)size);
            ok = ftp_quote_command(connection, command, NULL);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(ok);
        }
    }

    return result;
}

#else

JinxValue jinx_oracle_curl_ftp_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    (void)name;
    (void)args;
    (void)argc;
    if (handled != NULL) *handled = 0;
    return jinx_oracle_zero_value();
}

#endif
