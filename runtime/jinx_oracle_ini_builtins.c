#include "jinx_oracle_ini_builtins.h"
#include "jinx_oracle_constant_registry.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <ctype.h>
#include <errno.h>
#include <limits.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>

static void ini_trim_span(
    const char *bytes,
    size_t len,
    size_t *start,
    size_t *end
) {
    size_t a = 0u;
    size_t b = len;

    while (a < b && isspace((unsigned char)bytes[a])) a++;
    while (b > a && isspace((unsigned char)bytes[b - 1u])) b--;

    if (start != NULL) *start = a;
    if (end != NULL) *end = b;
}

static int ini_text_equals(
    const char *bytes,
    size_t len,
    const char *literal
) {
    size_t literal_len;
    if (bytes == NULL || literal == NULL) return 0;
    literal_len = strlen(literal);
    return len == literal_len && strncasecmp(bytes, literal, len) == 0;
}

static int ini_make_string(
    const char *bytes,
    size_t len,
    JinxZendValue *out
) {
    JinxZendString *string;

    if (out == NULL || (bytes == NULL && len != 0u)) return 0;
    string = jinx_zend_string_new(bytes != NULL ? bytes : "", len);
    if (string == NULL) return 0;
    *out = jinx_zend_string_value(string);
    return 1;
}

static int ini_parse_decimal_int(
    const char *bytes,
    size_t len,
    int64_t *out
) {
    size_t pos = 0u;
    int negative = 0;
    uint64_t value = 0u;
    uint64_t limit;

    if (bytes == NULL || out == NULL || len == 0u) return 0;

    if (bytes[pos] == '+' || bytes[pos] == '-') {
        negative = bytes[pos] == '-';
        pos++;
        if (pos == len) return 0;
    }

    limit = negative
        ? (uint64_t)INT64_MAX + 1u
        : (uint64_t)INT64_MAX;

    for (; pos < len; pos++) {
        unsigned char ch = (unsigned char)bytes[pos];
        uint64_t digit;

        if (ch < '0' || ch > '9') return 0;
        digit = (uint64_t)(ch - '0');
        if (value > (limit - digit) / 10u) return 0;
        value = value * 10u + digit;
    }

    if (negative) {
        *out = value == (uint64_t)INT64_MAX + 1u
            ? INT64_MIN
            : -(int64_t)value;
    } else {
        *out = (int64_t)value;
    }
    return 1;
}

static int ini_parse_decimal_float(
    const char *bytes,
    size_t len,
    double *out
) {
    char text[128];
    size_t pos = 0u;
    size_t dots = 0u;
    size_t digits = 0u;
    char *end = NULL;

    if (bytes == NULL || out == NULL || len == 0u || len >= sizeof(text)) {
        return 0;
    }

    if (bytes[pos] == '+' || bytes[pos] == '-') pos++;
    if (pos == len) return 0;

    for (; pos < len; pos++) {
        unsigned char ch = (unsigned char)bytes[pos];
        if (ch >= '0' && ch <= '9') {
            digits++;
        } else if (ch == '.') {
            dots++;
            if (dots > 1u) return 0;
        } else {
            return 0;
        }
    }

    if (digits == 0u || dots != 1u) return 0;

    memcpy(text, bytes, len);
    text[len] = '\0';
    errno = 0;
    *out = strtod(text, &end);
    return errno != ERANGE && end != text && *end == '\0';
}

