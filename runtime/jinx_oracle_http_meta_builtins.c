#include "jinx_oracle_http_meta_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifdef JINX_HAVE_LIBCURL
#include <curl/curl.h>
#endif

typedef struct JinxOracleHttpBuffer {
    unsigned char *data;
    size_t len;
    size_t cap;
} JinxOracleHttpBuffer;

static char *http_dup_value(JinxValue value) {
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

#ifdef JINX_HAVE_LIBCURL
static size_t http_buffer_write(
    char *ptr,
    size_t size,
    size_t nmemb,
    void *userdata
) {
    JinxOracleHttpBuffer *buffer = (JinxOracleHttpBuffer *)userdata;
    size_t bytes = size * nmemb;
    size_t needed;
    unsigned char *next;

    if (buffer == NULL || bytes == 0u) return bytes;
    needed = buffer->len + bytes + 1u;
    if (needed > buffer->cap) {
        size_t cap = buffer->cap == 0u ? 4096u : buffer->cap;
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

static size_t http_sink(
    char *ptr,
    size_t size,
    size_t nmemb,
    void *userdata
) {
    (void)ptr;
    (void)userdata;
    return size * nmemb;
}
#endif

static int http_assoc_string(
    JinxZendArray *array,
    const char *key,
    const char *value,
    size_t value_len
) {
    JinxZendString *string;
    int ok;
    if (array == NULL || key == NULL || value == NULL) return 0;
    string = jinx_zend_string_new(value, value_len);
    if (string == NULL) return 0;
    ok = jinx_zend_array_add_assoc(
        array, key, strlen(key), jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

#ifdef JINX_HAVE_LIBCURL
static int http_append_string(
    JinxZendArray *array,
    const char *value,
    size_t value_len
) {
    JinxZendString *string;
    int ok;
    string = jinx_zend_string_new(value, value_len);
    if (string == NULL) return 0;
    ok = jinx_zend_array_append(array, jinx_zend_string_value(string));
    jinx_zend_string_release(string);
    return ok;
}
#endif

static int ascii_equal_ci(char a, char b) {
    unsigned char ua = (unsigned char)a;
    unsigned char ub = (unsigned char)b;
    if (ua >= 'A' && ua <= 'Z') ua = (unsigned char)(ua - 'A' + 'a');
    if (ub >= 'A' && ub <= 'Z') ub = (unsigned char)(ub - 'A' + 'a');
    return ua == ub;
}

static int ascii_starts_ci(
    const char *text,
    size_t text_len,
    const char *prefix
) {
    size_t n = strlen(prefix);
    if (text_len < n) return 0;
    for (size_t i = 0u; i < n; i++) {
        if (!ascii_equal_ci(text[i], prefix[i])) return 0;
    }
    return 1;
}

static const char *ascii_find_ci(
    const char *haystack,
    size_t haystack_len,
    const char *needle
) {
    size_t needle_len = strlen(needle);
    if (needle_len == 0u || haystack_len < needle_len) return NULL;
    for (size_t i = 0u; i + needle_len <= haystack_len; i++) {
        size_t j = 0u;
        for (; j < needle_len; j++) {
            if (!ascii_equal_ci(haystack[i + j], needle[j])) break;
        }
        if (j == needle_len) return haystack + i;
    }
    return NULL;
}

#ifdef JINX_HAVE_LIBCURL
static JinxValue http_headers_parse(
    const unsigned char *bytes,
    size_t len,
    int associative
) {
    JinxZendArray *out = jinx_zend_array_new_packed(16u);
    size_t pos = 0u;
    if (out == NULL) return jinx_oracle_zero_value();

    while (pos < len) {
        size_t end = pos;
        const char *line;
        size_t line_len;

        while (end < len && bytes[end] != '\n') end++;
        line = (const char *)bytes + pos;
        line_len = end - pos;
        while (line_len != 0u &&
               (line[line_len - 1u] == '\r' ||
                line[line_len - 1u] == '\n')) {
            line_len--;
        }

        if (line_len != 0u) {
            if (!associative ||
                ascii_starts_ci(line, line_len, "HTTP/")) {
                if (!http_append_string(out, line, line_len)) {
                    jinx_zend_array_release(out);
                    return jinx_oracle_zero_value();
                }
            } else {
                const char *colon = memchr(line, ':', line_len);
                if (colon == NULL) {
                    if (!http_append_string(out, line, line_len)) {
                        jinx_zend_array_release(out);
                        return jinx_oracle_zero_value();
                    }
                } else {
                    size_t key_len = (size_t)(colon - line);
                    const char *value = colon + 1;
                    size_t value_len =
                        line_len - (size_t)(value - line);
                    JinxZendValue *existing;

                    while (value_len != 0u &&
                           (*value == ' ' || *value == '\t')) {
                        value++;
                        value_len--;
                    }

                    existing = jinx_zend_array_find(
                        out, line, key_len
                    );
                    if (existing == NULL) {
                        JinxZendString *string =
                            jinx_zend_string_new(value, value_len);
                        if (string == NULL ||
                            !jinx_zend_array_add_assoc(
                                out,
                                line,
                                key_len,
                                jinx_zend_string_value(string)
                            )) {
                            jinx_zend_string_release(string);
                            jinx_zend_array_release(out);
                            return jinx_oracle_zero_value();
                        }
                        jinx_zend_string_release(string);
                    } else if (existing->type == JINX_ZEND_STRING) {
                        JinxZendArray *values =
                            jinx_zend_array_new_packed(2u);
                        JinxZendString *next;
                        if (values == NULL ||
                            !jinx_zend_array_append(
                                values, *existing
                            )) {
                            jinx_zend_array_release(values);
                            jinx_zend_array_release(out);
                            return jinx_oracle_zero_value();
                        }
                        next = jinx_zend_string_new(value, value_len);
                        if (next == NULL ||
                            !jinx_zend_array_append(
                                values,
                                jinx_zend_string_value(next)
                            )) {
                            jinx_zend_string_release(next);
                            jinx_zend_array_release(values);
                            jinx_zend_array_release(out);
                            return jinx_oracle_zero_value();
                        }
                        jinx_zend_string_release(next);
                        jinx_zend_value_release(*existing);
                        *existing = jinx_zend_array_value(values);
                    } else if (existing->type == JINX_ZEND_ARRAY &&
                               existing->value.array != NULL) {
                        JinxZendString *next =
                            jinx_zend_string_new(value, value_len);
                        if (next == NULL ||
                            !jinx_zend_array_append(
                                existing->value.array,
                                jinx_zend_string_value(next)
                            )) {
                            jinx_zend_string_release(next);
                            jinx_zend_array_release(out);
                            return jinx_oracle_zero_value();
                        }
                        jinx_zend_string_release(next);
                    }
                }
            }
        }

        pos = end;
        while (pos < len &&
               (bytes[pos] == '\r' || bytes[pos] == '\n')) {
            pos++;
        }
    }

    return jinx_oracle_zend_array_value_owned(out);
}
#endif

#ifdef JINX_HAVE_LIBCURL
static int http_fetch_url(
    const char *url,
    JinxOracleHttpBuffer *body,
    JinxOracleHttpBuffer *headers,
    int collect_body
) {
    CURL *curl = curl_easy_init();
    CURLcode code;
    if (curl == NULL) return 0;

    curl_easy_setopt(curl, CURLOPT_URL, url);
    curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
    curl_easy_setopt(curl, CURLOPT_MAXREDIRS, 20L);
    curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 10L);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 30L);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "Jinx/Oracle");

    if (headers != NULL) {
        curl_easy_setopt(curl, CURLOPT_HEADERFUNCTION, http_buffer_write);
        curl_easy_setopt(curl, CURLOPT_HEADERDATA, headers);
    }

    if (collect_body && body != NULL) {
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, http_buffer_write);
        curl_easy_setopt(curl, CURLOPT_WRITEDATA, body);
    } else {
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, http_sink);
    }

    code = curl_easy_perform(curl);
    curl_easy_cleanup(curl);
    return code == CURLE_OK;
}
#endif

