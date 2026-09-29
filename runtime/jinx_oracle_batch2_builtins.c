#include "jinx_oracle_batch2_builtins.h"
#include "jinx_oracle_extended_builtins.h"
#include "jinx_oracle_hash_builtins.h"
#include "jinx_oracle_finfo_builtins.h"
#include "jinx_oracle_resource_registry.h"
#include "jinx_oracle_solar_builtins.h"
#include "jinx_oracle_dns_builtins.h"
#include "jinx_oracle_ftp_builtins.h"
#include "jinx_oracle_curl_ftp_builtins.h"
#include "jinx_oracle_http_meta_builtins.h"
#include "jinx_oracle_constant_registry.h"
#include "jinx_oracle_exif_builtins.h"
#include "jinx_oracle_frame_context.h"
#include "jinx_oracle_script_context.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <fnmatch.h>
#include <grp.h>
#include <libintl.h>
#include <langinfo.h>
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
#include <sys/stat.h>
#include <sys/statvfs.h>
#include <sys/time.h>
#include <sys/times.h>
#include <sys/types.h>
#include <sys/utsname.h>
#include <sys/wait.h>
#include <syslog.h>
#ifdef __linux__
#include <sys/prctl.h>
#endif
#include <time.h>
#include <unistd.h>
#include <zlib.h>
#ifdef JINX_HAVE_ICONV
#include <iconv.h>
#endif
#ifdef JINX_HAVE_CRYPT
#include <crypt.h>
#endif
#ifdef JINX_HAVE_RESOLV
#include <arpa/nameser.h>
#include <resolv.h>
#endif

extern char **environ;
extern char *strptime(const char *s, const char *format, struct tm *tm);

static int64_t jinx_oracle_batch2_error_reporting = JINX_NATIVE_PHP_ERROR_REPORTING;
static int jinx_oracle_batch2_assert_active = JINX_NATIVE_ASSERT_ACTIVE;
static int jinx_oracle_batch2_assert_warning = JINX_NATIVE_ASSERT_WARNING;
static int jinx_oracle_batch2_assert_bail = JINX_NATIVE_ASSERT_BAIL;
static int jinx_oracle_batch2_assert_exception = JINX_NATIVE_ASSERT_EXCEPTION;
static char *jinx_oracle_batch2_assert_callback = NULL;
static char *jinx_oracle_batch2_process_title = NULL;
static int jinx_oracle_batch2_posix_last_error = 0;
static char *jinx_oracle_batch2_syslog_ident = NULL;
static struct timeval jinx_oracle_batch2_uniqid_prev = {0, 0};
static unsigned char *jinx_oracle_batch2_strtok_string = NULL;
static size_t jinx_oracle_batch2_strtok_len = 0u;
static size_t jinx_oracle_batch2_strtok_pos = 0u;

#ifdef JINX_HAVE_ICONV
static char jinx_oracle_batch2_iconv_input_encoding[128] = JINX_NATIVE_ICONV_INPUT_ENCODING;
static char jinx_oracle_batch2_iconv_output_encoding[128] = JINX_NATIVE_ICONV_OUTPUT_ENCODING;
static char jinx_oracle_batch2_iconv_internal_encoding[128] = JINX_NATIVE_ICONV_INTERNAL_ENCODING;
#endif

typedef struct JinxOracleBatch2IniOverride {
    char *name;
    char *value;
} JinxOracleBatch2IniOverride;

static JinxOracleBatch2IniOverride *jinx_oracle_batch2_ini_overrides = NULL;
static size_t jinx_oracle_batch2_ini_override_count = 0u;
static size_t jinx_oracle_batch2_ini_override_capacity = 0u;


static int64_t b2_gregorian_to_sdn(int input_year, int input_month, int input_day) {
    int64_t year;
    int month;

    if (input_year == 0 || input_year < -4714 ||
        input_year > INT_MAX - 4800 ||
        input_month <= 0 || input_month > 12 ||
        input_day <= 0 || input_day > 31) {
        return 0;
    }
    if (input_year == -4714 &&
        (input_month < 11 || (input_month == 11 && input_day < 25))) {
        return 0;
    }

    year = input_year < 0 ? (int64_t)input_year + 4801LL
                         : (int64_t)input_year + 4800LL;
    if (input_month > 2) {
        month = input_month - 3;
    } else {
        month = input_month + 9;
        year--;
    }

    return (((year / 100LL) * 146097LL) / 4LL
        + ((year % 100LL) * 1461LL) / 4LL
        + ((int64_t)month * 153LL + 2LL) / 5LL
        + (int64_t)input_day
        - 32045LL);
}

static int b2_random_fill(unsigned char *out, size_t len) {
    int fd;
    size_t offset = 0u;
    if (out == NULL && len != 0u) return 0;
    fd = open("/dev/urandom", O_RDONLY);
    if (fd < 0) return 0;
    while (offset < len) {
        ssize_t n = read(fd, out + offset, len - offset);
        if (n < 0) {
            if (errno == EINTR) continue;
            close(fd);
            return 0;
        }
        if (n == 0) {
            close(fd);
            return 0;
        }
        offset += (size_t)n;
    }
    close(fd);
    return 1;
}

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

static int b2_frame_value_to_jinx(
    JinxZendValue value,
    JinxValue *out
) {
    if (out == NULL) return 0;
    if (jinx_oracle_zend_to_jinx_borrowed(value, out)) return 1;
    if (value.type == JINX_ZEND_OBJECT && value.value.object != NULL) {
        *out = jinx_oracle_zend_object_value_borrowed(value.value.object);
        return 1;
    }
    return 0;
}


static const JinxNativeConstantMeta *b2_constant_meta(const char *name);

static int64_t b2_constant_int(const char *name, int64_t fallback) {
    const JinxNativeConstantMeta *meta = b2_constant_meta(name);
    return meta != NULL && meta->type == 1u ? meta->i64 : fallback;
}

static int b2_valid_var_name(const char *name, size_t len) {
    const unsigned char *p = (const unsigned char *)name;
    if (name == NULL || len == 0u) return 0;
    if (!((p[0] >= 'a' && p[0] <= 'z') ||
          (p[0] >= 'A' && p[0] <= 'Z') ||
          p[0] == '_' || p[0] >= 0x80u)) {
        return 0;
    }
    for (size_t i = 1u; i < len; i++) {
        if (!((p[i] >= 'a' && p[i] <= 'z') ||
              (p[i] >= 'A' && p[i] <= 'Z') ||
              (p[i] >= '0' && p[i] <= '9') ||
              p[i] == '_' || p[i] >= 0x80u)) {
            return 0;
        }
    }
    return 1;
}

static char *b2_prefixed_var_name(
    const char *prefix,
    size_t prefix_len,
    const char *name,
    size_t name_len
) {
    char *out = (char *)malloc(prefix_len + name_len + 2u);
    if (out == NULL) return NULL;
    if (prefix_len != 0u) memcpy(out, prefix, prefix_len);
    out[prefix_len] = '_';
    if (name_len != 0u) memcpy(out + prefix_len + 1u, name, name_len);
    out[prefix_len + 1u + name_len] = '\0';
    return out;
}

static int b2_compact_one(
    JinxZendCallFrame *frame,
    JinxZendArray *out,
    JinxValue request,
    unsigned depth
) {
    if (frame == NULL || frame->locals == NULL || out == NULL || depth > 64u) {
        return 0;
    }

    if (request.type == 3u) {
        const char *name = (const char *)jinx_oracle_string_bytes(request);
        size_t len = jinx_oracle_string_len(request);
        JinxZendValue *value;
        if (memchr(name, '\0', len) != NULL) return 1;
        value = jinx_zend_array_find(frame->locals, name, len);
        if (value != NULL &&
            !jinx_zend_array_add_assoc(out, name, len, *value)) {
            return 0;
        }
        return 1;
    }

    if (jinx_oracle_value_is_zend_array(request)) {
        JinxZendArray *array = jinx_oracle_zend_array_ptr(request);
        size_t live = jinx_zend_array_live_count(array);
        for (size_t i = 0u; i < live; i++) {
            const JinxZendBucket *bucket =
                jinx_zend_array_live_iter_at(array, i);
            JinxValue nested;
            if (bucket == NULL) return 0;
            if (!b2_frame_value_to_jinx(bucket->value, &nested)) {
                /* PHP warns for non-string/non-array compact entries and
                 * continues unless a user handler throws. */
                continue;
            }
            if (!b2_compact_one(frame, out, nested, depth + 1u)) return 0;
        }
        return 1;
    }

    return 1;
}

static int b2_extract_nonref(
    JinxZendCallFrame *frame,
    JinxZendArray *input,
    int mode,
    const char *prefix,
    size_t prefix_len,
    int64_t *count_out
) {
    size_t live;
    int64_t count = 0;

    if (frame == NULL || frame->locals == NULL ||
        input == NULL || count_out == NULL) {
        return 0;
    }

    live = jinx_zend_array_live_count(input);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket =
            jinx_zend_array_live_iter_at(input, i);
        const char *raw_name = NULL;
        size_t raw_len = 0u;
        char numeric_name[64];
        char *owned_final = NULL;
        const char *final_name = NULL;
        size_t final_len = 0u;
        int raw_valid = 0;
        int raw_this = 0;
        int raw_globals = 0;
        JinxZendValue *existing = NULL;

        if (bucket == NULL) return 0;

        if (bucket->key != NULL) {
            raw_name = bucket->key->bytes;
            raw_len = bucket->key->len;
            raw_valid = b2_valid_var_name(raw_name, raw_len);
            raw_this = raw_len == 4u &&
                memcmp(raw_name, "this", 4u) == 0;
            raw_globals = raw_len == 7u &&
                memcmp(raw_name, "GLOBALS", 7u) == 0;
            existing = jinx_zend_array_find(
                frame->locals, raw_name, raw_len
            );
        } else {
            int n = snprintf(
                numeric_name, sizeof(numeric_name),
                "%lld", (long long)(int64_t)bucket->h
            );
            if (n < 0 || (size_t)n >= sizeof(numeric_name)) return 0;
            raw_name = numeric_name;
            raw_len = (size_t)n;
        }

        switch (mode) {
            case 0: /* EXTR_OVERWRITE */
                if (bucket->key == NULL || !raw_valid ||
                    raw_this || raw_globals) continue;
                final_name = raw_name;
                final_len = raw_len;
                break;

            case 1: /* EXTR_SKIP */
                if (bucket->key == NULL || !raw_valid ||
                    raw_this || existing != NULL) continue;
                final_name = raw_name;
                final_len = raw_len;
                break;

            case 2: /* EXTR_PREFIX_SAME */
                if (bucket->key == NULL || raw_len == 0u) continue;
                if (existing != NULL || raw_this) {
                    owned_final = b2_prefixed_var_name(
                        prefix, prefix_len, raw_name, raw_len
                    );
                    if (owned_final == NULL) return 0;
                    final_name = owned_final;
                    final_len = strlen(owned_final);
                    if (!b2_valid_var_name(final_name, final_len)) {
                        free(owned_final);
                        continue;
                    }
                } else {
                    if (!raw_valid) continue;
                    final_name = raw_name;
                    final_len = raw_len;
                }
                break;

            case 3: /* EXTR_PREFIX_ALL */
                owned_final = b2_prefixed_var_name(
                    prefix, prefix_len, raw_name, raw_len
                );
                if (owned_final == NULL) return 0;
                final_name = owned_final;
                final_len = strlen(owned_final);
                if (!b2_valid_var_name(final_name, final_len)) {
                    free(owned_final);
                    continue;
                }
                break;

            case 4: /* EXTR_PREFIX_INVALID */
                if (bucket->key != NULL && raw_valid && !raw_this) {
                    final_name = raw_name;
                    final_len = raw_len;
                } else {
                    owned_final = b2_prefixed_var_name(
                        prefix, prefix_len, raw_name, raw_len
                    );
                    if (owned_final == NULL) return 0;
                    final_name = owned_final;
                    final_len = strlen(owned_final);
                    if (!b2_valid_var_name(final_name, final_len)) {
                        free(owned_final);
                        continue;
                    }
                }
                break;

            case 5: /* EXTR_PREFIX_IF_EXISTS */
                if (bucket->key == NULL || existing == NULL) continue;
                owned_final = b2_prefixed_var_name(
                    prefix, prefix_len, raw_name, raw_len
                );
                if (owned_final == NULL) return 0;
                final_name = owned_final;
                final_len = strlen(owned_final);
                if (!b2_valid_var_name(final_name, final_len)) {
                    free(owned_final);
                    continue;
                }
                break;

            case 6: /* EXTR_IF_EXISTS */
                if (bucket->key == NULL || existing == NULL ||
                    !raw_valid || raw_this || raw_globals) continue;
                final_name = raw_name;
                final_len = raw_len;
                break;

            default:
                return 0;
        }

        if (!jinx_zend_array_add_assoc(
                frame->locals,
                final_name,
                final_len,
                bucket->value
            )) {
            free(owned_final);
            return 0;
        }
        free(owned_final);
        count++;
    }

    *count_out = count;
    return 1;
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

#ifdef JINX_HAVE_ICONV
static int b2_iconv_convert(
    const char *from_encoding,
    const char *to_encoding,
    const unsigned char *input,
    size_t input_len,
    unsigned char **output,
    size_t *output_len
) {
    iconv_t cd;
    unsigned char *buffer;
    size_t capacity;
    char *in_ptr;
    size_t in_left;
    char *out_ptr;
    size_t out_left;

    if (from_encoding == NULL || to_encoding == NULL ||
        output == NULL || output_len == NULL) return 0;

    cd = iconv_open(to_encoding, from_encoding);
    if (cd == (iconv_t)-1) return 0;

    capacity = input_len > (SIZE_MAX - 64u) / 4u
        ? input_len + 64u
        : input_len * 4u + 64u;
    if (capacity < 64u) capacity = 64u;

    buffer = (unsigned char *)malloc(capacity);
    if (buffer == NULL) {
        iconv_close(cd);
        return 0;
    }

    in_ptr = (char *)(uintptr_t)input;
    in_left = input_len;
    out_ptr = (char *)buffer;
    out_left = capacity;

    while (1) {
        size_t rc = iconv(cd, &in_ptr, &in_left, &out_ptr, &out_left);
        if (rc != (size_t)-1) break;
        if (errno != E2BIG) {
            free(buffer);
            iconv_close(cd);
            return 0;
        }

        {
            size_t used = (size_t)(out_ptr - (char *)buffer);
            size_t new_capacity = capacity > SIZE_MAX / 2u ? SIZE_MAX : capacity * 2u;
            unsigned char *grown;
            if (new_capacity <= capacity) {
                free(buffer);
                iconv_close(cd);
                return 0;
            }
            grown = (unsigned char *)realloc(buffer, new_capacity);
            if (grown == NULL) {
                free(buffer);
                iconv_close(cd);
                return 0;
            }
            buffer = grown;
            capacity = new_capacity;
            out_ptr = (char *)buffer + used;
            out_left = capacity - used;
        }
    }

    while (1) {
        size_t rc = iconv(cd, NULL, NULL, &out_ptr, &out_left);
        if (rc != (size_t)-1) break;
        if (errno != E2BIG) {
            free(buffer);
            iconv_close(cd);
            return 0;
        }
        {
            size_t used = (size_t)(out_ptr - (char *)buffer);
            size_t new_capacity = capacity > SIZE_MAX / 2u ? SIZE_MAX : capacity * 2u;
            unsigned char *grown;
            if (new_capacity <= capacity) {
                free(buffer);
                iconv_close(cd);
                return 0;
            }
            grown = (unsigned char *)realloc(buffer, new_capacity);
            if (grown == NULL) {
                free(buffer);
                iconv_close(cd);
                return 0;
            }
            buffer = grown;
            capacity = new_capacity;
            out_ptr = (char *)buffer + used;
            out_left = capacity - used;
        }
    }

    *output_len = (size_t)(out_ptr - (char *)buffer);
    *output = buffer;
    iconv_close(cd);
    return 1;
}