static int ini_decode_quoted(
    const char *bytes,
    size_t len,
    char **out,
    size_t *out_len,
    int *quoted
) {
    char quote = 0;
    size_t start = 0u;
    size_t end = len;
    size_t pos = 0u;
    char *decoded;

    if (out == NULL || out_len == NULL || quoted == NULL) return 0;
    *out = NULL;
    *out_len = 0u;
    *quoted = 0;

    if (len >= 1u && (bytes[0] == '"' || bytes[0] == '\'')) {
        quote = bytes[0];
        if (len < 2u || bytes[len - 1u] != quote) return 0;
        start = 1u;
        end = len - 1u;
        *quoted = 1;
    }

    decoded = (char *)malloc((end - start) + 1u);
    if (decoded == NULL) return 0;

    for (size_t i = start; i < end; i++) {
        char ch = bytes[i];
        if (*quoted && ch == '\\' && i + 1u < end) {
            char next = bytes[i + 1u];
            if (next == quote || next == '\\') {
                decoded[pos++] = next;
                i++;
                continue;
            }
        }
        decoded[pos++] = ch;
    }

    decoded[pos] = '\0';
    *out = decoded;
    *out_len = pos;
    return 1;
}

static int ini_has_unsupported_expression(
    const char *bytes,
    size_t len
) {
    if (bytes == NULL) return 0;

    for (size_t i = 0u; i < len; i++) {
        char ch = bytes[i];
        if (ch == '$' || ch == '|' || ch == '&') return 1;
    }

    if (len != 0u && (bytes[0] == '~' || bytes[0] == '!')) return 1;
    return 0;
}

static const JinxNativeConstantMeta *ini_generated_constant(
    const char *name
) {
    if (name == NULL) return NULL;

    for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
        if (strcmp(name, jinx_native_constant_metadata[i].name) == 0) {
            return &jinx_native_constant_metadata[i];
        }
    }
    return NULL;
}

static int ini_jinx_value_as_string(
    JinxValue value,
    JinxZendValue *out
) {
    char text[128];
    int written;

    if (out == NULL) return 0;

    if (value.type == 0u) {
        return ini_make_string("", 0u, out);
    }
    if (value.type == 2u) {
        return ini_make_string(
            value.as.i64 != 0 ? "1" : "",
            value.as.i64 != 0 ? 1u : 0u,
            out
        );
    }
    if (value.type == 1u) {
        written = snprintf(
            text, sizeof(text), "%lld", (long long)value.as.i64
        );
        return written >= 0 && (size_t)written < sizeof(text) &&
            ini_make_string(text, (size_t)written, out);
    }
    if (value.type == 5u) {
        written = snprintf(text, sizeof(text), "%.17g", value.as.f64);
        return written >= 0 && (size_t)written < sizeof(text) &&
            ini_make_string(text, (size_t)written, out);
    }
    if (value.type == 3u) {
        return ini_make_string(
            (const char *)jinx_oracle_string_bytes(value),
            (size_t)jinx_oracle_string_len(value),
            out
        );
    }

    return 0;
}

static int ini_constant_as_string(
    const char *name,
    JinxZendValue *out
) {
    JinxValue runtime_value = jinx_oracle_zero_value();
    const JinxNativeConstantMeta *meta;

    if (name == NULL || out == NULL) return 0;

    if (jinx_oracle_constant_registry_get(name, &runtime_value)) {
        return ini_jinx_value_as_string(runtime_value, out);
    }

    meta = ini_generated_constant(name);
    if (meta == NULL) return 0;

    if (meta->type == 1u) {
        return ini_jinx_value_as_string(
            jinx_oracle_int_value((int64_t)meta->i64),
            out
        );
    }
    if (meta->type == 2u) {
        return ini_jinx_value_as_string(
            jinx_oracle_bool_value(meta->i64 != 0),
            out
        );
    }
    if (meta->type == 3u) {
        return ini_make_string(
            meta->str != NULL ? meta->str : "",
            meta->str != NULL ? strlen(meta->str) : 0u,
            out
        );
    }
    if (meta->type == 5u) {
        return ini_jinx_value_as_string(
            jinx_oracle_float_value(meta->f64),
            out
        );
    }

    return 0;
}