static unsigned char *http_read_file(
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
    bytes[got] = '\0';
    *len_out = got;
    return bytes;
}

static unsigned char *http_read_include_path(
    const char *filename,
    size_t *len_out
) {
    unsigned char *bytes = http_read_file(filename, len_out);
    char *paths;
    char *save = NULL;
    char *token;

    if (bytes != NULL) return bytes;

    paths = strdup(JINX_NATIVE_PHP_INCLUDE_PATH);
    if (paths == NULL) return NULL;

    for (token = strtok_r(paths, ":", &save);
         token != NULL;
         token = strtok_r(NULL, ":", &save)) {
        size_t cap = strlen(token) + strlen(filename) + 2u;
        char *path = (char *)malloc(cap);
        if (path == NULL) continue;
        snprintf(path, cap, "%s/%s", token, filename);
        bytes = http_read_file(path, len_out);
        free(path);
        if (bytes != NULL) break;
    }

    free(paths);
    return bytes;
}

static int html_extract_attr(
    const char *tag,
    size_t tag_len,
    const char *wanted,
    const char **value_out,
    size_t *value_len_out
) {
    size_t pos = 0u;
    size_t wanted_len = strlen(wanted);

    while (pos < tag_len) {
        size_t key_start;
        size_t key_end;
        size_t value_start;
        size_t value_end;
        char quote = '\0';

        while (pos < tag_len &&
               isspace((unsigned char)tag[pos])) pos++;
        if (pos >= tag_len) break;

        key_start = pos;
        while (pos < tag_len &&
               (isalnum((unsigned char)tag[pos]) ||
                tag[pos] == '-' ||
                tag[pos] == '_' ||
                tag[pos] == ':')) {
            pos++;
        }
        key_end = pos;
        while (pos < tag_len &&
               isspace((unsigned char)tag[pos])) pos++;
        if (pos >= tag_len || tag[pos] != '=') {
            while (pos < tag_len &&
                   !isspace((unsigned char)tag[pos])) pos++;
            continue;
        }
        pos++;
        while (pos < tag_len &&
               isspace((unsigned char)tag[pos])) pos++;
        if (pos >= tag_len) break;

        if (tag[pos] == '"' || tag[pos] == '\'') {
            quote = tag[pos++];
        }
        value_start = pos;
        if (quote != '\0') {
            while (pos < tag_len && tag[pos] != quote) pos++;
            value_end = pos;
            if (pos < tag_len) pos++;
        } else {
            while (pos < tag_len &&
                   !isspace((unsigned char)tag[pos]) &&
                   tag[pos] != '>') {
                pos++;
            }
            value_end = pos;
        }

        if (key_end - key_start == wanted_len) {
            size_t i = 0u;
            for (; i < wanted_len; i++) {
                if (!ascii_equal_ci(
                    tag[key_start + i], wanted[i]
                )) {
                    break;
                }
            }
            if (i == wanted_len) {
                *value_out = tag + value_start;
                *value_len_out = value_end - value_start;
                return 1;
            }
        }
    }
    return 0;
}