static int b2_iconv_utf32(
    JinxValue value,
    const char *encoding,
    unsigned char **output,
    size_t *output_len
) {
    if (value.type != 3u) return 0;
    return b2_iconv_convert(
        encoding,
        "UTF-32LE",
        jinx_oracle_string_bytes(value),
        jinx_oracle_string_len(value),
        output,
        output_len
    );
}

static int64_t b2_iconv_normalize_offset(int64_t offset, size_t units) {
    int64_t total = units > (size_t)INT64_MAX ? INT64_MAX : (int64_t)units;
    if (offset < 0) offset = total + offset;
    return offset;
}

static int b2_iconv_encoding_supported(const char *encoding) {
    iconv_t cd;
    if (encoding == NULL || *encoding == '\0') return 0;
    cd = iconv_open("UTF-8", encoding);
    if (cd == (iconv_t)-1) return 0;
    iconv_close(cd);
    return 1;
}
#endif

static void b2_print_trace_string(
    const char *bytes,
    size_t len
) {
    fputc('\'', stdout);
    for (size_t i = 0u; i < len; i++) {
        unsigned char ch = (unsigned char)bytes[i];
        if (ch == '\\' || ch == '\'') {
            fputc('\\', stdout);
            fputc((int)ch, stdout);
        } else if (ch == '\n') {
            fputs("\\n", stdout);
        } else if (ch == '\r') {
            fputs("\\r", stdout);
        } else if (ch == '\t') {
            fputs("\\t", stdout);
        } else if (ch == '\0') {
            fputs("\\0", stdout);
        } else {
            fputc((int)ch, stdout);
        }
    }
    fputc('\'', stdout);
}

static void b2_print_trace_value(JinxZendValue value) {
    switch (value.type) {
        case JINX_ZEND_NULL:
            fputs("NULL", stdout);
            break;
        case JINX_ZEND_FALSE:
            fputs("false", stdout);
            break;
        case JINX_ZEND_TRUE:
            fputs("true", stdout);
            break;
        case JINX_ZEND_LONG:
            fprintf(stdout, "%lld", (long long)value.value.lval);
            break;
        case JINX_ZEND_DOUBLE:
            fprintf(stdout, "%.14g", value.value.dval);
            break;
        case JINX_ZEND_STRING:
            if (value.value.str == NULL) {
                fputs("''", stdout);
            } else {
                b2_print_trace_string(
                    value.value.str->bytes,
                    value.value.str->len
                );
            }
            break;
        case JINX_ZEND_ARRAY:
            fputs("Array", stdout);
            break;
        case JINX_ZEND_OBJECT:
            fprintf(
                stdout,
                "Object(%s)",
                value.value.object != NULL &&
                value.value.object->class_name != NULL
                    ? value.value.object->class_name
                    : "stdClass"
            );
            break;
        case JINX_ZEND_RESOURCE: {
            int64_t id = jinx_oracle_resource_id(value.value.ptr);
            fprintf(
                stdout,
                "Resource id #%lld",
                (long long)(id > 0 ? id : 0)
            );
            break;
        }
        case JINX_ZEND_REFERENCE:
            if (value.value.ref != NULL) {
                b2_print_trace_value(value.value.ref->value);
            } else {
                fputs("NULL", stdout);
            }
            break;
        default:
            fputs("NULL", stdout);
            break;
    }
}