static int ini_parse_value(
    const char *bytes,
    size_t len,
    int scanner_mode,
    JinxZendValue *out
) {
    char *decoded = NULL;
    size_t decoded_len = 0u;
    int quoted = 0;
    int64_t iv;
    double dv;

    if (out == NULL || scanner_mode < 0 || scanner_mode > 2) return 0;

    if (!ini_decode_quoted(
        bytes, len, &decoded, &decoded_len, &quoted
    )) {
        return 0;
    }

    if (!quoted && scanner_mode != 1) {
        if (ini_has_unsupported_expression(decoded, decoded_len)) {
            free(decoded);
            return -1;
        }

        if (ini_constant_as_string(decoded, out)) {
            free(decoded);
            return 1;
        }
    }

    if (!quoted && scanner_mode == 0) {
        if (ini_text_equals(decoded, decoded_len, "true") ||
            ini_text_equals(decoded, decoded_len, "yes") ||
            ini_text_equals(decoded, decoded_len, "on")) {
            free(decoded);
            return ini_make_string("1", 1u, out) ? 1 : 0;
        }

        if (ini_text_equals(decoded, decoded_len, "false") ||
            ini_text_equals(decoded, decoded_len, "no") ||
            ini_text_equals(decoded, decoded_len, "off") ||
            ini_text_equals(decoded, decoded_len, "none") ||
            ini_text_equals(decoded, decoded_len, "null")) {
            free(decoded);
            return ini_make_string("", 0u, out) ? 1 : 0;
        }
    }

    if (!quoted && scanner_mode == 2) {
        if (ini_text_equals(decoded, decoded_len, "true") ||
            ini_text_equals(decoded, decoded_len, "yes") ||
            ini_text_equals(decoded, decoded_len, "on")) {
            *out = jinx_zend_bool(1);
            free(decoded);
            return 1;
        }

        if (ini_text_equals(decoded, decoded_len, "false") ||
            ini_text_equals(decoded, decoded_len, "no") ||
            ini_text_equals(decoded, decoded_len, "off") ||
            ini_text_equals(decoded, decoded_len, "none")) {
            *out = jinx_zend_bool(0);
            free(decoded);
            return 1;
        }

        if (ini_text_equals(decoded, decoded_len, "null")) {
            *out = jinx_zend_null();
            free(decoded);
            return 1;
        }

        if (ini_parse_decimal_int(decoded, decoded_len, &iv)) {
            *out = jinx_zend_long(iv);
            free(decoded);
            return 1;
        }

        if (ini_parse_decimal_float(decoded, decoded_len, &dv)) {
            *out = jinx_zend_double(dv);
            free(decoded);
            return 1;
        }
    }

    if (!ini_make_string(decoded, decoded_len, out)) {
        free(decoded);
        return 0;
    }

    free(decoded);
    return 1;
}