static void html_normalize_meta_name(
    const char *name,
    size_t len,
    char *out
) {
    for (size_t i = 0u; i < len; i++) {
        unsigned char ch = (unsigned char)name[i];
        if (ch >= 'A' && ch <= 'Z') ch = (unsigned char)(ch - 'A' + 'a');
        if (!((ch >= 'a' && ch <= 'z') ||
              (ch >= '0' && ch <= '9') ||
              ch == '_')) {
            ch = '_';
        }
        out[i] = (char)ch;
    }
    out[len] = '\0';
}

static JinxValue html_meta_tags(
    const unsigned char *bytes,
    size_t len
) {
    JinxZendArray *out = jinx_zend_array_new_packed(8u);
    const char *text = (const char *)bytes;
    const char *head_end;
    size_t parse_len;
    size_t pos = 0u;

    if (out == NULL) return jinx_oracle_zero_value();
    head_end = ascii_find_ci(text, len, "</head");
    parse_len = head_end != NULL
        ? (size_t)(head_end - text)
        : len;

    while (pos < parse_len) {
        const char *meta = ascii_find_ci(
            text + pos, parse_len - pos, "<meta"
        );
        const char *end;
        const char *name;
        const char *content;
        size_t name_len;
        size_t content_len;
        char *normalized;

        if (meta == NULL) break;
        end = memchr(meta, '>', parse_len - (size_t)(meta - text));
        if (end == NULL) break;

        if (html_extract_attr(
                meta + 5,
                (size_t)(end - (meta + 5)),
                "name",
                &name,
                &name_len
            ) &&
            html_extract_attr(
                meta + 5,
                (size_t)(end - (meta + 5)),
                "content",
                &content,
                &content_len
            )) {
            normalized = (char *)malloc(name_len + 1u);
            if (normalized == NULL) {
                jinx_zend_array_release(out);
                return jinx_oracle_zero_value();
            }
            html_normalize_meta_name(name, name_len, normalized);
            if (!http_assoc_string(
                    out,
                    normalized,
                    content,
                    content_len
                )) {
                free(normalized);
                jinx_zend_array_release(out);
                return jinx_oracle_zero_value();
            }
            free(normalized);
        }

        pos = (size_t)(end - text) + 1u;
    }

    return jinx_oracle_zend_array_value_owned(out);
}