static int b2_assoc_string(
    JinxZendArray *array,
    const char *key,
    const char *text
) {
    JinxZendString *string;
    int ok;
    if (array == NULL || key == NULL || text == NULL) return 0;
    string = jinx_zend_string_new(text, strlen(text));
    if (string == NULL) return 0;
    ok = jinx_zend_array_add_assoc(
        array, key, strlen(key), jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static int b2_assoc_value(
    JinxZendArray *array,
    const char *key,
    JinxValue value
) {
    JinxZendValue zend_value;
    JinxZendString *owned_string = NULL;
    int ok;

    if (array == NULL || key == NULL) return 0;
    if (!jinx_oracle_jinx_value_to_zend(
            value, &zend_value, &owned_string)) {
        return 0;
    }
    ok = jinx_zend_array_add_assoc(
        array, key, strlen(key), zend_value
    );
    jinx_zend_string_release(owned_string);
    return ok;
}

static int b2_constant_time_string_equal(
    const char *left,
    const char *right
) {
    size_t left_len;
    size_t right_len;
    unsigned char diff = 0u;

    if (left == NULL || right == NULL) return 0;
    left_len = strlen(left);
    right_len = strlen(right);
    if (left_len != right_len) return 0;

    for (size_t i = 0u; i < left_len; i++) {
        diff |= (unsigned char)left[i] ^ (unsigned char)right[i];
    }
    return diff == 0u;
}

static int b2_bcrypt_cost(const char *hash, int *cost) {
    if (hash == NULL || cost == NULL || strlen(hash) < 7u) return 0;
    if ((unsigned char)hash[0] != 36u || hash[1] != '2' ||
        (hash[2] != 'y' && hash[2] != 'a' && hash[2] != 'b' && hash[2] != 'x') ||
        (unsigned char)hash[3] != 36u ||
        hash[4] < '0' || hash[4] > '9' ||
        hash[5] < '0' || hash[5] > '9' ||
        (unsigned char)hash[6] != 36u) {
        return 0;
    }
    *cost = (hash[4] - '0') * 10 + (hash[5] - '0');
    return *cost >= 4 && *cost <= 31;
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

static int b2_assoc_nullable_string(
    JinxZendArray *array,
    const char *key,
    const char *text
) {
    if (array == NULL || key == NULL) return 0;
    if (text == NULL) {
        return jinx_zend_array_add_assoc(
            array, key, strlen(key), jinx_zend_null()
        );
    }
    return b2_assoc_string(array, key, text);
}

static JinxValue b2_posix_passwd_value(const struct passwd *pw) {
    JinxZendArray *array;
    if (pw == NULL) return jinx_oracle_bool_value(0);
    array = jinx_zend_array_new_packed(7u);
    if (array == NULL) return jinx_oracle_zero_value();
    if (!b2_assoc_nullable_string(array, "name", pw->pw_name) ||
        !b2_assoc_nullable_string(array, "passwd", pw->pw_passwd) ||
        !jinx_zend_array_add_assoc(array, "uid", 3u, jinx_zend_long((int64_t)pw->pw_uid)) ||
        !jinx_zend_array_add_assoc(array, "gid", 3u, jinx_zend_long((int64_t)pw->pw_gid)) ||
        !b2_assoc_nullable_string(array, "gecos", pw->pw_gecos) ||
        !b2_assoc_nullable_string(array, "dir", pw->pw_dir) ||
        !b2_assoc_nullable_string(array, "shell", pw->pw_shell)) {
        jinx_zend_array_release(array);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_array_value_owned(array);
}

static JinxValue b2_posix_group_value(const struct group *group) {
    JinxZendArray *array;
    JinxZendArray *members;
    if (group == NULL) return jinx_oracle_bool_value(0);
    array = jinx_zend_array_new_packed(4u);
    members = jinx_zend_array_new_packed(4u);
    if (array == NULL || members == NULL) {
        jinx_zend_array_release(array);
        jinx_zend_array_release(members);
        return jinx_oracle_zero_value();
    }
    if (group->gr_mem != NULL) {
        for (size_t i = 0u; group->gr_mem[i] != NULL; i++) {
            if (!b2_append_string(members, group->gr_mem[i])) {
                jinx_zend_array_release(array);
                jinx_zend_array_release(members);
                return jinx_oracle_zero_value();
            }
        }
    }
    if (!b2_assoc_nullable_string(array, "name", group->gr_name) ||
        !b2_assoc_nullable_string(array, "passwd", group->gr_passwd) ||
        !jinx_zend_array_add_assoc(
            array, "members", 7u, jinx_zend_array_value(members)
        ) ||
        !jinx_zend_array_add_assoc(
            array, "gid", 3u, jinx_zend_long((int64_t)group->gr_gid)
        )) {
        jinx_zend_array_release(array);
        jinx_zend_array_release(members);
        return jinx_oracle_zero_value();
    }
    jinx_zend_array_release(members);
    return jinx_oracle_zend_array_value_owned(array);
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

static const JinxNativeIniMeta *b2_ini_meta(const char *name) {
    if (name == NULL) return NULL;
    for (size_t i = 0u; i < jinx_native_ini_metadata_count; i++) {
        if (strcmp(name, jinx_native_ini_metadata[i].name) == 0) {
            return &jinx_native_ini_metadata[i];
        }
    }
    return NULL;
}

static JinxOracleBatch2IniOverride *b2_ini_override(const char *name) {
    if (name == NULL) return NULL;
    for (size_t i = 0u; i < jinx_oracle_batch2_ini_override_count; i++) {
        if (strcmp(name, jinx_oracle_batch2_ini_overrides[i].name) == 0) {
            return &jinx_oracle_batch2_ini_overrides[i];
        }
    }
    return NULL;
}

static const char *b2_ini_current(const JinxNativeIniMeta *meta) {
    JinxOracleBatch2IniOverride *override;
    if (meta == NULL) return NULL;
    override = b2_ini_override(meta->name);
    return override != NULL ? override->value : meta->local_value;
}

static int b2_ini_store_override(const char *name, const char *value) {
    JinxOracleBatch2IniOverride *override;
    char *copy;
    if (name == NULL || value == NULL) return 0;
    override = b2_ini_override(name);
    copy = strdup(value);
    if (copy == NULL) return 0;
    if (override != NULL) {
        free(override->value);
        override->value = copy;
        return 1;
    }
    if (jinx_oracle_batch2_ini_override_count ==
        jinx_oracle_batch2_ini_override_capacity) {
        size_t new_capacity = jinx_oracle_batch2_ini_override_capacity == 0u
            ? 8u
            : jinx_oracle_batch2_ini_override_capacity * 2u;
        JinxOracleBatch2IniOverride *grown = (JinxOracleBatch2IniOverride *)realloc(
            jinx_oracle_batch2_ini_overrides,
            new_capacity * sizeof(*grown)
        );
        if (grown == NULL) {
            free(copy);
            return 0;
        }
        jinx_oracle_batch2_ini_overrides = grown;
        jinx_oracle_batch2_ini_override_capacity = new_capacity;
    }
    override = &jinx_oracle_batch2_ini_overrides[
        jinx_oracle_batch2_ini_override_count++
    ];
    override->name = strdup(name);
    override->value = copy;
    if (override->name == NULL) {
        free(copy);
        jinx_oracle_batch2_ini_override_count--;
        return 0;
    }
    return 1;
}

static void b2_ini_clear_override(const char *name) {
    if (name == NULL) return;
    for (size_t i = 0u; i < jinx_oracle_batch2_ini_override_count; i++) {
        if (strcmp(name, jinx_oracle_batch2_ini_overrides[i].name) == 0) {
            free(jinx_oracle_batch2_ini_overrides[i].name);
            free(jinx_oracle_batch2_ini_overrides[i].value);
            if (i + 1u < jinx_oracle_batch2_ini_override_count) {
                memmove(
                    &jinx_oracle_batch2_ini_overrides[i],
                    &jinx_oracle_batch2_ini_overrides[i + 1u],
                    (jinx_oracle_batch2_ini_override_count - i - 1u)
                        * sizeof(*jinx_oracle_batch2_ini_overrides)
                );
            }
            jinx_oracle_batch2_ini_override_count--;
            return;
        }
    }
}

static char *b2_ini_value_string(JinxValue value) {
    JinxValue string_value;
    if (value.type == 3u) return b2_dup(value);
    if (value.type == 0u) return strdup("");
    string_value = jinx_oracle_strval_value(value);
    if (string_value.type != 3u) return NULL;
    return b2_dup(string_value);
}

static JinxValue b2_ini_set_value(
    const char *name,
    JinxValue new_value,
    int *ok
) {
    const JinxNativeIniMeta *meta = b2_ini_meta(name);
    const char *old_value;
    char *replacement;
    JinxValue result = jinx_oracle_bool_value(0);
    if (ok != NULL) *ok = 0;
    if (meta == NULL || (meta->access & 1) == 0) {
        if (ok != NULL) *ok = 1;
        return result;
    }
    old_value = b2_ini_current(meta);
    replacement = b2_ini_value_string(new_value);
    if (replacement == NULL) return result;
    if (!b2_ini_store_override(name, replacement)) {
        free(replacement);
        return result;
    }
    free(replacement);
    if (ok != NULL) *ok = 1;
    return old_value != NULL
        ? b2_copy(old_value, strlen(old_value))
        : jinx_oracle_bool_value(0);
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

static const JinxNativeConstantMeta *b2_constant_meta(const char *name) {
    if (name == NULL) return NULL;
    for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
        if (strcmp(name, jinx_native_constant_metadata[i].name) == 0) {
            return &jinx_native_constant_metadata[i];
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
    if (!jinx_oracle_constant_registry_append_to_array(array)) {
        jinx_zend_array_release(array);
        return jinx_oracle_zero_value();
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

    {
        int ftp_handled = 0;
        JinxValue ftp_result = jinx_oracle_ftp_builtin_with_context(
            ctx, name, args, argc, &ftp_handled
        );
        if (ftp_handled) {
            if (handled != NULL) *handled = 1;
            return ftp_result;
        }
    }

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

    if (strcmp(name, "stream_get_wrappers") == 0) {
        if (argc != 0u) return result;
        result = b2_string_list(
            jinx_native_stream_wrappers,
            jinx_native_stream_wrappers_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "stream_get_transports") == 0) {
        if (argc != 0u) return result;
        result = b2_string_list(
            jinx_native_stream_transports,
            jinx_native_stream_transports_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "stream_get_filters") == 0) {
        if (argc != 0u) return result;
        result = b2_string_list(
            jinx_native_stream_filters,
            jinx_native_stream_filters_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "password_algos") == 0) {
        if (argc != 0u) return result;
        result = b2_string_list(
            jinx_native_password_algos,
            jinx_native_password_algos_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "timezone_identifiers_list") == 0) {
        if (argc != 0u) return result;
        result = b2_string_list(
            jinx_native_timezone_identifiers,
            jinx_native_timezone_identifiers_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "timezone_version_get") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return b2_copy(
            JINX_NATIVE_TIMEZONE_VERSION,
            strlen(JINX_NATIVE_TIMEZONE_VERSION)
        );
    }

    if (strcmp(name, "spl_classes") == 0) {
        JinxZendArray *array;
        if (argc != 0u) return result;
        array = jinx_zend_array_new_packed(
            jinx_native_spl_classes_count == 0u
                ? 1u
                : jinx_native_spl_classes_count
        );
        if (array == NULL) return result;
        for (size_t i = 0u; i < jinx_native_spl_classes_count; i++) {
            if (!b2_assoc_string(
                    array,
                    jinx_native_spl_classes[i].name,
                    jinx_native_spl_classes[i].value
                )) {
                jinx_zend_array_release(array);
                return result;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "hash_algos") == 0) {
        if (argc != 0u) return result;
        result = b2_string_list(
            jinx_native_hash_algos,
            jinx_native_hash_algos_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "hash_hmac_algos") == 0) {
        if (argc != 0u) return result;
        result = b2_string_list(
            jinx_native_hash_hmac_algos,
            jinx_native_hash_hmac_algos_count
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "phpversion") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return b2_copy(
            JINX_NATIVE_PHP_VERSION,
            strlen(JINX_NATIVE_PHP_VERSION)
        );
    }

    if (strcmp(name, "zend_version") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return b2_copy(
            JINX_NATIVE_ZEND_VERSION,
            strlen(JINX_NATIVE_ZEND_VERSION)
        );
    }

    if (strcmp(name, "sleep") == 0) {
        int64_t seconds;
        if (args == NULL || argc != 1u) return result;
        seconds = jinx_oracle_intish(args[0]);
        if (seconds < 0 || (uint64_t)seconds > UINT_MAX) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)sleep((unsigned int)seconds));
    }

    if (strcmp(name, "usleep") == 0) {
        int64_t microseconds;
        struct timespec request;
        struct timespec remaining;
        if (args == NULL || argc != 1u) return result;
        microseconds = jinx_oracle_intish(args[0]);
        if (microseconds < 0) return result;
        request.tv_sec = (time_t)(microseconds / 1000000);
        request.tv_nsec = (long)((microseconds % 1000000) * 1000);
        while (nanosleep(&request, &remaining) != 0) {
            if (errno != EINTR) return result;
            request = remaining;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "time_nanosleep") == 0) {
        int64_t seconds;
        int64_t nanoseconds;
        struct timespec request;
        struct timespec remaining;
        if (args == NULL || argc != 2u) return result;
        seconds = jinx_oracle_intish(args[0]);
        nanoseconds = jinx_oracle_intish(args[1]);
        if (seconds < 0 || nanoseconds < 0 || nanoseconds > 999999999) {
            return result;
        }
        request.tv_sec = (time_t)seconds;
        request.tv_nsec = (long)nanoseconds;
        if (nanosleep(&request, &remaining) == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(1);
        }
        if (errno == EINTR) {
            JinxZendArray *array = jinx_zend_array_new_packed(2u);
            if (array == NULL) return result;
            if (!jinx_zend_array_add_assoc(
                    array, "seconds", 7u,
                    jinx_zend_long((int64_t)remaining.tv_sec)
                ) ||
                !jinx_zend_array_add_assoc(
                    array, "nanoseconds", 11u,
                    jinx_zend_long((int64_t)remaining.tv_nsec)
                )) {
                jinx_zend_array_release(array);
                return result;
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(array);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "time_sleep_until") == 0) {
        double target;
        struct timeval now;
        uint64_t current_ns;
        uint64_t target_ns;
        uint64_t diff_ns;
        const uint64_t ns_per_sec = 1000000000ULL;
        const double top_target = (double)(UINT64_MAX / ns_per_sec);
        struct timespec request;
        struct timespec remaining;
        if (args == NULL || argc != 1u) return result;
        target = jinx_oracle_floatish(args[0]);
        if (!(target >= 0.0 && target <= top_target)) return result;
        if (gettimeofday(&now, NULL) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        target_ns = (uint64_t)(target * (double)ns_per_sec);
        current_ns = (uint64_t)now.tv_sec * ns_per_sec +
            (uint64_t)now.tv_usec * 1000ULL;
        if (target_ns < current_ns) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        diff_ns = target_ns - current_ns;
        request.tv_sec = (time_t)(diff_ns / ns_per_sec);
        request.tv_nsec = (long)(diff_ns % ns_per_sec);
        while (nanosleep(&request, &remaining) != 0) {
            if (errno != EINTR) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            request = remaining;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
    }

    if (strcmp(name, "sys_get_temp_dir") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return b2_copy(
            JINX_NATIVE_PHP_SYS_TEMP_DIR,
            strlen(JINX_NATIVE_PHP_SYS_TEMP_DIR)
        );
    }

    if (strcmp(name, "sys_getloadavg") == 0) {
        double load[3];
        JinxZendArray *array;
        if (argc != 0u) return result;
        if (getloadavg(load, 3) == -1) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        array = jinx_zend_array_new_packed(3u);
        if (array == NULL) return result;
        for (size_t i = 0u; i < 3u; i++) {
            if (!jinx_zend_array_append(array, jinx_zend_double(load[i]))) {
                jinx_zend_array_release(array);
                return result;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "php_sapi_name") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return b2_copy(
            JINX_NATIVE_PHP_SAPI_NAME,
            strlen(JINX_NATIVE_PHP_SAPI_NAME)
        );
    }

    if (strcmp(name, "php_ini_loaded_file") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return JINX_NATIVE_PHP_INI_LOADED_FILE_AVAILABLE
            ? b2_copy(
                JINX_NATIVE_PHP_INI_LOADED_FILE,
                strlen(JINX_NATIVE_PHP_INI_LOADED_FILE)
            )
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "php_ini_scanned_files") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return JINX_NATIVE_PHP_INI_SCANNED_FILES_AVAILABLE
            ? b2_copy(
                JINX_NATIVE_PHP_INI_SCANNED_FILES,
                strlen(JINX_NATIVE_PHP_INI_SCANNED_FILES)
            )
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "php_uname") == 0) {
        struct utsname info;
        char mode = 'a';
        if (argc >= 1u) {
            if (args == NULL || args[0].type != 3u ||
                jinx_oracle_string_len(args[0]) != 1u) return result;
            mode = (char)jinx_oracle_string_bytes(args[0])[0];
            if (mode != 'a' && mode != 'm' && mode != 'n' &&
                mode != 'r' && mode != 's' && mode != 'v') return result;
        }
        if (uname(&info) != 0) return result;
        if (handled != NULL) *handled = 1;
        if (mode == 's') return b2_copy(info.sysname, strlen(info.sysname));
        if (mode == 'n') return b2_copy(info.nodename, strlen(info.nodename));
        if (mode == 'r') return b2_copy(info.release, strlen(info.release));
        if (mode == 'v') return b2_copy(info.version, strlen(info.version));
        if (mode == 'm') return b2_copy(info.machine, strlen(info.machine));
        {
            char buffer[
                sizeof(info.sysname) + sizeof(info.nodename) +
                sizeof(info.release) + sizeof(info.version) +
                sizeof(info.machine) + 8u
            ];
            int written = snprintf(
                buffer,
                sizeof(buffer),
                "%s %s %s %s %s",
                info.sysname,
                info.nodename,
                info.release,
                info.version,
                info.machine
            );
            if (written < 0 || (size_t)written >= sizeof(buffer)) return result;
            return b2_copy(buffer, (size_t)written);
        }
    }

#ifdef JINX_HAVE_ICONV
    if (strcmp(name, "iconv_get_encoding") == 0) {
        const char *type = "all";
        char *type_owned = NULL;

        if (argc >= 1u && args != NULL && args[0].type != 0u) {
            if (args[0].type != 3u) return result;
            type_owned = b2_dup(args[0]);
            if (type_owned == NULL) return result;
            type = type_owned;
        }

        if (strcmp(type, "all") == 0) {
            JinxZendArray *array = jinx_zend_array_new_packed(3u);
            if (array == NULL ||
                !b2_assoc_string(array, "input_encoding", jinx_oracle_batch2_iconv_input_encoding) ||
                !b2_assoc_string(array, "output_encoding", jinx_oracle_batch2_iconv_output_encoding) ||
                !b2_assoc_string(array, "internal_encoding", jinx_oracle_batch2_iconv_internal_encoding)) {
                jinx_zend_array_release(array);
                free(type_owned);
                return result;
            }
            free(type_owned);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(array);
        }

        if (strcmp(type, "input_encoding") == 0) {
            result = b2_copy(
                jinx_oracle_batch2_iconv_input_encoding,
                strlen(jinx_oracle_batch2_iconv_input_encoding)
            );
        } else if (strcmp(type, "output_encoding") == 0) {
            result = b2_copy(
                jinx_oracle_batch2_iconv_output_encoding,
                strlen(jinx_oracle_batch2_iconv_output_encoding)
            );
        } else if (strcmp(type, "internal_encoding") == 0) {
            result = b2_copy(
                jinx_oracle_batch2_iconv_internal_encoding,
                strlen(jinx_oracle_batch2_iconv_internal_encoding)
            );
        } else {
            result = jinx_oracle_bool_value(0);
        }

        free(type_owned);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "iconv_set_encoding") == 0) {
        char *type;
        char *encoding;
        char *target = NULL;
        size_t target_size = 0u;

        if (args == NULL || argc != 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;

        type = b2_dup(args[0]);
        encoding = b2_dup(args[1]);
        if (type == NULL || encoding == NULL) {
            free(type);
            free(encoding);
            return result;
        }

        if (strcmp(type, "input_encoding") == 0) {
            target = jinx_oracle_batch2_iconv_input_encoding;
            target_size = sizeof(jinx_oracle_batch2_iconv_input_encoding);
        } else if (strcmp(type, "output_encoding") == 0) {
            target = jinx_oracle_batch2_iconv_output_encoding;
            target_size = sizeof(jinx_oracle_batch2_iconv_output_encoding);
        } else if (strcmp(type, "internal_encoding") == 0) {
            target = jinx_oracle_batch2_iconv_internal_encoding;
            target_size = sizeof(jinx_oracle_batch2_iconv_internal_encoding);
        }

        if (target == NULL || !b2_iconv_encoding_supported(encoding) ||
            strlen(encoding) >= target_size) {
            free(type);
            free(encoding);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        memcpy(target, encoding, strlen(encoding) + 1u);
        free(type);
        free(encoding);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
    }

    if (strcmp(name, "iconv") == 0) {
        char *from_encoding;
        char *to_encoding;
        unsigned char *converted = NULL;
        size_t converted_len = 0u;

        if (args == NULL || argc != 3u ||
            args[0].type != 3u || args[1].type != 3u || args[2].type != 3u) {
            return result;
        }

        from_encoding = b2_dup(args[0]);
        to_encoding = b2_dup(args[1]);
        if (from_encoding == NULL || to_encoding == NULL) {
            free(from_encoding);
            free(to_encoding);
            return result;
        }

        if (!b2_iconv_convert(
                from_encoding,
                to_encoding,
                jinx_oracle_string_bytes(args[2]),
                jinx_oracle_string_len(args[2]),
                &converted,
                &converted_len)) {
            free(from_encoding);
            free(to_encoding);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        free(from_encoding);
        free(to_encoding);
        result = b2_copy((const char *)converted, converted_len);
        free(converted);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "iconv_strlen") == 0) {
        char *encoding = NULL;
        unsigned char *utf32 = NULL;
        size_t utf32_len = 0u;

        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc >= 2u && args[1].type != 0u) {
            if (args[1].type != 3u) return result;
            encoding = b2_dup(args[1]);
            if (encoding == NULL) return result;
        } else {
            encoding = strdup(jinx_oracle_batch2_iconv_internal_encoding);
            if (encoding == NULL) return result;
        }

        if (!b2_iconv_utf32(args[0], encoding, &utf32, &utf32_len) ||
            (utf32_len % 4u) != 0u) {
            free(encoding);
            free(utf32);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        free(encoding);
        free(utf32);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)(utf32_len / 4u));
    }

    if (strcmp(name, "iconv_substr") == 0) {
        char *encoding = NULL;
        unsigned char *utf32 = NULL;
        size_t utf32_len = 0u;
        size_t units;
        int64_t start;
        int64_t length;
        size_t take;
        unsigned char *converted = NULL;
        size_t converted_len = 0u;

        if (args == NULL || argc < 2u ||
            args[0].type != 3u) return result;

        if (argc >= 4u && args[3].type != 0u) {
            if (args[3].type != 3u) return result;
            encoding = b2_dup(args[3]);
        } else {
            encoding = strdup(jinx_oracle_batch2_iconv_internal_encoding);
        }
        if (encoding == NULL) return result;

        if (!b2_iconv_utf32(args[0], encoding, &utf32, &utf32_len) ||
            (utf32_len % 4u) != 0u) {
            free(encoding);
            free(utf32);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        units = utf32_len / 4u;
        start = b2_iconv_normalize_offset(jinx_oracle_intish(args[1]), units);
        if (start < 0 || (uint64_t)start > units) {
            free(encoding);
            free(utf32);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (argc < 3u || args[2].type == 0u) {
            take = units - (size_t)start;
        } else {
            length = jinx_oracle_intish(args[2]);
            if (length >= 0) {
                take = (size_t)length;
                if (take > units - (size_t)start) take = units - (size_t)start;
            } else {
                int64_t end = (int64_t)units + length;
                if (end < start) take = 0u;
                else take = (size_t)(end - start);
            }
        }

        if (!b2_iconv_convert(
                "UTF-32LE",
                encoding,
                utf32 + ((size_t)start * 4u),
                take * 4u,
                &converted,
                &converted_len)) {
            free(encoding);
            free(utf32);
            free(converted);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        result = b2_copy((const char *)converted, converted_len);
        free(converted);
        free(utf32);
        free(encoding);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "iconv_strpos") == 0 ||
        strcmp(name, "iconv_strrpos") == 0) {
        char *encoding = NULL;
        unsigned char *haystack = NULL;
        unsigned char *needle = NULL;
        size_t haystack_len = 0u;
        size_t needle_len = 0u;
        size_t hay_units;
        size_t needle_units;
        int64_t offset = 0;
        int64_t found = -1;

        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;

        if (strcmp(name, "iconv_strpos") == 0) {
            if (argc >= 4u && args[3].type != 0u) {
                if (args[3].type != 3u) return result;
                encoding = b2_dup(args[3]);
            } else {
                encoding = strdup(jinx_oracle_batch2_iconv_internal_encoding);
            }
            if (argc >= 3u) offset = jinx_oracle_intish(args[2]);
        } else {
            if (argc >= 3u && args[2].type != 0u) {
                if (args[2].type != 3u) return result;
                encoding = b2_dup(args[2]);
            } else {
                encoding = strdup(jinx_oracle_batch2_iconv_internal_encoding);
            }
            offset = 0;
        }
        if (encoding == NULL) return result;

        if (!b2_iconv_utf32(args[0], encoding, &haystack, &haystack_len) ||
            !b2_iconv_utf32(args[1], encoding, &needle, &needle_len) ||
            (haystack_len % 4u) != 0u || (needle_len % 4u) != 0u) {
            free(encoding);
            free(haystack);
            free(needle);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        hay_units = haystack_len / 4u;
        needle_units = needle_len / 4u;
        offset = b2_iconv_normalize_offset(offset, hay_units);

        if (offset >= 0 && (uint64_t)offset <= hay_units) {
            if (needle_units == 0u) {
                found = offset;
            } else if (needle_units <= hay_units) {
                if (strcmp(name, "iconv_strpos") == 0) {
                    for (size_t i = (size_t)offset; i + needle_units <= hay_units; i++) {
                        if (memcmp(haystack + i * 4u, needle, needle_units * 4u) == 0) {
                            found = (int64_t)i;
                            break;
                        }
                    }
                } else {
                    size_t last = hay_units - needle_units;
                    if ((size_t)offset > last) offset = (int64_t)last;
                    for (size_t i = last + 1u; i-- > (size_t)offset;) {
                        if (memcmp(haystack + i * 4u, needle, needle_units * 4u) == 0) {
                            found = (int64_t)i;
                            break;
                        }
                        if (i == 0u) break;
                    }
                }
            }
        }

        free(encoding);
        free(haystack);
        free(needle);
        if (handled != NULL) *handled = 1;
        return found >= 0 ? jinx_oracle_int_value(found) : jinx_oracle_bool_value(0);
    }
#endif

    if (strcmp(name, "zlib_get_coding_type") == 0) {
        /*
         * Native Jinx does not install a transparent output-compression
         * handler. PHP returns false when no zlib output coding is active.
         */
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "random_bytes") == 0) {
        int64_t length;
        char *bytes;
        if (args == NULL || argc < 1u) return result;
        length = jinx_oracle_intish(args[0]);
        if (length <= 0 || (uint64_t)length > UINT32_MAX) return result;
        bytes = jinx_oracle_scratch_string((uint32_t)length);
        if (!b2_random_fill((unsigned char *)bytes, (size_t)length)) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value_len(bytes, (uint32_t)length);
    }

    if (strcmp(name, "random_int") == 0) {
        int64_t minimum;
        int64_t maximum;
        uint64_t span;
        uint64_t sample;
        uint64_t selected;
        int64_t output;
        if (args == NULL || argc < 2u) return result;
        minimum = jinx_oracle_intish(args[0]);
        maximum = jinx_oracle_intish(args[1]);
        if (minimum > maximum) return result;

        span = (uint64_t)maximum - (uint64_t)minimum + 1u;
        if (span == 0u) {
            if (!b2_random_fill((unsigned char *)&sample, sizeof(sample))) return result;
            memcpy(&output, &sample, sizeof(output));
        } else {
            uint64_t cutoff = UINT64_MAX - (UINT64_MAX % span);
            do {
                if (!b2_random_fill((unsigned char *)&sample, sizeof(sample))) return result;
            } while (sample >= cutoff);
            selected = (uint64_t)minimum + (sample % span);
            memcpy(&output, &selected, sizeof(output));
        }

        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(output);
    }

    if (strcmp(name, "lcg_value") == 0) {
        uint64_t sample;
        double value;
        if (!b2_random_fill((unsigned char *)&sample, sizeof(sample))) return result;
        value = ((double)(sample >> 11) + 0.5) / 9007199254740992.0;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_float_value(value);
    }

    if (strcmp(name, "str_shuffle") == 0) {
        const unsigned char *input;
        uint32_t len;
        unsigned char *buffer;

        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        input = jinx_oracle_string_bytes(args[0]);
        len = jinx_oracle_string_len(args[0]);

        buffer = (unsigned char *)malloc((size_t)len + 1u);
        if (buffer == NULL) return result;
        if (len != 0u) memcpy(buffer, input, len);

        for (uint32_t i = len; i > 1u; i--) {
            uint64_t sample;
            uint32_t j;
            if (!b2_random_fill((unsigned char *)&sample, sizeof(sample))) {
                free(buffer);
                return result;
            }
            j = (uint32_t)(sample % i);
            {
                unsigned char tmp = buffer[i - 1u];
                buffer[i - 1u] = buffer[j];
                buffer[j] = tmp;
            }
        }

        result = b2_copy((const char *)buffer, len);
        free(buffer);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "pcntl_strerror") == 0 ||
        strcmp(name, "socket_strerror") == 0) {
        int error_code;
        const char *message;
        if (args == NULL || argc != 1u) return result;
        error_code = (int)jinx_oracle_intish(args[0]);
        message = strerror(error_code);
        if (message == NULL) message = "Unknown error";
        if (handled != NULL) *handled = 1;
        return b2_copy(message, strlen(message));
    }

    if (strcmp(name, "posix_getpid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getpid());
    }
    if (strcmp(name, "posix_getppid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getppid());
    }
    if (strcmp(name, "posix_getuid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getuid());
    }
    if (strcmp(name, "posix_getgid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getgid());
    }
    if (strcmp(name, "posix_geteuid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)geteuid());
    }
    if (strcmp(name, "posix_getegid") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getegid());
    }
    if (strcmp(name, "posix_getpgrp") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)getpgrp());
    }
    if (strcmp(name, "posix_getpgid") == 0) {
        pid_t pgid;
        if (args == NULL || argc < 1u) return result;
        pgid = getpgid((pid_t)jinx_oracle_intish(args[0]));
        if (pgid < 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)pgid);
    }
    if (strcmp(name, "posix_getsid") == 0) {
        pid_t sid;
        if (args == NULL || argc < 1u) return result;
        sid = getsid((pid_t)jinx_oracle_intish(args[0]));
        if (sid < 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)sid);
    }
    if (strcmp(name, "posix_getcwd") == 0) {
        char buffer[PATH_MAX];
        if (getcwd(buffer, sizeof(buffer)) == NULL) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return b2_copy(buffer, strlen(buffer));
    }
    if (strcmp(name, "posix_getlogin") == 0) {
        const char *login = getlogin();
        if (handled != NULL) *handled = 1;
        if (login == NULL) {
            jinx_oracle_batch2_posix_last_error = errno;
            return jinx_oracle_bool_value(0);
        }
        return b2_copy(login, strlen(login));
    }
    if (strcmp(name, "posix_getgroups") == 0) {
        int count = getgroups(0, NULL);
        gid_t *groups;
        JinxZendArray *array;
        if (count < 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        groups = count == 0 ? NULL : (gid_t *)malloc(sizeof(gid_t) * (size_t)count);
        if (count != 0 && groups == NULL) return result;
        if (count != 0 && getgroups(count, groups) < 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            free(groups);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        array = jinx_zend_array_new_packed(count == 0 ? 1u : (size_t)count);
        if (array == NULL) {
            free(groups);
            return result;
        }
        for (int i = 0; i < count; i++) {
            if (!jinx_zend_array_append(array, jinx_zend_long((int64_t)groups[i]))) {
                free(groups);
                jinx_zend_array_release(array);
                return result;
            }
        }
        free(groups);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }
    if (strcmp(name, "posix_getpwnam") == 0 ||
        strcmp(name, "posix_getpwuid") == 0) {
        struct passwd *pw = NULL;
        if (args == NULL || argc < 1u) return result;
        errno = 0;
        if (strcmp(name, "posix_getpwnam") == 0) {
            char *user;
            if (args[0].type != 3u) return result;
            user = b2_dup(args[0]);
            if (user == NULL) return result;
            pw = getpwnam(user);
            free(user);
        } else {
            pw = getpwuid((uid_t)jinx_oracle_intish(args[0]));
        }
        if (pw == NULL) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return b2_posix_passwd_value(pw);
    }
    if (strcmp(name, "posix_getgrnam") == 0 ||
        strcmp(name, "posix_getgrgid") == 0) {
        struct group *group = NULL;
        if (args == NULL || argc < 1u) return result;
        errno = 0;
        if (strcmp(name, "posix_getgrnam") == 0) {
            char *group_name;
            if (args[0].type != 3u) return result;
            group_name = b2_dup(args[0]);
            if (group_name == NULL) return result;
            group = getgrnam(group_name);
            free(group_name);
        } else {
            group = getgrgid((gid_t)jinx_oracle_intish(args[0]));
        }
        if (group == NULL) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return b2_posix_group_value(group);
    }
    if (strcmp(name, "posix_strerror") == 0) {
        const char *message;
        if (args == NULL || argc < 1u) return result;
        message = strerror((int)jinx_oracle_intish(args[0]));
        if (handled != NULL) *handled = 1;
        return b2_copy(message != NULL ? message : "", message != NULL ? strlen(message) : 0u);
    }
    if (strcmp(name, "pcntl_wifexited") == 0 ||
        strcmp(name, "pcntl_wifstopped") == 0 ||
        strcmp(name, "pcntl_wifsignaled") == 0 ||
        strcmp(name, "pcntl_wifcontinued") == 0 ||
        strcmp(name, "pcntl_wexitstatus") == 0 ||
        strcmp(name, "pcntl_wtermsig") == 0 ||
        strcmp(name, "pcntl_wstopsig") == 0) {
        int status_word;
        if (args == NULL || argc != 1u) return result;
        status_word = (int)jinx_oracle_intish(args[0]);

        if (strcmp(name, "pcntl_wifexited") == 0) {
#ifdef WIFEXITED
            result = jinx_oracle_bool_value(WIFEXITED(status_word) != 0);
#else
            result = jinx_oracle_bool_value(0);
#endif
        } else if (strcmp(name, "pcntl_wifstopped") == 0) {
#ifdef WIFSTOPPED
            result = jinx_oracle_bool_value(WIFSTOPPED(status_word) != 0);
#else
            result = jinx_oracle_bool_value(0);
#endif
        } else if (strcmp(name, "pcntl_wifsignaled") == 0) {
#ifdef WIFSIGNALED
            result = jinx_oracle_bool_value(WIFSIGNALED(status_word) != 0);
#else
            result = jinx_oracle_bool_value(0);
#endif
        } else if (strcmp(name, "pcntl_wifcontinued") == 0) {
#ifdef WIFCONTINUED
            result = jinx_oracle_bool_value(WIFCONTINUED(status_word) != 0);
#else
            result = jinx_oracle_bool_value(0);
#endif
        } else if (strcmp(name, "pcntl_wexitstatus") == 0) {
#ifdef WEXITSTATUS
            result = jinx_oracle_int_value((int64_t)WEXITSTATUS(status_word));
#else
            result = jinx_oracle_bool_value(0);
#endif
        } else if (strcmp(name, "pcntl_wtermsig") == 0) {
#ifdef WTERMSIG
            result = jinx_oracle_int_value((int64_t)WTERMSIG(status_word));
#else
            result = jinx_oracle_bool_value(0);
#endif
        } else {
#ifdef WSTOPSIG
            result = jinx_oracle_int_value((int64_t)WSTOPSIG(status_word));
#else
            result = jinx_oracle_bool_value(0);
#endif
        }

        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "posix_eaccess") == 0) {
        char *path;
        int mode = 0;
        int rc;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL || path[0] == '\0') {
            free(path);
            return result;
        }
        if (argc >= 2u) mode = (int)jinx_oracle_intish(args[1]);
#if defined(__GLIBC__) || defined(__linux__)
        rc = eaccess(path, mode);
#else
        rc = access(path, mode);
#endif
        if (rc != 0) jinx_oracle_batch2_posix_last_error = errno;
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }
    if (strcmp(name, "posix_kill") == 0) {
        int rc;
        if (args == NULL || argc != 2u) return result;
        rc = kill(
            (pid_t)jinx_oracle_intish(args[0]),
            (int)jinx_oracle_intish(args[1])
        );
        if (rc != 0) jinx_oracle_batch2_posix_last_error = errno;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }
    if (strcmp(name, "posix_mkfifo") == 0) {
        char *path;
        int rc;
        if (args == NULL || argc != 2u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        rc = mkfifo(path, (mode_t)jinx_oracle_intish(args[1]));
        if (rc != 0) jinx_oracle_batch2_posix_last_error = errno;
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }
    if (strcmp(name, "posix_setuid") == 0 ||
        strcmp(name, "posix_setgid") == 0 ||
        strcmp(name, "posix_seteuid") == 0 ||
        strcmp(name, "posix_setegid") == 0) {
        int rc;
        if (args == NULL || argc != 1u) return result;
        if (strcmp(name, "posix_setuid") == 0) {
            rc = setuid((uid_t)jinx_oracle_intish(args[0]));
        } else if (strcmp(name, "posix_setgid") == 0) {
            rc = setgid((gid_t)jinx_oracle_intish(args[0]));
        } else if (strcmp(name, "posix_seteuid") == 0) {
            rc = seteuid((uid_t)jinx_oracle_intish(args[0]));
        } else {
            rc = setegid((gid_t)jinx_oracle_intish(args[0]));
        }
        if (rc != 0) jinx_oracle_batch2_posix_last_error = errno;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }
    if (strcmp(name, "posix_setpgid") == 0) {
        int rc;
        if (args == NULL || argc != 2u) return result;
        rc = setpgid(
            (pid_t)jinx_oracle_intish(args[0]),
            (pid_t)jinx_oracle_intish(args[1])
        );
        if (rc != 0) jinx_oracle_batch2_posix_last_error = errno;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }
    if (strcmp(name, "posix_setsid") == 0) {
        pid_t sid;
        if (argc != 0u) return result;
        sid = setsid();
        if (sid < 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)sid);
    }
    if (strcmp(name, "posix_getrlimit") == 0) {
        struct rlimit rl;
        int resource;
        JinxZendArray *array;
        if (args == NULL || argc != 1u || args[0].type == 0u) return result;
        resource = (int)jinx_oracle_intish(args[0]);
        if (getrlimit(resource, &rl) != 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        array = jinx_zend_array_new_packed(2u);
        if (array == NULL) return result;
        if (rl.rlim_cur == RLIM_INFINITY) {
            if (!b2_append_string(array, "unlimited")) {
                jinx_zend_array_release(array);
                return result;
            }
        } else if (!jinx_zend_array_append(array, jinx_zend_long((int64_t)rl.rlim_cur))) {
            jinx_zend_array_release(array);
            return result;
        }
        if (rl.rlim_max == RLIM_INFINITY) {
            if (!b2_append_string(array, "unlimited")) {
                jinx_zend_array_release(array);
                return result;
            }
        } else if (!jinx_zend_array_append(array, jinx_zend_long((int64_t)rl.rlim_max))) {
            jinx_zend_array_release(array);
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }
    if (strcmp(name, "posix_setrlimit") == 0) {
        struct rlimit rl;
        int rc;
        if (args == NULL || argc != 3u) return result;
        rl.rlim_cur = (rlim_t)jinx_oracle_intish(args[1]);
        rl.rlim_max = (rlim_t)jinx_oracle_intish(args[2]);
        rc = setrlimit((int)jinx_oracle_intish(args[0]), &rl);
        if (rc != 0) jinx_oracle_batch2_posix_last_error = errno;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }
    if (strcmp(name, "posix_access") == 0) {
        char *path;
        int mode = 0;
        int rc;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        if (argc >= 2u) mode = (int)jinx_oracle_intish(args[1]);
        rc = access(path, mode);
        if (rc != 0) jinx_oracle_batch2_posix_last_error = errno;
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
    }
    if (strcmp(name, "posix_get_last_error") == 0 ||
        strcmp(name, "posix_errno") == 0) {
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)jinx_oracle_batch2_posix_last_error);
    }
    if (strcmp(name, "posix_ctermid") == 0) {
        char buffer[L_ctermid];
        char *terminal = ctermid(buffer);
        if (handled != NULL) *handled = 1;
        if (terminal == NULL) {
            jinx_oracle_batch2_posix_last_error = errno;
            return jinx_oracle_bool_value(0);
        }
        return b2_copy(terminal, strlen(terminal));
    }
    if (strcmp(name, "posix_sysconf") == 0) {
        long value;
        if (args == NULL || argc < 1u) return result;
        value = sysconf((int)jinx_oracle_intish(args[0]));
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)value);
    }
    if (strcmp(name, "posix_pathconf") == 0) {
        char *path;
        long value;
        if (args == NULL || argc < 2u || args[0].type != 3u) return result;
        path = b2_dup(args[0]);
        if (path == NULL || path[0] == '\0') {
            free(path);
            return result;
        }
        errno = 0;
        value = pathconf(path, (int)jinx_oracle_intish(args[1]));
        if (value < 0 && errno != 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            free(path);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)value);
    }
    if (strcmp(name, "posix_fpathconf") == 0) {
        long value;
        int fd;
        if (args == NULL || argc < 2u || args[0].type != 1u) return result;
        fd = (int)jinx_oracle_intish(args[0]);
        errno = 0;
        value = fpathconf(fd, (int)jinx_oracle_intish(args[1]));
        if (value < 0 && errno != 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)value);
    }
    if (strcmp(name, "posix_isatty") == 0) {
        int fd;
        if (args == NULL || argc < 1u || args[0].type != 1u) return result;
        fd = (int)jinx_oracle_intish(args[0]);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(isatty(fd) == 1);
    }
    if (strcmp(name, "posix_ttyname") == 0) {
        int fd;
        char *terminal;
        if (args == NULL || argc < 1u || args[0].type != 1u) return result;
        fd = (int)jinx_oracle_intish(args[0]);
        errno = 0;
        terminal = ttyname(fd);
        if (handled != NULL) *handled = 1;
        if (terminal == NULL) {
            jinx_oracle_batch2_posix_last_error = errno;
            return jinx_oracle_bool_value(0);
        }
        return b2_copy(terminal, strlen(terminal));
    }
    if (strcmp(name, "posix_times") == 0) {
        struct tms times_value;
        clock_t ticks = times(&times_value);
        JinxZendArray *array;
        if (ticks == (clock_t)-1) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        array = jinx_zend_array_new_packed(5u);
        if (array == NULL) return result;
        if (!jinx_zend_array_add_assoc(array, "ticks", 5u, jinx_zend_long((int64_t)ticks)) ||
            !jinx_zend_array_add_assoc(array, "utime", 5u, jinx_zend_long((int64_t)times_value.tms_utime)) ||
            !jinx_zend_array_add_assoc(array, "stime", 5u, jinx_zend_long((int64_t)times_value.tms_stime)) ||
            !jinx_zend_array_add_assoc(array, "cutime", 6u, jinx_zend_long((int64_t)times_value.tms_cutime)) ||
            !jinx_zend_array_add_assoc(array, "cstime", 6u, jinx_zend_long((int64_t)times_value.tms_cstime))) {
            jinx_zend_array_release(array);
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }
    if (strcmp(name, "posix_uname") == 0) {
        struct utsname info;
        JinxZendArray *array;
        if (uname(&info) != 0) {
            jinx_oracle_batch2_posix_last_error = errno;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        array = jinx_zend_array_new_packed(
            JINX_NATIVE_POSIX_UNAME_HAS_DOMAINNAME ? 6u : 5u
        );
        if (array == NULL) return result;
        if (!b2_assoc_string(array, "sysname", info.sysname) ||
            !b2_assoc_string(array, "nodename", info.nodename) ||
            !b2_assoc_string(array, "release", info.release) ||
            !b2_assoc_string(array, "version", info.version) ||
            !b2_assoc_string(array, "machine", info.machine)) {
            jinx_zend_array_release(array);
            return result;
        }
        if (JINX_NATIVE_POSIX_UNAME_HAS_DOMAINNAME) {
            char domain[256];
            if (getdomainname(domain, sizeof(domain)) != 0) {
                jinx_zend_array_release(array);
                jinx_oracle_batch2_posix_last_error = errno;
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            domain[sizeof(domain) - 1u] = '\0';
            if (!b2_assoc_string(array, "domainname", domain)) {
                jinx_zend_array_release(array);
                return result;
            }
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "token_name") == 0) {
        int64_t token;
        const char *token_name = "UNKNOWN";

        if (args == NULL || argc != 1u) return result;
        token = jinx_oracle_intish(args[0]);

        for (size_t i = 0u; i < jinx_native_constant_metadata_count; i++) {
            const JinxNativeConstantMeta *meta = &jinx_native_constant_metadata[i];
            if (meta->type == 1u &&
                meta->name != NULL &&
                meta->name[0] == 'T' &&
                meta->name[1] == '_' &&
                meta->i64 == token) {
                token_name = meta->name;
                break;
            }
        }

        if (handled != NULL) *handled = 1;
        return b2_copy(token_name, strlen(token_name));
    }

    if (strcmp(name, "function_exists") == 0 ||
        strcmp(name, "enum_exists") == 0 ||
        strcmp(name, "interface_exists") == 0 ||
        strcmp(name, "trait_exists") == 0 ||
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
        } else if (strcmp(name, "trait_exists") == 0) {
            found = b2_name_in_list(
                query, jinx_native_trait_names, jinx_native_trait_names_count
            );
        } else {
            found = b2_extension(query) != NULL;
        }
        free(query);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(found);
    }

    if (strcmp(name, "pdo_drivers") == 0) {
        result = b2_string_list(
            jinx_native_pdo_drivers,
            jinx_native_pdo_drivers_count
        );
        if (result.type != JINX_ORACLE_VALUE_ZEND_ARRAY) return result;
        if (handled != NULL) *handled = 1;
        return result;
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

    if (strcmp(name, "ngettext") == 0) {
        char *one;
        char *many;
        unsigned long n;
        const char *translated;
        if (args == NULL || argc != 3u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        one = b2_dup(args[0]);
        many = b2_dup(args[1]);
        n = (unsigned long)jinx_oracle_intish(args[2]);
        if (one == NULL || many == NULL) {
            free(one); free(many); return result;
        }
        translated = ngettext(one, many, n);
        if (translated == NULL) translated = n == 1u ? one : many;
        result = b2_copy(translated, strlen(translated));
        free(one); free(many);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "textdomain") == 0) {
        char *domain;
        char *selected;
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        domain = b2_dup(args[0]);
        if (domain == NULL) return result;
        selected = textdomain(domain);
        if (selected != NULL) result = b2_copy(selected, strlen(selected));
        free(domain);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "setlocale") == 0) {
        int category;
        const char *selected = NULL;
        if (args == NULL || argc < 2u) return result;
        category = (int)jinx_oracle_intish(args[0]);
        for (size_t i = 1u; i < argc; i++) {
            char *locale;
            if (args[i].type != 3u) return result;
            locale = b2_dup(args[i]);
            if (locale == NULL) return result;
            selected = setlocale(category, locale);
            free(locale);
            if (selected != NULL) break;
        }
        if (handled != NULL) *handled = 1;
        return selected != NULL ? b2_copy(selected, strlen(selected)) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "nl_langinfo") == 0) {
        const char *value;
        nl_item item;
        if (args == NULL || argc != 1u) return result;
        item = (nl_item)jinx_oracle_intish(args[0]);
        value = nl_langinfo(item);
        if (handled != NULL) *handled = 1;
        return value != NULL
            ? b2_copy(value, strlen(value))
            : jinx_oracle_bool_value(0);
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

    if (strcmp(name, "ignore_user_abort") == 0) {
        const JinxNativeIniMeta *meta = b2_ini_meta("ignore_user_abort");
        const char *current = meta != NULL ? b2_ini_current(meta) : "0";
        int64_t old_value = current != NULL && atoi(current) != 0 ? 1 : 0;
        if (args != NULL && argc >= 1u && args[0].type != 0u) {
            int set_ok = 0;
            JinxValue updated = b2_ini_set_value(
                "ignore_user_abort",
                jinx_oracle_bool_value(jinx_oracle_boolish(args[0])),
                &set_ok
            );
            (void)updated;
            if (!set_ok) return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(old_value);
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

    if (strcmp(name, "jdtounix") == 0) {
        int64_t day;
        if (args == NULL || argc != 1u) return result;
        day = jinx_oracle_intish(args[0]);
        if (day < 2440588LL ||
            day - 2440588LL > INT64_MAX / 86400LL) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((day - 2440588LL) * 86400LL);
    }

    if (strcmp(name, "unixtojd") == 0) {
        int64_t timestamp = args != NULL && argc >= 1u && args[0].type != 0u
            ? jinx_oracle_intish(args[0])
            : (int64_t)time(NULL);
        time_t raw;
        struct tm tmv;
        int64_t sdn;
        if (timestamp < 0) return result;
        raw = (time_t)timestamp;
        if (localtime_r(&raw, &tmv) == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        sdn = b2_gregorian_to_sdn(
            tmv.tm_year + 1900,
            tmv.tm_mon + 1,
            tmv.tm_mday
        );
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(sdn);
    }

    if (strcmp(name, "preg_quote") == 0) {
        const unsigned char *input;
        size_t input_len;
        unsigned char delimiter = 0u;
        size_t extra = 0u;
        char *quoted;
        size_t pos = 0u;

        if (args == NULL || argc < 1u || argc > 2u || args[0].type != 3u) {
            return result;
        }
        if (argc == 2u && args[1].type != 0u) {
            if (args[1].type != 3u) return result;
            if (jinx_oracle_string_len(args[1]) != 0u) {
                delimiter = jinx_oracle_string_bytes(args[1])[0];
            }
        }

        input = jinx_oracle_string_bytes(args[0]);
        input_len = (size_t)jinx_oracle_string_len(args[0]);
        if (input_len == 0u) {
            if (handled != NULL) *handled = 1;
            return b2_copy("", 0u);
        }

        for (size_t i = 0u; i < input_len; i++) {
            unsigned char ch = input[i];
            switch (ch) {
                case '.': case '\\': case '+': case '*': case '?':
                case '[': case '^': case ']': case '$': case '(':
                case ')': case '{': case '}': case '=': case '!':
                case '>': case '<': case '|': case ':': case '-':
                case '#':
                    extra++;
                    break;
                case '\0':
                    extra += 3u;
                    break;
                default:
                    if (ch == delimiter) extra++;
                    break;
            }
        }

        if (input_len > SIZE_MAX - extra) return result;
        quoted = (char *)malloc(input_len + extra + 1u);
        if (quoted == NULL) return result;

        for (size_t i = 0u; i < input_len; i++) {
            unsigned char ch = input[i];
            switch (ch) {
                case '.': case '\\': case '+': case '*': case '?':
                case '[': case '^': case ']': case '$': case '(':
                case ')': case '{': case '}': case '=': case '!':
                case '>': case '<': case '|': case ':': case '-':
                case '#':
                    quoted[pos++] = '\\';
                    quoted[pos++] = (char)ch;
                    break;
                case '\0':
                    quoted[pos++] = '\\';
                    quoted[pos++] = '0';
                    quoted[pos++] = '0';
                    quoted[pos++] = '0';
                    break;
                default:
                    if (ch == delimiter) quoted[pos++] = '\\';
                    quoted[pos++] = (char)ch;
                    break;
            }
        }

        quoted[pos] = '\0';
        result = b2_copy(quoted, pos);
        free(quoted);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "strtok") == 0) {
        const unsigned char *delimiters = NULL;
        size_t delimiter_len = 0u;
        unsigned char table[256] = {0};
        size_t start;
        size_t end;

        if (args == NULL || argc < 1u || argc > 2u || args[0].type != 3u) {
            return result;
        }

        if (argc == 2u && args[1].type != 0u) {
            const unsigned char *source;
            size_t source_len;
            unsigned char *copy;
            if (args[1].type != 3u) return result;
            source = jinx_oracle_string_bytes(args[0]);
            source_len = (size_t)jinx_oracle_string_len(args[0]);
            copy = (unsigned char *)malloc(source_len == 0u ? 1u : source_len);
            if (copy == NULL) return result;
            if (source_len != 0u) memcpy(copy, source, source_len);
            free(jinx_oracle_batch2_strtok_string);
            jinx_oracle_batch2_strtok_string = copy;
            jinx_oracle_batch2_strtok_len = source_len;
            jinx_oracle_batch2_strtok_pos = 0u;
            delimiters = jinx_oracle_string_bytes(args[1]);
            delimiter_len = (size_t)jinx_oracle_string_len(args[1]);
        } else {
            if (jinx_oracle_batch2_strtok_string == NULL) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            delimiters = jinx_oracle_string_bytes(args[0]);
            delimiter_len = (size_t)jinx_oracle_string_len(args[0]);
        }

        if (jinx_oracle_batch2_strtok_pos >= jinx_oracle_batch2_strtok_len) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        for (size_t i = 0u; i < delimiter_len; i++) {
            table[delimiters[i]] = 1u;
        }

        while (jinx_oracle_batch2_strtok_pos < jinx_oracle_batch2_strtok_len &&
               table[jinx_oracle_batch2_strtok_string[
                   jinx_oracle_batch2_strtok_pos
               ]] != 0u) {
            jinx_oracle_batch2_strtok_pos++;
        }

        if (jinx_oracle_batch2_strtok_pos >= jinx_oracle_batch2_strtok_len) {
            free(jinx_oracle_batch2_strtok_string);
            jinx_oracle_batch2_strtok_string = NULL;
            jinx_oracle_batch2_strtok_len = 0u;
            jinx_oracle_batch2_strtok_pos = 0u;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        start = jinx_oracle_batch2_strtok_pos;
        end = start + 1u;
        while (end < jinx_oracle_batch2_strtok_len &&
               table[jinx_oracle_batch2_strtok_string[end]] == 0u) {
            end++;
        }
        jinx_oracle_batch2_strtok_pos =
            end < jinx_oracle_batch2_strtok_len ? end + 1u : end;

        result = b2_copy(
            (const char *)jinx_oracle_batch2_strtok_string + start,
            end - start
        );
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "uniqid") == 0) {
        char *prefix = NULL;
        size_t prefix_len = 0u;
        int more_entropy = 0;
        struct timeval tv;
        unsigned int sec;
        unsigned int usec;
        char *out;
        int written;

        if (argc >= 1u && args != NULL) {
            if (args[0].type != 3u) return result;
            prefix_len = (size_t)jinx_oracle_string_len(args[0]);
            if (memchr(jinx_oracle_string_bytes(args[0]), '\0', prefix_len) != NULL) {
                return result;
            }
            prefix = b2_dup(args[0]);
            if (prefix == NULL) return result;
        } else {
            prefix = strdup("");
            if (prefix == NULL) return result;
        }
        if (argc >= 2u) more_entropy = jinx_oracle_boolish(args[1]);
        if (argc > 2u) {
            free(prefix);
            return result;
        }

        do {
            if (gettimeofday(&tv, NULL) != 0) {
                free(prefix);
                return result;
            }
        } while (tv.tv_sec == jinx_oracle_batch2_uniqid_prev.tv_sec &&
                 tv.tv_usec == jinx_oracle_batch2_uniqid_prev.tv_usec);

        jinx_oracle_batch2_uniqid_prev = tv;
        sec = (unsigned int)(int)tv.tv_sec;
        usec = (unsigned int)(tv.tv_usec % 0x100000);

        out = (char *)malloc(prefix_len + 64u);
        if (out == NULL) {
            free(prefix);
            return result;
        }

        if (more_entropy) {
            uint32_t bytes = 0u;
            double seed;
            if (!b2_random_fill((unsigned char *)&bytes, sizeof(bytes))) {
                bytes = (uint32_t)tv.tv_usec ^ (uint32_t)tv.tv_sec;
            }
            seed = ((double)bytes / (double)UINT32_MAX) * 10.0;
            written = snprintf(
                out, prefix_len + 64u,
                "%s%08x%05x%.8F",
                prefix, sec, usec, seed
            );
        } else {
            written = snprintf(
                out, prefix_len + 64u,
                "%s%08x%05x",
                prefix, sec, usec
            );
        }
        free(prefix);
        if (written < 0 || (size_t)written >= prefix_len + 64u) {
            free(out);
            return result;
        }
        result = b2_copy(out, (size_t)written);
        free(out);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "time") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)time(NULL));
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

    if (strcmp(name, "putenv") == 0) {
        char *setting;
        char *equals;
        int rc;
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        setting = b2_dup(args[0]);
        if (setting == NULL) return result;
        if (setting[0] == '\0' || setting[0] == '=') {
            free(setting);
            return result;
        }
        equals = strchr(setting, '=');
        if (equals == NULL) {
            rc = unsetenv(setting);
        } else {
            *equals = '\0';
            rc = setenv(setting, equals + 1, 1);
        }
        if (strcasecmp(setting, "TZ") == 0) tzset();
        free(setting);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(rc == 0);
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

    if (strcmp(name, "hrtime") == 0) {
        struct timespec ts;
        int as_number = args != NULL && argc >= 1u && jinx_oracle_boolish(args[0]);
        if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        if (as_number) {
            int64_t total = (int64_t)ts.tv_sec * 1000000000LL + (int64_t)ts.tv_nsec;
            return jinx_oracle_int_value(total);
        }
        {
            JinxZendArray *array = jinx_zend_array_new_packed(2u);
            if (array == NULL) return result;
            if (!jinx_zend_array_append(array, jinx_zend_long((int64_t)ts.tv_sec)) ||
                !jinx_zend_array_append(array, jinx_zend_long((int64_t)ts.tv_nsec))) {
                jinx_zend_array_release(array);
                return result;
            }
            return jinx_oracle_zend_array_value_owned(array);
        }
    }

    if (strcmp(name, "microtime") == 0) {
        struct timeval tv;
        int as_float = args != NULL && argc >= 1u && jinx_oracle_boolish(args[0]);
        if (gettimeofday(&tv, NULL) != 0) return result;
        if (handled != NULL) *handled = 1;
        if (as_float) {
            return jinx_oracle_float_value(
                (double)tv.tv_sec + (double)tv.tv_usec / 1000000.0
            );
        }
        {
            char buffer[96];
            int written = snprintf(
                buffer,
                sizeof(buffer),
                "%.8f %lld",
                (double)tv.tv_usec / 1000000.0,
                (long long)tv.tv_sec
            );
            if (written < 0 || (size_t)written >= sizeof(buffer)) return result;
            return b2_copy(buffer, (size_t)written);
        }
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
        array = jinx_zend_array_new_packed(17u);
        if (array == NULL) return result;
#define B2_RUSAGE_ADD(k, v) \
        do { if (!jinx_zend_array_add_assoc(array, k, sizeof(k) - 1u, jinx_zend_long((int64_t)(v)))) { jinx_zend_array_release(array); return result; } } while (0)
        B2_RUSAGE_ADD("ru_oublock", u.ru_oublock);
        B2_RUSAGE_ADD("ru_inblock", u.ru_inblock);
        B2_RUSAGE_ADD("ru_msgsnd", u.ru_msgsnd);
        B2_RUSAGE_ADD("ru_msgrcv", u.ru_msgrcv);
        B2_RUSAGE_ADD("ru_maxrss", u.ru_maxrss);
        B2_RUSAGE_ADD("ru_ixrss", u.ru_ixrss);
        B2_RUSAGE_ADD("ru_idrss", u.ru_idrss);
        B2_RUSAGE_ADD("ru_minflt", u.ru_minflt);
        B2_RUSAGE_ADD("ru_majflt", u.ru_majflt);
        B2_RUSAGE_ADD("ru_nsignals", u.ru_nsignals);
        B2_RUSAGE_ADD("ru_nvcsw", u.ru_nvcsw);
        B2_RUSAGE_ADD("ru_nivcsw", u.ru_nivcsw);
        B2_RUSAGE_ADD("ru_nswap", u.ru_nswap);
        B2_RUSAGE_ADD("ru_utime.tv_usec", u.ru_utime.tv_usec);
        B2_RUSAGE_ADD("ru_utime.tv_sec", u.ru_utime.tv_sec);
        B2_RUSAGE_ADD("ru_stime.tv_usec", u.ru_stime.tv_usec);
        B2_RUSAGE_ADD("ru_stime.tv_sec", u.ru_stime.tv_sec);
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

    if (strcmp(name, "openlog") == 0) {
        char *ident;
        char *owned_ident;
        int option;
        int facility;
        if (args == NULL || argc != 3u || args[0].type != 3u) return result;
        ident = b2_dup(args[0]);
        if (ident == NULL) return result;
        owned_ident = strdup(ident);
        free(ident);
        if (owned_ident == NULL) return result;
        free(jinx_oracle_batch2_syslog_ident);
        jinx_oracle_batch2_syslog_ident = owned_ident;
        option = (int)jinx_oracle_intish(args[1]);
        facility = (int)jinx_oracle_intish(args[2]);
        openlog(jinx_oracle_batch2_syslog_ident, option, facility);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
    }

    if (strcmp(name, "syslog") == 0) {
        char *message;
        int priority;
        if (args == NULL || argc != 2u || args[1].type != 3u) return result;
        priority = (int)jinx_oracle_intish(args[0]);
        message = b2_dup(args[1]);
        if (message == NULL) return result;
        syslog(priority, "%s", message);
        free(message);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
    }

    if (strcmp(name, "closelog") == 0) {
        closelog();
        free(jinx_oracle_batch2_syslog_ident);
        jinx_oracle_batch2_syslog_ident = NULL;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(1);
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

    if (strcmp(name, "stream_get_contents") == 0) {
        JinxOracleBatch2Stream *stream;
        int64_t maxlen = -1;
        int64_t desired = -1;
        unsigned char *buffer = NULL;
        size_t capacity = 0u;
        size_t length = 0u;
        if (args == NULL || argc < 1u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        if (argc >= 2u && args[1].type != 0u) {
            maxlen = jinx_oracle_intish(args[1]);
            if (maxlen < -1) return result;
        }
        if (argc >= 3u) {
            desired = jinx_oracle_intish(args[2]);
            if (desired >= 0) {
                long current = ftell(stream->fp);
                int seek_result;
                if (current >= 0 && desired > (int64_t)current) {
                    seek_result = fseek(
                        stream->fp,
                        (long)(desired - (int64_t)current),
                        SEEK_CUR
                    );
                } else if (current >= 0 && desired < (int64_t)current) {
                    seek_result = fseek(stream->fp, (long)desired, SEEK_SET);
                } else {
                    seek_result = current < 0
                        ? fseek(stream->fp, (long)desired, SEEK_SET)
                        : 0;
                }
                if (seek_result != 0) {
                    if (handled != NULL) *handled = 1;
                    return jinx_oracle_bool_value(0);
                }
            }
        }
        capacity = maxlen >= 0 && maxlen < 8192 ? (size_t)maxlen : 8192u;
        if (capacity == 0u) {
            if (handled != NULL) *handled = 1;
            return b2_copy("", 0u);
        }
        buffer = (unsigned char *)malloc(capacity);
        if (buffer == NULL) return result;
        while (maxlen < 0 || (int64_t)length < maxlen) {
            size_t wanted = capacity - length;
            size_t n;
            if (wanted == 0u) {
                size_t next = capacity < 1048576u ? capacity * 2u : capacity + 1048576u;
                if (next <= capacity || next > UINT32_MAX ||
                    (maxlen >= 0 && (uint64_t)next > (uint64_t)maxlen)) {
                    next = maxlen >= 0 ? (size_t)maxlen : (size_t)UINT32_MAX;
                }
                if (next <= capacity) break;
                {
                    unsigned char *grown = (unsigned char *)realloc(buffer, next);
                    if (grown == NULL) {
                        free(buffer);
                        return result;
                    }
                    buffer = grown;
                    capacity = next;
                    wanted = capacity - length;
                }
            }
            if (maxlen >= 0 && (uint64_t)wanted > (uint64_t)(maxlen - (int64_t)length)) {
                wanted = (size_t)(maxlen - (int64_t)length);
            }
            if (wanted == 0u) break;
            n = fread(buffer + length, 1u, wanted, stream->fp);
            length += n;
            if (n < wanted) break;
        }
        if (ferror(stream->fp)) {
            free(buffer);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        result = b2_copy((const char *)buffer, length);
        free(buffer);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "stream_get_line") == 0) {
        JinxOracleBatch2Stream *stream;
        int64_t maximum;
        char *delimiter;
        size_t delimiter_len;
        unsigned char *buffer;
        size_t length = 0u;
        if (args == NULL || argc < 3u || args[2].type != 3u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        maximum = jinx_oracle_intish(args[1]);
        if (maximum < 0) return result;
        if (maximum == 0) maximum = 8192;
        if ((uint64_t)maximum > UINT32_MAX) return result;
        delimiter = b2_dup(args[2]);
        if (delimiter == NULL) return result;
        delimiter_len = strlen(delimiter);
        if (delimiter_len == 0u) {
            free(delimiter);
            return result;
        }
        buffer = (unsigned char *)malloc((size_t)maximum);
        if (buffer == NULL) {
            free(delimiter);
            return result;
        }
        while (length < (size_t)maximum) {
            int ch = fgetc(stream->fp);
            if (ch == EOF) break;
            buffer[length++] = (unsigned char)ch;
            if (length >= delimiter_len &&
                memcmp(
                    buffer + length - delimiter_len,
                    delimiter,
                    delimiter_len
                ) == 0) {
                length -= delimiter_len;
                break;
            }
        }
        free(delimiter);
        if (ferror(stream->fp)) {
            free(buffer);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (length == 0u && feof(stream->fp)) {
            free(buffer);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        result = b2_copy((const char *)buffer, length);
        free(buffer);
        if (handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "stream_copy_to_stream") == 0) {
        JinxOracleBatch2Stream *source;
        JinxOracleBatch2Stream *destination;
        int64_t maximum = -1;
        int64_t position = 0;
        unsigned char buffer[16384];
        size_t total = 0u;
        if (args == NULL || argc < 2u) return result;
        source = b2_stream(args[0]);
        destination = b2_stream(args[1]);
        if (source == NULL || destination == NULL ||
            source->fp == NULL || destination->fp == NULL) return result;
        if (argc >= 3u && args[2].type != 0u) {
            maximum = jinx_oracle_intish(args[2]);
            if (maximum < -1) return result;
        }
        if (argc >= 4u) position = jinx_oracle_intish(args[3]);
        if (position > 0 && fseek(source->fp, (long)position, SEEK_SET) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        while (maximum < 0 || (int64_t)total < maximum) {
            size_t wanted = sizeof(buffer);
            size_t n;
            if (maximum >= 0 &&
                (uint64_t)wanted > (uint64_t)(maximum - (int64_t)total)) {
                wanted = (size_t)(maximum - (int64_t)total);
            }
            if (wanted == 0u) break;
            n = fread(buffer, 1u, wanted, source->fp);
            if (n == 0u) break;
            if (fwrite(buffer, 1u, n, destination->fp) != n) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            total += n;
            if (n < wanted) break;
        }
        if (ferror(source->fp)) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)total);
    }

    if (strcmp(name, "stream_isatty") == 0 ||
        strcmp(name, "stream_supports_lock") == 0 ||
        strcmp(name, "stream_set_blocking") == 0 ||
        strcmp(name, "socket_set_blocking") == 0 ||
        strcmp(name, "stream_set_read_buffer") == 0 ||
        strcmp(name, "stream_set_write_buffer") == 0 ||
        strcmp(name, "set_file_buffer") == 0) {
        JinxOracleBatch2Stream *stream;
        int fd;
        if (args == NULL || argc < 1u) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        fd = fileno(stream->fp);
        if (fd < 0) return result;

        if (strcmp(name, "stream_isatty") == 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(isatty(fd) == 1);
        }

        if (strcmp(name, "stream_supports_lock") == 0) {
            struct stat st;
            int supported = fstat(fd, &st) == 0 && S_ISREG(st.st_mode);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(supported);
        }

        if (strcmp(name, "stream_set_blocking") == 0 ||
            strcmp(name, "socket_set_blocking") == 0) {
            int flags;
            int block;
            if (argc != 2u) return result;
            block = jinx_oracle_boolish(args[1]);
            flags = fcntl(fd, F_GETFL, 0);
            if (flags < 0) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            if (block) flags &= ~O_NONBLOCK;
            else flags |= O_NONBLOCK;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(fcntl(fd, F_SETFL, flags) == 0);
        }

        {
            int64_t size;
            int rc;
            if (argc != 2u) return result;
            size = jinx_oracle_intish(args[1]);
            if (size < 0 || (uint64_t)size > SIZE_MAX) return result;

            /*
             * PHP's tmpfile() wrapper does not honor write-buffer changes and
             * returns -1. An unlinked regular file is the native tmpfile()
             * shape on POSIX (st_nlink == 0), while ordinary fopen() files
             * keep the setvbuf-backed path below.
             */
            if (strcmp(name, "stream_set_write_buffer") == 0 ||
                strcmp(name, "set_file_buffer") == 0) {
                struct stat st;
                if (fstat(fd, &st) == 0 && S_ISREG(st.st_mode) && st.st_nlink == 0) {
                    if (handled != NULL) *handled = 1;
                    return jinx_oracle_int_value(-1);
                }
            }

            rc = setvbuf(
                stream->fp,
                NULL,
                size == 0 ? _IONBF : _IOFBF,
                (size_t)size
            );
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(rc == 0 ? 0 : EOF);
        }
    }

    if (strcmp(name, "stream_is_local") == 0) {
        if (args == NULL || argc != 1u) return result;
        if (args[0].type == JINX_ORACLE_VALUE_ZEND_OBJECT) {
            JinxOracleBatch2Stream *stream = b2_stream(args[0]);
            struct stat st;
            int local;
            if (stream == NULL || stream->fp == NULL) return result;
            local = fstat(fileno(stream->fp), &st) == 0 &&
                !S_ISSOCK(st.st_mode);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(local);
        }
        if (args[0].type == 3u) {
            char *target = b2_dup(args[0]);
            char *scheme;
            int local;
            if (target == NULL) return result;
            scheme = strstr(target, "://");
            local = scheme == NULL ||
                strncasecmp(target, "file://", 7u) == 0;
            free(target);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(local);
        }
        return result;
    }

    if (strcmp(name, "stream_resolve_include_path") == 0) {
        char *filename;
        char resolved[PATH_MAX];
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        filename = b2_dup(args[0]);
        if (filename == NULL) return result;
        if (realpath(filename, resolved) != NULL) {
            free(filename);
            if (handled != NULL) *handled = 1;
            return b2_copy(resolved, strlen(resolved));
        }
        {
            const JinxNativeIniMeta *include_meta = b2_ini_meta("include_path");
            const char *include_path = include_meta != NULL
                ? b2_ini_current(include_meta)
                : JINX_NATIVE_PHP_INCLUDE_PATH;
            char *paths = strdup(include_path != NULL ? include_path : "");
            char *save = NULL;
            char *entry = paths != NULL ? strtok_r(paths, ":", &save) : NULL;
            while (entry != NULL) {
                char candidate[PATH_MAX];
                const char *dir = entry[0] == '\0' ? "." : entry;
                int written = snprintf(
                    candidate, sizeof(candidate), "%s/%s", dir, filename
                );
                if (written > 0 && (size_t)written < sizeof(candidate) &&
                    realpath(candidate, resolved) != NULL) {
                    free(paths);
                    free(filename);
                    if (handled != NULL) *handled = 1;
                    return b2_copy(resolved, strlen(resolved));
                }
                entry = strtok_r(NULL, ":", &save);
            }
            free(paths);
        }
        free(filename);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(0);
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

    if (strcmp(name, "vfprintf") == 0) {
        JinxOracleBatch2Stream *stream;
        JinxZendArray *values;
        size_t live;
        JinxValue *format_args = NULL;
        JinxValue formatted;
        int format_ok = 0;
        size_t written;
        if (args == NULL || argc != 3u || args[1].type != 3u ||
            !jinx_oracle_value_is_zend_array(args[2])) return result;
        stream = b2_stream(args[0]);
        if (stream == NULL || stream->fp == NULL) return result;
        values = jinx_oracle_zend_array_ptr(args[2]);
        live = jinx_zend_array_live_count(values);
        if (live > UINT32_MAX) return result;
        if (live != 0u) {
            format_args = (JinxValue *)calloc(live, sizeof(*format_args));
            if (format_args == NULL) return result;
            for (size_t i = 0u; i < live; i++) {
                const JinxZendBucket *bucket =
                    jinx_zend_array_live_iter_at(values, i);
                if (bucket == NULL ||
                    !jinx_oracle_zend_to_jinx_borrowed(
                        bucket->value, &format_args[i]
                    )) {
                    free(format_args);
                    return result;
                }
            }
        }
        formatted = jinx_oracle_sprintf_values(
            args[1],
            format_args,
            (uint32_t)live,
            &format_ok
        );
        free(format_args);
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

    if (strcmp(name, "strftime") == 0) {
        char *format;
        time_t raw;
        struct tm tmv;
        char buffer[4096];
        size_t len;
        const char *old_tz;
        char *saved_tz = NULL;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        format = b2_dup(args[0]);
        if (format == NULL) return result;
        if (format[0] == '\0') {
            free(format);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        raw = argc >= 2u && args[1].type != 0u ? (time_t)jinx_oracle_intish(args[1]) : time(NULL);
        old_tz = getenv("TZ");
        if (old_tz != NULL) saved_tz = strdup(old_tz);
        if (setenv(
                "TZ",
                jinx_oracle_extended_default_timezone(),
                1
            ) != 0) {
            free(saved_tz); free(format); return result;
        }
        tzset();
        if (localtime_r(&raw, &tmv) == NULL) {
            if (saved_tz != NULL) setenv("TZ", saved_tz, 1); else unsetenv("TZ");
            tzset(); free(saved_tz); free(format); return result;
        }
        len = strftime(buffer, sizeof(buffer), format, &tmv);
        if (saved_tz != NULL) setenv("TZ", saved_tz, 1); else unsetenv("TZ");
        tzset();
        free(saved_tz); free(format);
        if (handled != NULL) *handled = 1;
        return len != 0u ? b2_copy(buffer, len) : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "strptime") == 0) {
        char *text;
        char *format;
        char *unparsed;
        struct tm parsed_time;
        JinxZendArray *array;

        if (args == NULL || argc != 2u ||
            args[0].type != 3u || args[1].type != 3u) {
            return result;
        }

        text = b2_dup(args[0]);
        format = b2_dup(args[1]);
        if (text == NULL || format == NULL) {
            free(text);
            free(format);
            return result;
        }

        memset(&parsed_time, 0, sizeof(parsed_time));
        unparsed = strptime(text, format, &parsed_time);
        if (unparsed == NULL) {
            free(text);
            free(format);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        array = jinx_zend_array_new_packed(9u);
        if (array == NULL ||
            !jinx_zend_array_add_assoc(array, "tm_sec", 6u, jinx_zend_long(parsed_time.tm_sec)) ||
            !jinx_zend_array_add_assoc(array, "tm_min", 6u, jinx_zend_long(parsed_time.tm_min)) ||
            !jinx_zend_array_add_assoc(array, "tm_hour", 7u, jinx_zend_long(parsed_time.tm_hour)) ||
            !jinx_zend_array_add_assoc(array, "tm_mday", 7u, jinx_zend_long(parsed_time.tm_mday)) ||
            !jinx_zend_array_add_assoc(array, "tm_mon", 6u, jinx_zend_long(parsed_time.tm_mon)) ||
            !jinx_zend_array_add_assoc(array, "tm_year", 7u, jinx_zend_long(parsed_time.tm_year)) ||
            !jinx_zend_array_add_assoc(array, "tm_wday", 7u, jinx_zend_long(parsed_time.tm_wday)) ||
            !jinx_zend_array_add_assoc(array, "tm_yday", 7u, jinx_zend_long(parsed_time.tm_yday)) ||
            !b2_assoc_string(array, "unparsed", unparsed)) {
            if (array != NULL) jinx_zend_array_release(array);
            free(text);
            free(format);
            return result;
        }

        free(text);
        free(format);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
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

    if (strcmp(name, "readgzfile") == 0) {
        char *path;
        gzFile gz;
        unsigned char buffer[8192];
        int got;
        int64_t total = 0;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (argc >= 2u && jinx_oracle_boolish(args[1])) return result;
        path = b2_dup(args[0]);
        if (path == NULL) return result;
        gz = gzopen(path, "rb");
        free(path);
        if (gz == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        while ((got = gzread(gz, buffer, sizeof(buffer))) > 0) {
            if (fwrite(buffer, 1u, (size_t)got, stdout) != (size_t)got) {
                gzclose(gz);
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            total += got;
        }
        gzclose(gz);
        fflush(stdout);
        if (handled != NULL) *handled = 1;
        return got < 0 ? jinx_oracle_bool_value(0) : jinx_oracle_int_value(total);
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
        const JinxNativeIniMeta *meta = b2_ini_meta("include_path");
        const char *value = meta != NULL
            ? b2_ini_current(meta)
            : JINX_NATIVE_PHP_INCLUDE_PATH;
        if (handled != NULL) *handled = 1;
        return value != NULL
            ? b2_copy(value, strlen(value))
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "set_include_path") == 0) {
        int set_ok = 0;
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        result = b2_ini_set_value("include_path", args[0], &set_ok);
        if (set_ok && handled != NULL) *handled = 1;
        return result;
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

    if (strcmp(name, "shell_exec") == 0) {
        char *command;
        FILE *pipe;
        unsigned char chunk[4096];
        unsigned char *output = NULL;
        size_t length = 0u;
        size_t capacity = 0u;

        if (args == NULL || argc != 1u || args[0].type != 3u ||
            jinx_oracle_string_len(args[0]) == 0u) {
            return result;
        }
        command = b2_dup(args[0]);
        if (command == NULL) return result;
        pipe = popen(command, "r");
        free(command);
        if (pipe == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        for (;;) {
            size_t got = fread(chunk, 1u, sizeof(chunk), pipe);
            if (got != 0u) {
                if (length > SIZE_MAX - got) {
                    free(output);
                    (void)pclose(pipe);
                    return result;
                }
                if (length + got > capacity) {
                    size_t next = capacity == 0u ? 4096u : capacity;
                    while (next < length + got) {
                        if (next > SIZE_MAX / 2u) {
                            next = length + got;
                            break;
                        }
                        next *= 2u;
                    }
                    {
                        unsigned char *grown = (unsigned char *)realloc(output, next);
                        if (grown == NULL) {
                            free(output);
                            (void)pclose(pipe);
                            return result;
                        }
                        output = grown;
                        capacity = next;
                    }
                }
                memcpy(output + length, chunk, got);
                length += got;
            }
            if (got < sizeof(chunk)) {
                if (feof(pipe)) break;
                if (ferror(pipe)) {
                    free(output);
                    (void)pclose(pipe);
                    if (handled != NULL) *handled = 1;
                    return jinx_oracle_bool_value(0);
                }
            }
        }
        (void)pclose(pipe);
        if (handled != NULL) *handled = 1;
        if (length == 0u) {
            free(output);
            return jinx_oracle_zero_value();
        }
        result = b2_copy(output, length);
        free(output);
        return result;
    }

    if (strcmp(name, "proc_nice") == 0) {
        if (args == NULL || argc != 1u) return result;
        errno = 0;
        (void)nice((int)jinx_oracle_intish(args[0]));
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(errno == 0);
    }

    if (strcmp(name, "system") == 0 || strcmp(name, "passthru") == 0) {
        char *command;
        FILE *pipe;
        unsigned char buffer[4096];
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
        if (strcmp(name, "system") == 0) {
            char line[4096];
            while (fgets(line, sizeof(line), pipe) != NULL) {
                size_t n = strlen(line);
                if (fwrite(line, 1u, n, stdout) != n) {
                    (void)pclose(pipe);
                    if (handled != NULL) *handled = 1;
                    return jinx_oracle_bool_value(0);
                }
                while (n != 0u && (line[n - 1u] == '\n' || line[n - 1u] == '\r')) {
                    line[--n] = '\0';
                }
                snprintf(last, sizeof(last), "%s", line);
            }
        } else {
            size_t got;
            while ((got = fread(buffer, 1u, sizeof(buffer), pipe)) != 0u) {
                if (fwrite(buffer, 1u, got, stdout) != got) {
                    (void)pclose(pipe);
                    if (handled != NULL) *handled = 1;
                    return jinx_oracle_bool_value(0);
                }
            }
        }
        (void)pclose(pipe);
        fflush(stdout);
        if (handled != NULL) *handled = 1;
        return strcmp(name, "passthru") == 0
            ? jinx_oracle_zero_value()
            : b2_copy(last, strlen(last));
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


    if (strcmp(name, "ini_parse_quantity") == 0) {
        char *text;
        char *start;
        char *finish;
        char *digits;
        char *endptr = NULL;
        int negative = 0;
        int base = 0;
        unsigned long long magnitude;
        int64_t value = 0;
        int64_t factor = 1;

        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        text = b2_dup(args[0]);
        if (text == NULL) return result;

        start = text;
        finish = text + strlen(text);
        while (start < finish && isspace((unsigned char)*start)) start++;
        while (finish > start && isspace((unsigned char)finish[-1])) finish--;

        if (start == finish) {
            free(text);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(0);
        }

        if (*start == '+' || *start == '-') {
            negative = *start == '-';
            start++;
        }
        if (start >= finish || !isdigit((unsigned char)*start)) {
            free(text);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(0);
        }

        digits = start;
        if ((finish - start) >= 2 && start[0] == '0') {
            switch (start[1]) {
                case 'x': case 'X':
                    base = 16;
                    digits = start + 2;
                    break;
                case 'o': case 'O':
                    base = 8;
                    digits = start + 2;
                    break;
                case 'b': case 'B':
                    base = 2;
                    digits = start + 2;
                    break;
                default:
                    break;
            }
        }

        if (digits >= finish || !isxdigit((unsigned char)*digits)) {
            free(text);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(0);
        }

        errno = 0;
        magnitude = strtoull(digits, &endptr, base);
        if (endptr == digits) {
            free(text);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(0);
        }

        if (negative) {
            if (magnitude == (unsigned long long)INT64_MAX + 1ULL) {
                value = INT64_MIN;
            } else if (magnitude <= (unsigned long long)INT64_MAX) {
                value = -(int64_t)magnitude;
            } else {
                value = INT64_MIN;
            }
        } else {
            value = magnitude <= (unsigned long long)INT64_MAX
                ? (int64_t)magnitude
                : INT64_MAX;
        }

        while (endptr < finish && isspace((unsigned char)*endptr)) endptr++;
        if (endptr < finish) {
            unsigned char suffix = (unsigned char)finish[-1];
            if (suffix == 'k' || suffix == 'K') {
                factor = 1LL << 10;
            } else if (suffix == 'm' || suffix == 'M') {
                factor = 1LL << 20;
            } else if (suffix == 'g' || suffix == 'G') {
                factor = 1LL << 30;
            }
        }

        if (factor != 1) {
            if (value > 0 && value > INT64_MAX / factor) {
                value = INT64_MAX;
            } else if (value < 0 && value < INT64_MIN / factor) {
                value = INT64_MIN;
            } else {
                value *= factor;
            }
        }

        free(text);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(value);
    }

    if (strcmp(name, "ini_get") == 0) {
        char *key;
        const JinxNativeIniMeta *meta;
        const char *value;
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        key = b2_dup(args[0]);
        if (key == NULL) return result;
        meta = b2_ini_meta(key);
        free(key);
        if (handled != NULL) *handled = 1;
        if (meta == NULL) return jinx_oracle_bool_value(0);
        value = b2_ini_current(meta);
        return value != NULL
            ? b2_copy(value, strlen(value))
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "ini_set") == 0 || strcmp(name, "ini_alter") == 0) {
        char *key;
        int set_ok = 0;
        if (args == NULL || argc != 2u || args[0].type != 3u) return result;
        if (args[1].type != 0u && args[1].type != 1u &&
            args[1].type != 2u && args[1].type != 3u &&
            args[1].type != 5u) return result;
        key = b2_dup(args[0]);
        if (key == NULL) return result;
        result = b2_ini_set_value(key, args[1], &set_ok);
        free(key);
        if (set_ok && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "ini_restore") == 0) {
        char *key;
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        key = b2_dup(args[0]);
        if (key == NULL) return result;
        b2_ini_clear_override(key);
        free(key);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "ini_get_all") == 0) {
        char *extension = NULL;
        int details = 1;
        JinxZendArray *array;
        if (argc >= 1u && args != NULL && args[0].type != 0u) {
            if (args[0].type != 3u) return result;
            extension = b2_dup(args[0]);
            if (extension == NULL) return result;
            if (b2_extension(extension) == NULL) {
                free(extension);
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
        }
        if (argc >= 2u) details = jinx_oracle_boolish(args[1]);
        array = jinx_zend_array_new_packed(
            jinx_native_ini_metadata_count == 0u
                ? 1u
                : jinx_native_ini_metadata_count
        );
        if (array == NULL) {
            free(extension);
            return result;
        }
        for (size_t i = 0u; i < jinx_native_ini_metadata_count; i++) {
            const JinxNativeIniMeta *meta = &jinx_native_ini_metadata[i];
            const char *current;
            JinxZendValue value;
            JinxZendString *owned = NULL;
            if (extension != NULL &&
                strcasecmp(extension, meta->extension) != 0) {
                continue;
            }
            current = b2_ini_current(meta);
            if (details) {
                JinxZendArray *option = jinx_zend_array_new_packed(3u);
                if (option == NULL) {
                    free(extension);
                    jinx_zend_array_release(array);
                    return result;
                }
                if (meta->global_value != NULL) {
                    if (!b2_assoc_string(option, "global_value", meta->global_value)) {
                        jinx_zend_array_release(option);
                        free(extension);
                        jinx_zend_array_release(array);
                        return result;
                    }
                } else if (!jinx_zend_array_add_assoc(
                    option, "global_value", 12u, jinx_zend_null()
                )) {
                    jinx_zend_array_release(option);
                    free(extension);
                    jinx_zend_array_release(array);
                    return result;
                }
                if (current != NULL) {
                    if (!b2_assoc_string(option, "local_value", current)) {
                        jinx_zend_array_release(option);
                        free(extension);
                        jinx_zend_array_release(array);
                        return result;
                    }
                } else if (!jinx_zend_array_add_assoc(
                    option, "local_value", 11u, jinx_zend_null()
                )) {
                    jinx_zend_array_release(option);
                    free(extension);
                    jinx_zend_array_release(array);
                    return result;
                }
                if (!jinx_zend_array_add_assoc(
                        option, "access", 6u, jinx_zend_long(meta->access)
                    ) ||
                    !jinx_zend_array_add_assoc(
                        array, meta->name, strlen(meta->name),
                        jinx_zend_array_value(option)
                    )) {
                    jinx_zend_array_release(option);
                    free(extension);
                    jinx_zend_array_release(array);
                    return result;
                }
                jinx_zend_array_release(option);
            } else {
                if (current == NULL) {
                    value = jinx_zend_null();
                } else {
                    owned = jinx_zend_string_new(current, strlen(current));
                    if (owned == NULL) {
                        free(extension);
                        jinx_zend_array_release(array);
                        return result;
                    }
                    value = jinx_zend_string_value(owned);
                }
                if (!jinx_zend_array_add_assoc(
                        array, meta->name, strlen(meta->name), value
                    )) {
                    jinx_zend_string_release(owned);
                    free(extension);
                    jinx_zend_array_release(array);
                    return result;
                }
                jinx_zend_string_release(owned);
            }
        }
        free(extension);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
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
    if (strcmp(name, "password_verify") == 0) {
        char *password;
        char *hash;
        char *computed;
        int bcrypt_cost;

        if (args == NULL || argc != 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;

        password = b2_dup(args[0]);
        hash = b2_dup(args[1]);
        if (password == NULL || hash == NULL) {
            free(password);
            free(hash);
            return result;
        }

        if (!b2_bcrypt_cost(hash, &bcrypt_cost)) {
            free(password);
            free(hash);
            return result;
        }

        computed = crypt(password, hash);
        free(password);
        if (handled != NULL) *handled = 1;
        if (computed == NULL) {
            free(hash);
            return jinx_oracle_bool_value(0);
        }

        {
            int verified = b2_constant_time_string_equal(computed, hash);
            free(hash);
            return jinx_oracle_bool_value(verified);
        }
    }

    if (strcmp(name, "password_get_info") == 0) {
        char *hash;
        int cost = 0;
        int is_bcrypt;
        JinxZendArray *outer;
        JinxZendArray *options;
        JinxValue algo = jinx_value_null();
        const JinxNativeConstantMeta *algo_meta;

        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        hash = b2_dup(args[0]);
        if (hash == NULL) return result;
        is_bcrypt = b2_bcrypt_cost(hash, &cost);
        free(hash);

        outer = jinx_zend_array_new_packed(3u);
        options = jinx_zend_array_new_packed(is_bcrypt ? 1u : 0u);
        if (outer == NULL || options == NULL) {
            jinx_zend_array_release(outer);
            jinx_zend_array_release(options);
            return result;
        }

        if (is_bcrypt) {
            algo_meta = b2_constant_meta("PASSWORD_BCRYPT");
            if (algo_meta != NULL) algo = b2_constant_value(algo_meta);
            else algo = jinx_oracle_string_value("2y");

            if (!jinx_zend_array_add_assoc(
                    options, "cost", 4u, jinx_zend_long((int64_t)cost))) {
                jinx_zend_array_release(outer);
                jinx_zend_array_release(options);
                return result;
            }
        }

        if (!b2_assoc_value(outer, "algo", algo) ||
            !b2_assoc_string(
                outer, "algoName", is_bcrypt ? "bcrypt" : "unknown"
            ) ||
            !jinx_zend_array_add_assoc(
                outer, "options", 7u, jinx_zend_array_value(options)
            )) {
            jinx_zend_array_release(outer);
            jinx_zend_array_release(options);
            return result;
        }

        jinx_zend_array_release(options);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(outer);
    }

    if (strcmp(name, "password_needs_rehash") == 0) {
        char *hash;
        int current_cost = 0;
        int desired_cost = 12;
        int is_bcrypt;
        int algo_is_bcrypt = 0;
        const JinxNativeConstantMeta *algo_meta;
        const JinxNativeConstantMeta *cost_meta;

        if (args == NULL || argc < 2u || args[0].type != 3u) return result;
        hash = b2_dup(args[0]);
        if (hash == NULL) return result;

        algo_meta = b2_constant_meta("PASSWORD_BCRYPT");
        if (algo_meta != NULL) {
            if (algo_meta->type == 3u && args[1].type == 3u) {
                char *algo = b2_dup(args[1]);
                if (algo != NULL) {
                    algo_is_bcrypt = algo_meta->str != NULL &&
                        strcmp(algo, algo_meta->str) == 0;
                    free(algo);
                }
            } else if (algo_meta->type == 1u &&
                       (args[1].type == 1u || args[1].type == 2u)) {
                algo_is_bcrypt =
                    jinx_oracle_intish(args[1]) == (int64_t)algo_meta->i64;
            }
        }

        if (!algo_is_bcrypt) {
            free(hash);
            return result;
        }

        if (argc >= 3u && args[2].type != 0u) {
            if (args[2].type == JINX_ORACLE_VALUE_ZEND_ARRAY) {
                JinxZendArray *options_array =
                    jinx_oracle_zend_array_ptr(args[2]);
                JinxZendValue *cost_value = options_array != NULL
                    ? jinx_zend_array_find(options_array, "cost", 4u)
                    : NULL;
                if (cost_value != NULL &&
                    (cost_value->type == JINX_ZEND_LONG ||
                     cost_value->type == JINX_ZEND_BOOL)) {
                    desired_cost = (int)cost_value->value.lval;
                }
            } else {
                free(hash);
                return result;
            }
        } else {
            cost_meta = b2_constant_meta("PASSWORD_BCRYPT_DEFAULT_COST");
            if (cost_meta != NULL && cost_meta->type == 1u) {
                desired_cost = (int)cost_meta->i64;
            }
        }

        is_bcrypt = b2_bcrypt_cost(hash, &current_cost);
        free(hash);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(
            !is_bcrypt || current_cost != desired_cost
        );
    }

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

    {
        int dns_handled = 0;
        JinxValue dns_result = jinx_oracle_dns_builtin(
            name, args, argc, &dns_handled
        );
        if (dns_handled) {
            if (handled != NULL) *handled = 1;
            return dns_result;
        }
    }

    {
        int ftp_handled = 0;
        JinxValue ftp_result = jinx_oracle_curl_ftp_builtin(
            name, args, argc, &ftp_handled
        );
        if (ftp_handled) {
            if (handled != NULL) *handled = 1;
            return ftp_result;
        }
    }

    {
        int ftp_handled = 0;
        JinxValue ftp_result = jinx_oracle_ftp_builtin(
            name, args, argc, &ftp_handled
        );
        if (ftp_handled) {
            if (handled != NULL) *handled = 1;
            return ftp_result;
        }
    }

    {
        int http_meta_handled = 0;
        JinxValue http_meta_result = jinx_oracle_http_meta_builtin(
            name, args, argc, &http_meta_handled
        );
        if (http_meta_handled) {
            if (handled != NULL) *handled = 1;
            return http_meta_result;
        }
    }

    if (strcmp(name, "define") == 0) {
        char *constant_name;
        int ok_define;
        if (args == NULL || argc < 2u || argc > 3u ||
            args[0].type != 3u) {
            return result;
        }
        if (argc >= 3u && jinx_oracle_boolish(args[2])) {
            /* PHP 8 ignores case_insensitive but emits a warning; native
             * warning propagation is not yet threaded through this bridge. */
        }
        constant_name = b2_dup(args[0]);
        if (constant_name == NULL) return result;
        if (b2_constant_meta(constant_name) != NULL ||
            jinx_oracle_constant_registry_defined(constant_name)) {
            free(constant_name);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        ok_define = jinx_oracle_constant_registry_define(
            constant_name, args[1]
        );
        free(constant_name);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(ok_define);
    }

    if (strcmp(name, "get_browser") == 0) {
        const char *browscap = b2_cfg_value("browscap");
        /*
         * PHP returns false when no browscap file is configured. If a
         * browscap database exists, parsing it is a separate subsystem and
         * this direct builtin bridge deliberately fails closed for now.
         */
        if (browscap == NULL || browscap[0] == '\0') {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        return result;
    }

    {
        int exif_handled = 0;
        JinxValue exif_result = jinx_oracle_exif_builtin(
            name, args, argc, &exif_handled
        );
        if (exif_handled) {
            if (handled != NULL) *handled = 1;
            return exif_result;
        }
    }

    if (strcmp(name, "func_num_args") == 0 ||
        strcmp(name, "func_get_arg") == 0 ||
        strcmp(name, "func_get_args") == 0 ||
        strcmp(name, "get_called_class") == 0) {
        JinxZendCallFrame *frame = jinx_oracle_get_caller_frame();

        if (frame == NULL) {
            /* PHP throws when these are used outside their required context. */
            return result;
        }

        if (strcmp(name, "func_num_args") == 0) {
            if (argc != 0u) return result;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value((int64_t)frame->argc);
        }

        if (strcmp(name, "func_get_arg") == 0) {
            int64_t offset;
            JinxValue value;
            if (args == NULL || argc != 1u) return result;
            offset = jinx_oracle_intish(args[0]);
            if (offset < 0 || (uint64_t)offset >= (uint64_t)frame->argc) {
                /* Current PHP raises ValueError for either invalid range. */
                return result;
            }
            if (!b2_frame_value_to_jinx(
                    frame->args[(size_t)offset], &value
                )) {
                return result;
            }
            if (handled != NULL) *handled = 1;
            return value;
        }

        if (strcmp(name, "func_get_args") == 0) {
            JinxZendArray *array;
            if (argc != 0u) return result;
            array = jinx_zend_array_new_packed(
                frame->argc == 0u ? 1u : frame->argc
            );
            if (array == NULL) return result;
            for (size_t i = 0u; i < frame->argc; i++) {
                if (!jinx_zend_array_append(array, frame->args[i])) {
                    jinx_zend_array_release(array);
                    return result;
                }
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(array);
        }

        if (strcmp(name, "get_called_class") == 0) {
            if (argc != 0u || frame->scope_name == NULL ||
                frame->scope_name[0] == '\0') {
                return result;
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_string_value(frame->scope_name);
        }
    }

    if (strcmp(name, "get_defined_vars") == 0 ||
        strcmp(name, "compact") == 0 ||
        strcmp(name, "extract") == 0) {
        JinxZendCallFrame *frame = jinx_oracle_get_caller_frame();
        if (frame == NULL || frame->locals == NULL) return result;

        if (strcmp(name, "get_defined_vars") == 0) {
            JinxZendArray *copy;
            if (argc != 0u) return result;
            copy = jinx_zend_array_clone(frame->locals);
            if (copy == NULL) return result;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(copy);
        }

        if (strcmp(name, "compact") == 0) {
            JinxZendArray *out;
            if (args == NULL || argc < 1u) return result;
            out = jinx_zend_array_new_packed(argc);
            if (out == NULL) return result;
            for (size_t i = 0u; i < argc; i++) {
                if (!b2_compact_one(frame, out, args[i], 0u)) {
                    jinx_zend_array_release(out);
                    return result;
                }
            }
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zend_array_value_owned(out);
        }

        if (strcmp(name, "extract") == 0) {
            JinxZendArray *input;
            int64_t flags = 0;
            int mode;
            char *prefix = NULL;
            size_t prefix_len = 0u;
            int64_t count = 0;
            int ok_extract;

            if (args == NULL || argc < 1u || argc > 3u ||
                !jinx_oracle_value_is_zend_array(args[0])) {
                return result;
            }
            input = jinx_oracle_zend_array_ptr(args[0]);
            if (argc >= 2u && args[1].type != 0u) {
                flags = jinx_oracle_intish(args[1]);
            }
            if ((flags & b2_constant_int("EXTR_REFS", 256)) != 0) {
                /* Native reference carriers are not wired to frame locals yet. */
                return result;
            }
            mode = (int)(flags & 0xff);
            if (mode < 0 || mode > 6) return result;

            if (argc >= 3u && args[2].type != 0u) {
                if (args[2].type != 3u) return result;
                prefix = b2_dup(args[2]);
                if (prefix == NULL) return result;
                prefix_len = strlen(prefix);
                if (prefix_len != 0u &&
                    !b2_valid_var_name(prefix, prefix_len)) {
                    free(prefix);
                    return result;
                }
            }

            if (mode > 1 && mode <= 5 && argc < 3u) {
                free(prefix);
                return result;
            }

            ok_extract = b2_extract_nonref(
                frame,
                input,
                mode,
                prefix != NULL ? prefix : "",
                prefix_len,
                &count
            );
            free(prefix);
            if (!ok_extract) return result;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_int_value(count);
        }
    }

    if (strcmp(name, "debug_backtrace") == 0) {
        JinxZendCallFrame *frame = jinx_oracle_get_caller_frame();
        int64_t options = b2_constant_int(
            "DEBUG_BACKTRACE_PROVIDE_OBJECT", 1
        );
        int64_t limit = 0;
        int64_t ignore_args = b2_constant_int(
            "DEBUG_BACKTRACE_IGNORE_ARGS", 2
        );
        JinxZendArray *trace;
        size_t emitted = 0u;

        if (argc >= 1u && args != NULL && args[0].type != 0u) {
            options = jinx_oracle_intish(args[0]);
        }
        if (argc >= 2u && args != NULL && args[1].type != 0u) {
            limit = jinx_oracle_intish(args[1]);
            if (limit < 0) return result;
        }
        if (argc > 2u) return result;

        trace = jinx_zend_array_new_packed(8u);
        if (trace == NULL) return result;

        while (frame != NULL &&
               (limit == 0 || (int64_t)emitted < limit)) {
            JinxZendArray *record = jinx_zend_array_new_packed(8u);
            if (record == NULL) {
                jinx_zend_array_release(trace);
                return result;
            }

            if (frame->call_file != NULL &&
                frame->call_file[0] != '\0') {
                if (!b2_assoc_string(
                        record, "file", frame->call_file
                    ) ||
                    !jinx_zend_array_add_assoc(
                        record, "line", 4u,
                        jinx_zend_long((int64_t)frame->call_line)
                    )) {
                    jinx_zend_array_release(record);
                    jinx_zend_array_release(trace);
                    return result;
                }
            }

            if (frame->function_name != NULL &&
                frame->function_name[0] != '\0' &&
                !b2_assoc_string(
                    record, "function", frame->function_name
                )) {
                jinx_zend_array_release(record);
                jinx_zend_array_release(trace);
                return result;
            }

            if (frame->scope_name != NULL &&
                frame->scope_name[0] != '\0') {
                const char *type = frame->call_type != NULL &&
                    frame->call_type[0] != '\0'
                    ? frame->call_type
                    : "::";
                if (!b2_assoc_string(
                        record, "class", frame->scope_name
                    ) ||
                    !b2_assoc_string(record, "type", type)) {
                    jinx_zend_array_release(record);
                    jinx_zend_array_release(trace);
                    return result;
                }
            }

            if ((options & ignore_args) == 0) {
                JinxZendArray *arg_array = jinx_zend_array_new_packed(
                    frame->argc == 0u ? 1u : frame->argc
                );
                if (arg_array == NULL) {
                    jinx_zend_array_release(record);
                    jinx_zend_array_release(trace);
                    return result;
                }
                for (size_t i = 0u; i < frame->argc; i++) {
                    if (!jinx_zend_array_append(
                            arg_array, frame->args[i]
                        )) {
                        jinx_zend_array_release(arg_array);
                        jinx_zend_array_release(record);
                        jinx_zend_array_release(trace);
                        return result;
                    }
                }
                if (!jinx_zend_array_add_assoc(
                        record, "args", 4u,
                        jinx_zend_array_value(arg_array)
                    )) {
                    jinx_zend_array_release(arg_array);
                    jinx_zend_array_release(record);
                    jinx_zend_array_release(trace);
                    return result;
                }
                jinx_zend_array_release(arg_array);
            }

            if (!jinx_zend_array_append(
                    trace, jinx_zend_array_value(record)
                )) {
                jinx_zend_array_release(record);
                jinx_zend_array_release(trace);
                return result;
            }
            jinx_zend_array_release(record);
            emitted++;
            frame = frame->previous;
        }

        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(trace);
    }

    if (strcmp(name, "debug_print_backtrace") == 0) {
        JinxZendCallFrame *frame = jinx_oracle_get_caller_frame();
        int64_t options = 0;
        int64_t limit = 0;
        int64_t ignore_args = b2_constant_int(
            "DEBUG_BACKTRACE_IGNORE_ARGS", 2
        );
        size_t emitted = 0u;

        if (argc >= 1u && args != NULL && args[0].type != 0u) {
            options = jinx_oracle_intish(args[0]);
        }
        if (argc >= 2u && args != NULL && args[1].type != 0u) {
            limit = jinx_oracle_intish(args[1]);
            if (limit < 0) return result;
        }
        if (argc > 2u) return result;

        while (frame != NULL &&
               (limit == 0 || (int64_t)emitted < limit)) {
            fprintf(stdout, "#%zu ", emitted);

            if (frame->call_file != NULL &&
                frame->call_file[0] != '\0') {
                fprintf(
                    stdout,
                    "%s(%u): ",
                    frame->call_file,
                    frame->call_line
                );
            } else {
                fputs("[internal function]: ", stdout);
            }

            if (frame->scope_name != NULL &&
                frame->scope_name[0] != '\0') {
                fputs(frame->scope_name, stdout);
                fputs(
                    frame->call_type != NULL &&
                    frame->call_type[0] != '\0'
                        ? frame->call_type
                        : "::",
                    stdout
                );
            }

            fputs(
                frame->function_name != NULL
                    ? frame->function_name
                    : "{main}",
                stdout
            );
            fputc('(', stdout);

            if ((options & ignore_args) == 0) {
                for (size_t i = 0u; i < frame->argc; i++) {
                    if (i != 0u) fputs(", ", stdout);
                    b2_print_trace_value(frame->args[i]);
                }
            }

            fputs(")\n", stdout);
            emitted++;
            frame = frame->previous;
        }

        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "error_clear_last") == 0) {
        JinxZendExecutor *executor = jinx_oracle_get_executor();
        if (executor != NULL) {
            jinx_zend_executor_clear_last_error(executor);
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "error_get_last") == 0) {
        JinxZendExecutor *executor = jinx_oracle_get_executor();
        JinxZendArray *array;
        JinxZendString *message;
        JinxZendString *file;

        if (executor == NULL || executor->last_error == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_zero_value();
        }

        array = jinx_zend_array_new_packed(4u);
        if (array == NULL) return result;

        message = jinx_zend_string_new(
            executor->last_error,
            strlen(executor->last_error)
        );
        file = jinx_zend_string_new(
            executor->last_error_file != NULL ? executor->last_error_file : "",
            executor->last_error_file != NULL
                ? strlen(executor->last_error_file)
                : 0u
        );
        if (message == NULL || file == NULL ||
            !jinx_zend_array_add_assoc(
                array, "type", 4u,
                jinx_zend_long((int64_t)executor->error_level)
            ) ||
            !jinx_zend_array_add_assoc(
                array, "message", 7u,
                jinx_zend_string_value(message)
            ) ||
            !jinx_zend_array_add_assoc(
                array, "file", 4u,
                jinx_zend_string_value(file)
            ) ||
            !jinx_zend_array_add_assoc(
                array, "line", 4u,
                jinx_zend_long((int64_t)executor->last_error_line)
            )) {
            jinx_zend_string_release(message);
            jinx_zend_string_release(file);
            jinx_zend_array_release(array);
            return result;
        }

        jinx_zend_string_release(message);
        jinx_zend_string_release(file);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "get_included_files") == 0 ||
        strcmp(name, "get_required_files") == 0) {
        size_t count = jinx_oracle_script_context_count();
        JinxZendArray *array;

        if (jinx_oracle_script_context_main() == NULL) {
            return result;
        }

        array = jinx_zend_array_new_packed(count == 0u ? 1u : count);
        if (array == NULL) return result;

        for (size_t i = 0u; i < count; i++) {
            const char *path = jinx_oracle_script_context_at(i);
            if (path == NULL || !b2_append_string(array, path)) {
                jinx_zend_array_release(array);
                return result;
            }
        }

        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_array_value_owned(array);
    }

    if (strcmp(name, "getlastmod") == 0 ||
        strcmp(name, "getmyinode") == 0) {
        const char *path = jinx_oracle_script_context_main();
        struct stat st;

        if (path == NULL) return result;

        if (stat(path, &st) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (handled != NULL) *handled = 1;
        return strcmp(name, "getlastmod") == 0
            ? jinx_oracle_int_value((int64_t)st.st_mtime)
            : jinx_oracle_int_value((int64_t)st.st_ino);
    }

    return result;
}