static int ini_parse_bytes(
    const unsigned char *bytes,
    size_t len,
    int process_sections,
    int scanner_mode,
    JinxValue *out
) {
    JinxZendArray *root;
    JinxZendArray *target;
    size_t cursor = 0u;

    if (bytes == NULL || out == NULL || scanner_mode < 0 || scanner_mode > 2) {
        return 0;
    }
    if (memchr(bytes, '\0', len) != NULL) return 0;

    root = jinx_zend_array_new_packed(8u);
    if (root == NULL) return 0;
    target = root;

    while (cursor < len) {
        size_t raw_end = cursor;
        size_t logical_end;
        size_t rel_start;
        size_t rel_end;
        size_t a;
        size_t b;
        int quote = 0;
        int escaped = 0;

        while (raw_end < len && bytes[raw_end] != '\n') raw_end++;
        logical_end = raw_end;
        if (logical_end > cursor && bytes[logical_end - 1u] == '\r') {
            logical_end--;
        }

        ini_trim_span(
            (const char *)bytes + cursor,
            logical_end - cursor,
            &rel_start,
            &rel_end
        );
        a = cursor + rel_start;
        b = cursor + rel_end;

        if (a < b && bytes[a] != ';' && bytes[a] != '#') {
            size_t comment_end = b;

            for (size_t i = a; i < b; i++) {
                char ch = (char)bytes[i];

                if (escaped) {
                    escaped = 0;
                    continue;
                }
                if (quote != 0 && ch == '\\') {
                    escaped = 1;
                    continue;
                }
                if (ch == '"' || ch == '\'') {
                    if (quote == 0) quote = ch;
                    else if (quote == ch) quote = 0;
                    continue;
                }
                if (quote == 0 && ch == ';') {
                    comment_end = i;
                    break;
                }
            }

            if (quote != 0) {
                jinx_zend_array_release(root);
                return 0;
            }

            ini_trim_span(
                (const char *)bytes + a,
                comment_end - a,
                &rel_start,
                &rel_end
            );
            a += rel_start;
            b = a + (rel_end - rel_start);

            if (a < b && bytes[a] == '[') {
                size_t name_start;
                size_t name_end;
                JinxZendValue *existing;
                JinxZendArray *section;

                if (bytes[b - 1u] != ']') {
                    jinx_zend_array_release(root);
                    return 0;
                }

                ini_trim_span(
                    (const char *)bytes + a + 1u,
                    (b - 1u) - (a + 1u),
                    &rel_start,
                    &rel_end
                );
                name_start = a + 1u + rel_start;
                name_end = a + 1u + rel_end;

                if (name_start >= name_end) {
                    jinx_zend_array_release(root);
                    return 0;
                }

                if (process_sections) {
                    existing = jinx_zend_array_find(
                        root,
                        (const char *)bytes + name_start,
                        name_end - name_start
                    );

                    if (existing != NULL) {
                        if (existing->type != JINX_ZEND_ARRAY ||
                            existing->value.array == NULL) {
                            jinx_zend_array_release(root);
                            return 0;
                        }
                        target = existing->value.array;
                    } else {
                        section = jinx_zend_array_new_packed(4u);
                        if (section == NULL ||
                            !jinx_zend_array_add_assoc(
                                root,
                                (const char *)bytes + name_start,
                                name_end - name_start,
                                jinx_zend_array_value(section)
                            )) {
                            jinx_zend_array_release(section);
                            jinx_zend_array_release(root);
                            return 0;
                        }

                        jinx_zend_array_release(section);
                        existing = jinx_zend_array_find(
                            root,
                            (const char *)bytes + name_start,
                            name_end - name_start
                        );
                        if (existing == NULL ||
                            existing->type != JINX_ZEND_ARRAY ||
                            existing->value.array == NULL) {
                            jinx_zend_array_release(root);
                            return 0;
                        }
                        target = existing->value.array;
                    }
                } else {
                    target = root;
                }
            } else if (a < b) {
                size_t equal = a;
                size_t key_start;
                size_t key_end;
                size_t value_start;
                size_t value_end;
                JinxZendValue value = jinx_zend_null();
                int status;

                while (equal < b && bytes[equal] != '=') equal++;
                if (equal == b) {
                    jinx_zend_array_release(root);
                    return 0;
                }

                ini_trim_span(
                    (const char *)bytes + a,
                    equal - a,
                    &rel_start,
                    &rel_end
                );
                key_start = a + rel_start;
                key_end = a + rel_end;

                if (key_start >= key_end) {
                    jinx_zend_array_release(root);
                    return 0;
                }

                if (memchr(
                    bytes + key_start,
                    '[',
                    key_end - key_start
                ) != NULL) {
                    jinx_zend_array_release(root);
                    return -1;
                }

                ini_trim_span(
                    (const char *)bytes + equal + 1u,
                    b - (equal + 1u),
                    &rel_start,
                    &rel_end
                );
                value_start = equal + 1u + rel_start;
                value_end = equal + 1u + rel_end;

                status = ini_parse_value(
                    (const char *)bytes + value_start,
                    value_end - value_start,
                    scanner_mode,
                    &value
                );
                if (status <= 0) {
                    jinx_zend_value_release(value);
                    jinx_zend_array_release(root);
                    return status;
                }

                if (!jinx_zend_array_add_assoc(
                    target,
                    (const char *)bytes + key_start,
                    key_end - key_start,
                    value
                )) {
                    jinx_zend_value_release(value);
                    jinx_zend_array_release(root);
                    return 0;
                }
                jinx_zend_value_release(value);
            }
        }

        cursor = raw_end < len ? raw_end + 1u : len;
    }

    *out = jinx_oracle_zend_array_value_owned(root);
    return 1;
}