JinxValue jinx_oracle_http_meta_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "get_headers") == 0) {
#ifdef JINX_HAVE_LIBCURL
        char *url;
        int associative = 0;
        JinxOracleHttpBuffer headers = {0};
        int ok;

        if (args == NULL || argc < 1u || argc > 3u ||
            args[0].type != 3u) {
            return result;
        }
        if (argc >= 3u && args[2].type != 0u) {
            return result;
        }
        if (argc >= 2u && args[1].type != 0u) {
            associative = jinx_oracle_boolish(args[1]);
        }

        url = http_dup_value(args[0]);
        if (url == NULL) return result;
        ok = http_fetch_url(url, NULL, &headers, 0);
        free(url);
        if (handled != NULL) *handled = 1;
        if (!ok) {
            free(headers.data);
            return jinx_oracle_bool_value(0);
        }
        result = http_headers_parse(
            headers.data, headers.len, associative
        );
        free(headers.data);
        return result;
#else
        (void)args;
        (void)argc;
        return result;
#endif
    }

    if (strcmp(name, "get_meta_tags") == 0) {
        char *filename;
        int use_include_path = 0;
        unsigned char *bytes = NULL;
        size_t len = 0u;
        int is_url;

        if (args == NULL || argc < 1u || argc > 2u ||
            args[0].type != 3u) {
            return result;
        }
        if (argc >= 2u && args[1].type != 0u) {
            use_include_path = jinx_oracle_boolish(args[1]);
        }

        filename = http_dup_value(args[0]);
        if (filename == NULL) return result;
        is_url =
            ascii_starts_ci(filename, strlen(filename), "http://") ||
            ascii_starts_ci(filename, strlen(filename), "https://");

        if (is_url) {
#ifdef JINX_HAVE_LIBCURL
            JinxOracleHttpBuffer body = {0};
            if (http_fetch_url(filename, &body, NULL, 1)) {
                bytes = body.data;
                len = body.len;
            }
#endif
        } else {
            bytes = use_include_path
                ? http_read_include_path(filename, &len)
                : http_read_file(filename, &len);
        }

        free(filename);
        if (handled != NULL) *handled = 1;
        if (bytes == NULL) return jinx_oracle_bool_value(0);

        result = html_meta_tags(bytes, len);
        free(bytes);
        return result;
    }

    return result;
}