static int ini_read_file(
    const char *path,
    unsigned char **out,
    size_t *out_len
) {
    FILE *fp;
    long length;
    unsigned char *buffer;
    size_t got;

    if (path == NULL || out == NULL || out_len == NULL) return 0;
    *out = NULL;
    *out_len = 0u;

    fp = fopen(path, "rb");
    if (fp == NULL) return 0;

    if (fseek(fp, 0, SEEK_END) != 0) {
        fclose(fp);
        return 0;
    }

    length = ftell(fp);
    if (length < 0 || (uintmax_t)length > (uintmax_t)(SIZE_MAX - 1u) ||
        fseek(fp, 0, SEEK_SET) != 0) {
        fclose(fp);
        return 0;
    }

    buffer = (unsigned char *)malloc((size_t)length + 1u);
    if (buffer == NULL) {
        fclose(fp);
        return 0;
    }

    got = fread(buffer, 1u, (size_t)length, fp);
    if (got != (size_t)length || ferror(fp)) {
        free(buffer);
        fclose(fp);
        return 0;
    }

    fclose(fp);
    buffer[got] = '\0';
    *out = buffer;
    *out_len = got;
    return 1;
}

static int ini_decode_options(
    JinxValue *args,
    size_t argc,
    int *process_sections,
    int *scanner_mode
) {
    if (args == NULL || process_sections == NULL || scanner_mode == NULL ||
        argc < 1u || argc > 3u) {
        return 0;
    }

    *process_sections = 0;
    *scanner_mode = 0;

    if (argc >= 2u) {
        if (args[1].type != 1u && args[1].type != 2u) return 0;
        *process_sections = jinx_oracle_boolish(args[1]) ? 1 : 0;
    }

    if (argc >= 3u) {
        int64_t mode;
        if (args[2].type != 1u) return 0;
        mode = args[2].as.i64;
        if (mode < 0 || mode > 2) return 0;
        *scanner_mode = (int)mode;
    }

    return 1;
}

JinxValue jinx_oracle_ini_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    int process_sections;
    int scanner_mode;
    int status;

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "parse_ini_string") == 0) {
        if (!ini_decode_options(
            args, argc, &process_sections, &scanner_mode
        ) || args[0].type != 3u) {
            return result;
        }

        status = ini_parse_bytes(
            jinx_oracle_string_bytes(args[0]),
            jinx_oracle_string_len(args[0]),
            process_sections,
            scanner_mode,
            &result
        );

        if (status < 0) return jinx_oracle_zero_value();
        if (handled != NULL) *handled = 1;
        return status > 0 ? result : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "parse_ini_file") == 0) {
        char *path;
        unsigned char *bytes = NULL;
        size_t len = 0u;

        if (!ini_decode_options(
            args, argc, &process_sections, &scanner_mode
        ) || args[0].type != 3u) {
            return result;
        }

        path = (char *)malloc((size_t)args[0].flags + 1u);
        if (path == NULL) return result;
        if (args[0].flags != 0u) {
            memcpy(
                path,
                jinx_oracle_string_bytes(args[0]),
                args[0].flags
            );
        }
        path[args[0].flags] = '\0';

        if (!ini_read_file(path, &bytes, &len)) {
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        free(path);

        status = ini_parse_bytes(
            bytes,
            len,
            process_sections,
            scanner_mode,
            &result
        );
        free(bytes);

        if (status < 0) return jinx_oracle_zero_value();
        if (handled != NULL) *handled = 1;
        return status > 0 ? result : jinx_oracle_bool_value(0);
    }

    return result;
}
