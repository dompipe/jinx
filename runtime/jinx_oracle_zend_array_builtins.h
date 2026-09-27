#ifndef JINX_ORACLE_ZEND_ARRAY_BUILTINS_H
#define JINX_ORACLE_ZEND_ARRAY_BUILTINS_H

#include "jinx_oracle_zend_array_carrier.h"
#include "jinx_zend_array_delete.h"
#include "jinx_builtin_dispatch.h"

#include <stdlib.h>
#include <string.h>

static inline JinxValue jinx_oracle_zend_bucket_key_value(const JinxZendBucket *bucket) {
    if (bucket == 0) return jinx_oracle_zero_value();
    if (bucket->key != 0) {
        return jinx_oracle_string_value_len(bucket->key->bytes, (uint32_t)bucket->key->len);
    }
    return jinx_oracle_int_value((int64_t)bucket->h);
}

static inline int jinx_oracle_zend_add_bucket(JinxZendArray *target, const JinxZendBucket *bucket, int preserve_numeric) {
    if (target == 0 || bucket == 0) return 0;
    if (bucket->key != 0) {
        return jinx_zend_array_add_assoc(target, bucket->key->bytes, bucket->key->len, bucket->value);
    }
    return preserve_numeric
        ? jinx_zend_array_add_index(target, (size_t)bucket->h, bucket->value)
        : jinx_zend_array_append(target, bucket->value);
}

static inline int jinx_oracle_zend_numeric_value(
    JinxZendValue value,
    int64_t *out_int,
    double *out_double,
    int *is_double
) {
    if (value.type == JINX_ZEND_LONG) {
        *out_int = value.value.lval;
        *out_double = (double)value.value.lval;
        return 1;
    }
    if (value.type == JINX_ZEND_DOUBLE) {
        *out_double = value.value.dval;
        *out_int = (int64_t)value.value.dval;
        *is_double = 1;
        return 1;
    }
    if (value.type == JINX_ZEND_TRUE || value.type == JINX_ZEND_FALSE) {
        *out_int = value.type == JINX_ZEND_TRUE ? 1 : 0;
        *out_double = (double)*out_int;
        return 1;
    }
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        char *end = 0;
        double parsed = strtod(value.value.str->bytes, &end);
        if (end != value.value.str->bytes) {
            *out_double = parsed;
            *out_int = (int64_t)parsed;
            if (strchr(value.value.str->bytes, '.') != 0 ||
                strchr(value.value.str->bytes, 'e') != 0 ||
                strchr(value.value.str->bytes, 'E') != 0) {
                *is_double = 1;
            }
            return 1;
        }
    }
    return 0;
}


static inline JinxValue jinx_oracle_zend_numeric_extreme_array(
    JinxZendArray *array,
    int want_max
) {
    size_t live = jinx_zend_array_live_count(array);
    if (live == 0u) return jinx_oracle_zero_value();

    const JinxZendBucket *first = jinx_zend_array_live_iter_at(array, 0u);
    if (first == 0 ||
        (first->value.type != JINX_ZEND_LONG && first->value.type != JINX_ZEND_DOUBLE)) {
        return jinx_oracle_zero_value();
    }

    JinxZendValue best = first->value;
    double best_value = best.type == JINX_ZEND_DOUBLE
        ? best.value.dval
        : (double)best.value.lval;

    for (size_t i = 1u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0 ||
            (bucket->value.type != JINX_ZEND_LONG && bucket->value.type != JINX_ZEND_DOUBLE)) {
            return jinx_oracle_zero_value();
        }

        double candidate = bucket->value.type == JINX_ZEND_DOUBLE
            ? bucket->value.value.dval
            : (double)bucket->value.value.lval;

        if ((want_max && candidate > best_value) ||
            (!want_max && candidate < best_value)) {
            best = bucket->value;
            best_value = candidate;
        }
    }

    return best.type == JINX_ZEND_DOUBLE
        ? jinx_oracle_float_value(best.value.dval)
        : jinx_oracle_int_value(best.value.lval);
}

static inline JinxValue jinx_oracle_zend_array_sum_product(JinxZendArray *array, int product) {
    int64_t int_acc = product ? 1 : 0;
    double double_acc = product ? 1.0 : 0.0;
    int result_double = 0;
    size_t live = jinx_zend_array_live_count(array);

    for (size_t i = 0; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        int64_t iv = 0;
        double dv = 0.0;
        int value_double = 0;

        if (bucket == 0 || !jinx_oracle_zend_numeric_value(bucket->value, &iv, &dv, &value_double)) continue;

        if (result_double || value_double) {
            if (!result_double) {
                double_acc = (double)int_acc;
                result_double = 1;
            }
            double_acc = product ? double_acc * dv : double_acc + dv;
        } else {
            int_acc = product ? int_acc * iv : int_acc + iv;
        }
    }

    return result_double ? jinx_oracle_float_value(double_acc) : jinx_oracle_int_value(int_acc);
}

static inline JinxZendArray *jinx_oracle_zend_array_reverse_core(JinxZendArray *array, int preserve_numeric) {
    size_t live = jinx_zend_array_live_count(array);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) return 0;

    for (size_t i = live; i > 0u; i--) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i - 1u);
        if (!jinx_oracle_zend_add_bucket(result, bucket, preserve_numeric)) {
            jinx_zend_array_release(result);
            return 0;
        }
    }
    return result;
}

static inline JinxZendArray *jinx_oracle_zend_array_slice_core(
    JinxZendArray *array,
    int64_t offset,
    int has_length,
    int64_t length,
    int preserve_numeric
) {
    size_t live = jinx_zend_array_live_count(array);
    int64_t start = offset < 0 ? (int64_t)live + offset : offset;
    int64_t end;

    if (start < 0) start = 0;
    if (start > (int64_t)live) start = (int64_t)live;

    if (!has_length) {
        end = (int64_t)live;
    } else if (length >= 0) {
        end = start + length;
        if (end > (int64_t)live) end = (int64_t)live;
    } else {
        end = (int64_t)live + length;
        if (end < start) end = start;
    }

    JinxZendArray *result = jinx_zend_array_new_packed((size_t)(end - start) + 1u);
    if (result == 0) return 0;

    for (int64_t i = start; i < end; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, (size_t)i);
        if (!jinx_oracle_zend_add_bucket(result, bucket, preserve_numeric)) {
            jinx_zend_array_release(result);
            return 0;
        }
    }
    return result;
}

static inline JinxZendArray *jinx_oracle_zend_array_merge_core(const JinxValue *args, size_t argc, int replace) {
    JinxZendArray *result = jinx_zend_array_new_packed(4u);
    if (result == 0) return 0;

    for (size_t a = 0u; a < argc; a++) {
        JinxZendArray *array = jinx_oracle_zend_array_ptr(args[a]);
        if (array == 0) {
            jinx_zend_array_release(result);
            return 0;
        }

        size_t live = jinx_zend_array_live_count(array);
        for (size_t i = 0u; i < live; i++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
            int ok;

            if (bucket->key != 0) {
                ok = jinx_zend_array_add_assoc(result, bucket->key->bytes, bucket->key->len, bucket->value);
            } else if (replace) {
                ok = jinx_zend_array_add_index(result, (size_t)bucket->h, bucket->value);
            } else {
                ok = jinx_zend_array_append(result, bucket->value);
            }

            if (!ok) {
                jinx_zend_array_release(result);
                return 0;
            }
        }
    }
    return result;
}

static inline JinxZendArray *jinx_oracle_zend_array_flip_core(JinxZendArray *array) {
    JinxZendArray *result = jinx_zend_array_new_packed(jinx_zend_array_live_count(array) + 1u);
    if (result == 0) return 0;

    size_t live = jinx_zend_array_live_count(array);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        JinxZendValue old_key = bucket->key != 0
            ? jinx_zend_string_value(bucket->key)
            : jinx_zend_long((int64_t)bucket->h);

        if (bucket->value.type == JINX_ZEND_STRING && bucket->value.value.str != 0) {
            if (!jinx_zend_array_add_assoc(
                result,
                bucket->value.value.str->bytes,
                bucket->value.value.str->len,
                old_key
            )) {
                jinx_zend_array_release(result);
                return 0;
            }
        } else if (bucket->value.type == JINX_ZEND_LONG) {
            if (!jinx_zend_array_add_index(result, (size_t)bucket->value.value.lval, old_key)) {
                jinx_zend_array_release(result);
                return 0;
            }
        }
    }
    return result;
}

static inline JinxZendArray *jinx_oracle_zend_array_change_key_case_core(JinxZendArray *array, int upper) {
    JinxZendArray *result = jinx_zend_array_new_packed(jinx_zend_array_live_count(array) + 1u);
    if (result == 0) return 0;

    size_t live = jinx_zend_array_live_count(array);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);

        if (bucket->key == 0) {
            if (!jinx_zend_array_add_index(result, (size_t)bucket->h, bucket->value)) {
                jinx_zend_array_release(result);
                return 0;
            }
            continue;
        }

        char *key = (char *)malloc(bucket->key->len + 1u);
        if (key == 0) {
            jinx_zend_array_release(result);
            return 0;
        }

        for (size_t j = 0u; j < bucket->key->len; j++) {
            unsigned char ch = (unsigned char)bucket->key->bytes[j];
            if (upper && ch >= 'a' && ch <= 'z') ch = (unsigned char)(ch - ('a' - 'A'));
            if (!upper && ch >= 'A' && ch <= 'Z') ch = (unsigned char)(ch + ('a' - 'A'));
            key[j] = (char)ch;
        }
        key[bucket->key->len] = '\0';

        int ok = jinx_zend_array_add_assoc(result, key, bucket->key->len, bucket->value);
        free(key);
        if (!ok) {
            jinx_zend_array_release(result);
            return 0;
        }
    }
    return result;
}


static inline int jinx_oracle_zend_scalar_text_length(JinxZendValue value) {
    if (value.type == JINX_ZEND_NULL || value.type == JINX_ZEND_FALSE) return 0;
    if (value.type == JINX_ZEND_TRUE) return 1;
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) return (int)value.value.str->len;
    if (value.type == JINX_ZEND_LONG) return snprintf(NULL, 0, "%lld", (long long)value.value.lval);
    if (value.type == JINX_ZEND_DOUBLE) return snprintf(NULL, 0, "%g", value.value.dval);
    return 5;
}

static inline uint32_t jinx_oracle_zend_scalar_write(char *out, JinxZendValue value) {
    if (value.type == JINX_ZEND_NULL || value.type == JINX_ZEND_FALSE) return 0u;
    if (value.type == JINX_ZEND_TRUE) { out[0] = '1'; return 1u; }
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        memcpy(out, value.value.str->bytes, value.value.str->len);
        return (uint32_t)value.value.str->len;
    }
    if (value.type == JINX_ZEND_LONG) {
        int n = sprintf(out, "%lld", (long long)value.value.lval);
        return n < 0 ? 0u : (uint32_t)n;
    }
    if (value.type == JINX_ZEND_DOUBLE) {
        int n = sprintf(out, "%g", value.value.dval);
        return n < 0 ? 0u : (uint32_t)n;
    }
    memcpy(out, "Array", 5u);
    return 5u;
}


static inline int jinx_oracle_zend_to_jinx_value(JinxZendValue value, JinxValue *out) {
    if (value.type == JINX_ZEND_NULL) { *out = jinx_oracle_zero_value(); return 1; }
    if (value.type == JINX_ZEND_FALSE) { *out = jinx_oracle_bool_value(0); return 1; }
    if (value.type == JINX_ZEND_TRUE) { *out = jinx_oracle_bool_value(1); return 1; }
    if (value.type == JINX_ZEND_LONG) { *out = jinx_oracle_int_value(value.value.lval); return 1; }
    if (value.type == JINX_ZEND_DOUBLE) { *out = jinx_oracle_float_value(value.value.dval); return 1; }
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        *out = jinx_oracle_string_value_len(value.value.str->bytes, (uint32_t)value.value.str->len);
        return 1;
    }
    if (value.type == JINX_ZEND_ARRAY && value.value.array != 0) {
        *out = jinx_oracle_zend_array_value_borrowed(value.value.array);
        return 1;
    }
    return 0;
}

static inline JinxValue jinx_oracle_zend_vsprintf_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    JinxZendArray *values_array = jinx_oracle_zend_array_ptr(args[1]);
    if (values_array == 0) return jinx_oracle_zero_value();

    size_t count = jinx_zend_array_live_count(values_array);
    if (count > UINT32_MAX) return jinx_oracle_bool_value(0);

    JinxValue *values = count == 0u ? NULL : (JinxValue *)calloc(count, sizeof(JinxValue));
    if (count != 0u && values == NULL) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < count; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(values_array, i);
        if (bucket == 0 || !jinx_oracle_zend_to_jinx_value(bucket->value, &values[i])) {
            free(values);
            return jinx_oracle_zero_value();
        }
    }

    int ok = 0;
    JinxValue result = jinx_oracle_sprintf_values(args[0], values, (uint32_t)count, &ok);
    free(values);
    return ok ? result : jinx_oracle_zero_value();
}


static inline JinxValue jinx_oracle_zend_vprintf_special(const JinxValue *args, size_t argc) {
    JinxValue formatted = jinx_oracle_zend_vsprintf_special(args, argc);
    if (formatted.type != 3u) return jinx_oracle_zero_value();

    if (formatted.flags != 0u &&
        fwrite(formatted.as.ptr, 1u, formatted.flags, stdout) != formatted.flags) {
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_int_value((int64_t)formatted.flags);
}

static inline JinxValue jinx_oracle_zend_implode_special(const JinxValue *args, size_t argc) {
    JinxZendArray *array = 0;
    const unsigned char *separator = (const unsigned char *)"";
    uint32_t separator_len = 0u;

    if (argc == 1u && jinx_oracle_value_is_zend_array(args[0])) {
        array = jinx_oracle_zend_array_ptr(args[0]);
    } else if (argc >= 2u && jinx_oracle_value_is_zend_array(args[1])) {
        array = jinx_oracle_zend_array_ptr(args[1]);
        separator = jinx_oracle_string_bytes(args[0]);
        separator_len = jinx_oracle_string_len(args[0]);
    }

    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    uint64_t needed = live > 0u ? (uint64_t)(live - 1u) * separator_len : 0u;

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        int n = bucket == 0 ? 0 : jinx_oracle_zend_scalar_text_length(bucket->value);
        if (n > 0) needed += (uint64_t)n;
    }

    if (needed > UINT32_MAX) return jinx_oracle_bool_value(0);
    char *out = jinx_oracle_scratch_string((uint32_t)needed);
    uint32_t pos = 0u;

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (i != 0u && separator_len != 0u) {
            memcpy(out + pos, separator, separator_len);
            pos += separator_len;
        }
        if (bucket != 0) pos += jinx_oracle_zend_scalar_write(out + pos, bucket->value);
    }

    return jinx_oracle_string_value_len(out, pos);
}

static inline JinxValue jinx_oracle_zend_count_chars_special(const JinxValue *args, size_t argc) {
    const unsigned char *bytes = jinx_oracle_string_bytes(args[0]);
    uint32_t len = jinx_oracle_string_len(args[0]);
    int mode = argc >= 2u ? (int)jinx_oracle_intish(args[1]) : 0;
    uint32_t counts[256] = {0};

    for (uint32_t i = 0u; i < len; i++) counts[bytes[i]]++;

    if (mode == 3 || mode == 4) {
        char *out = jinx_oracle_scratch_string(256u);
        uint32_t pos = 0u;
        for (uint32_t ch = 0u; ch < 256u; ch++) {
            if ((mode == 3 && counts[ch] != 0u) || (mode == 4 && counts[ch] == 0u)) {
                out[pos++] = (char)ch;
            }
        }
        return jinx_oracle_string_value_len(out, pos);
    }

    if (mode < 0 || mode > 2) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_zend_array_new_packed(256u);
    if (result == 0) return jinx_oracle_zero_value();

    for (uint32_t ch = 0u; ch < 256u; ch++) {
        int include = mode == 0 || (mode == 1 && counts[ch] != 0u) || (mode == 2 && counts[ch] == 0u);
        if (include && !jinx_zend_array_add_index(result, ch, jinx_zend_long((int64_t)counts[ch]))) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline int jinx_oracle_jinx_value_to_zend(JinxValue value, JinxZendValue *out, JinxZendString **owned_string) {
    *owned_string = 0;

    if (value.type == 0u) { *out = jinx_zend_null(); return 1; }
    if (value.type == 1u) { *out = jinx_zend_long(value.as.i64); return 1; }
    if (value.type == 2u) { *out = jinx_zend_bool(value.as.i64 != 0); return 1; }
    if (value.type == 5u) { *out = jinx_zend_double(value.as.f64); return 1; }
    if (value.type == 3u) {
        *owned_string = jinx_zend_string_new((const char *)value.as.ptr, value.flags);
        if (*owned_string == 0) return 0;
        *out = jinx_zend_string_value(*owned_string);
        return 1;
    }
    if (jinx_oracle_value_is_zend_array(value)) {
        *out = jinx_zend_array_value(jinx_oracle_zend_array_ptr(value));
        return 1;
    }

    return 0;
}

static inline uint64_t jinx_oracle_zend_unsigned_distance(int64_t high, int64_t low) {
    return (uint64_t)high - (uint64_t)low;
}

static inline JinxValue jinx_oracle_zend_range_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    int64_t start = jinx_oracle_intish(args[0]);
    int64_t end = jinx_oracle_intish(args[1]);
    int64_t signed_step = argc >= 3u ? jinx_oracle_intish(args[2]) : 1;
    int step_negative = signed_step < 0;
    uint64_t step;

    if (signed_step == 0 || signed_step == INT64_MIN) return jinx_oracle_zero_value();

    step = (uint64_t)(step_negative ? -signed_step : signed_step);

    if (start < end && step_negative) return jinx_oracle_zero_value();

    if (start == end) {
        JinxZendArray *single = jinx_zend_array_new_packed(1u);
        if (single == 0 || !jinx_zend_array_append(single, jinx_zend_long(start))) {
            jinx_zend_array_release(single);
            return jinx_oracle_zero_value();
        }
        return jinx_oracle_zend_array_value_owned(single);
    }

    uint64_t distance = start < end
        ? jinx_oracle_zend_unsigned_distance(end, start)
        : jinx_oracle_zend_unsigned_distance(start, end);

    if (step > distance) return jinx_oracle_zero_value();

    uint64_t count = distance / step + 1u;
    if (count > UINT32_MAX) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_zend_array_new_packed((size_t)count);
    if (result == 0) return jinx_oracle_zero_value();

    for (uint64_t i = 0u; i < count; i++) {
        uint64_t delta = i * step;
        int64_t value = start < end
            ? (int64_t)((uint64_t)start + delta)
            : (int64_t)((uint64_t)start - delta);

        if (!jinx_zend_array_append(result, jinx_zend_long(value))) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_fill_special(const JinxValue *args, size_t argc) {
    if (argc < 3u) return jinx_oracle_zero_value();

    int64_t start = jinx_oracle_intish(args[0]);
    int64_t count = jinx_oracle_intish(args[1]);

    if (count < 0 || count > INT_MAX) return jinx_oracle_zero_value();
    if (count > 0 && start > INT64_MAX - count + 1) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_zend_array_new_packed(count == 0 ? 1u : (size_t)count);
    if (result == 0) return jinx_oracle_zero_value();
    if (count == 0) return jinx_oracle_zend_array_value_owned(result);

    JinxZendValue fill;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(args[2], &fill, &owned_string)) {
        jinx_zend_array_release(result);
        return jinx_oracle_zero_value();
    }

    for (int64_t i = 0; i < count; i++) {
        int64_t key = start + i;
        if (!jinx_zend_array_add_index(result, (size_t)key, fill)) {
            jinx_zend_string_release(owned_string);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    jinx_zend_string_release(owned_string);
    return jinx_oracle_zend_array_value_owned(result);
}


static inline int jinx_oracle_zend_add_scalar_key(
    JinxZendArray *result,
    JinxZendValue key,
    JinxZendValue value
) {
    if (key.type == JINX_ZEND_LONG) {
        return jinx_zend_array_add_index(result, (size_t)key.value.lval, value);
    }

    if (key.type == JINX_ZEND_STRING && key.value.str != 0) {
        return jinx_zend_array_add_symtable(result, key.value.str->bytes, key.value.str->len, value);
    }

    if (key.type == JINX_ZEND_FALSE) {
        return jinx_zend_array_add_index(result, 0u, value);
    }

    if (key.type == JINX_ZEND_TRUE) {
        return jinx_zend_array_add_index(result, 1u, value);
    }

    if (key.type == JINX_ZEND_DOUBLE) {
        return jinx_zend_array_add_index(result, (size_t)(int64_t)key.value.dval, value);
    }

    if (key.type == JINX_ZEND_NULL) {
        return jinx_zend_array_add_assoc(result, "", 0u, value);
    }

    return 0;
}

static inline int jinx_oracle_zend_add_stringified_key(
    JinxZendArray *result,
    JinxZendValue key,
    JinxZendValue value
) {
    if (key.type == JINX_ZEND_LONG) {
        return jinx_zend_array_add_index(result, (size_t)key.value.lval, value);
    }

    if (key.type == JINX_ZEND_STRING && key.value.str != 0) {
        return jinx_zend_array_add_symtable(result, key.value.str->bytes, key.value.str->len, value);
    }

    int length = jinx_oracle_zend_scalar_text_length(key);
    if (length < 0) return 0;

    char *buffer = (char *)malloc((size_t)length + 1u);
    if (buffer == 0) return 0;

    uint32_t written = jinx_oracle_zend_scalar_write(buffer, key);
    buffer[written] = '\0';
    int ok = jinx_zend_array_add_symtable(result, buffer, written, value);
    free(buffer);
    return ok;
}

static inline JinxValue jinx_oracle_zend_array_fill_keys_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    JinxZendArray *keys = jinx_oracle_zend_array_ptr(args[0]);
    if (keys == 0) return jinx_oracle_zero_value();

    JinxZendValue fill;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(args[1], &fill, &owned_string)) return jinx_oracle_bool_value(0);

    JinxZendArray *result = jinx_zend_array_new_packed(jinx_zend_array_live_count(keys) + 1u);
    if (result == 0) { jinx_zend_string_release(owned_string); return jinx_oracle_zero_value(); }

    size_t live = jinx_zend_array_live_count(keys);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(keys, i);
        JinxZendValue key = bucket->value;
        int ok = jinx_oracle_zend_add_stringified_key(result, key, fill);
        if (!ok) {
            jinx_zend_string_release(owned_string);
            jinx_zend_array_release(result);
            return jinx_oracle_bool_value(0);
        }
    }

    jinx_zend_string_release(owned_string);
    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_combine_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();
    JinxZendArray *keys = jinx_oracle_zend_array_ptr(args[0]);
    JinxZendArray *values = jinx_oracle_zend_array_ptr(args[1]);
    if (keys == 0 || values == 0) return jinx_oracle_zero_value();

    size_t count = jinx_zend_array_live_count(keys);
    if (count != jinx_zend_array_live_count(values)) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_zend_array_new_packed(count + 1u);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < count; i++) {
        const JinxZendBucket *kb = jinx_zend_array_live_iter_at(keys, i);
        const JinxZendBucket *vb = jinx_zend_array_live_iter_at(values, i);
        int ok = jinx_oracle_zend_add_stringified_key(result, kb->value, vb->value);
        if (!ok) { jinx_zend_array_release(result); return jinx_oracle_zero_value(); }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_count_values_special(const JinxValue *args, size_t argc) {
    (void)argc;
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_zend_array_new_packed(jinx_zend_array_live_count(array) + 1u);
    if (result == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        JinxZendValue *slot = 0;

        if (bucket->value.type == JINX_ZEND_LONG) {
            size_t key = (size_t)bucket->value.value.lval;
            slot = jinx_zend_array_index(result, key);
            int64_t next = slot != 0 && slot->type == JINX_ZEND_LONG ? slot->value.lval + 1 : 1;
            if (!jinx_zend_array_add_index(result, key, jinx_zend_long(next))) {
                jinx_zend_array_release(result); return jinx_oracle_zero_value();
            }
        } else if (bucket->value.type == JINX_ZEND_STRING && bucket->value.value.str != 0) {
            int64_t numeric_key;
            if (jinx_zend_array_numeric_string_key(
                bucket->value.value.str->bytes,
                bucket->value.value.str->len,
                &numeric_key
            )) {
                slot = jinx_zend_array_index(result, (size_t)numeric_key);
            } else {
                slot = jinx_zend_array_find(result, bucket->value.value.str->bytes, bucket->value.value.str->len);
            }
            int64_t next = slot != 0 && slot->type == JINX_ZEND_LONG ? slot->value.lval + 1 : 1;
            if (!jinx_zend_array_add_symtable(
                result,
                bucket->value.value.str->bytes,
                bucket->value.value.str->len,
                jinx_zend_long(next)
            )) {
                jinx_zend_array_release(result); return jinx_oracle_zero_value();
            }
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}


static inline int jinx_oracle_zend_scalar_text_equal(JinxZendValue a, JinxZendValue b) {
    int a_len = jinx_oracle_zend_scalar_text_length(a);
    int b_len = jinx_oracle_zend_scalar_text_length(b);
    if (a_len < 0 || b_len < 0 || a_len != b_len) return 0;
    if (a_len == 0) return 1;

    char *a_buf = (char *)malloc((size_t)a_len);
    char *b_buf = (char *)malloc((size_t)b_len);
    if (a_buf == 0 || b_buf == 0) {
        free(a_buf);
        free(b_buf);
        return 0;
    }

    uint32_t a_written = jinx_oracle_zend_scalar_write(a_buf, a);
    uint32_t b_written = jinx_oracle_zend_scalar_write(b_buf, b);
    int equal = a_written == b_written && a_written == (uint32_t)a_len &&
        memcmp(a_buf, b_buf, a_written) == 0;

    free(a_buf);
    free(b_buf);
    return equal;
}

static inline int jinx_oracle_zend_bucket_key_equal(const JinxZendBucket *a, const JinxZendBucket *b) {
    if (a == 0 || b == 0) return 0;
    if (a->key == 0 && b->key == 0) return a->h == b->h;
    if (a->key == 0 || b->key == 0) return 0;
    return a->key->len == b->key->len &&
        memcmp(a->key->bytes, b->key->bytes, a->key->len) == 0;
}

static inline int jinx_oracle_zend_array_contains_value_text(JinxZendArray *array, JinxZendValue value) {
    size_t live = jinx_zend_array_live_count(array);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket != 0 && jinx_oracle_zend_scalar_text_equal(bucket->value, value)) return 1;
    }
    return 0;
}

static inline int jinx_oracle_zend_array_contains_key(const JinxZendArray *array, const JinxZendBucket *needle) {
    size_t live = jinx_zend_array_live_count(array);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (jinx_oracle_zend_bucket_key_equal(bucket, needle)) return 1;
    }
    return 0;
}

static inline int jinx_oracle_zend_array_contains_assoc(
    const JinxZendArray *array,
    const JinxZendBucket *needle
) {
    size_t live = jinx_zend_array_live_count(array);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (jinx_oracle_zend_bucket_key_equal(bucket, needle) &&
            jinx_oracle_zend_scalar_text_equal(bucket->value, needle->value)) {
            return 1;
        }
    }
    return 0;
}

static inline JinxValue jinx_oracle_zend_array_chunk_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    int64_t length = jinx_oracle_intish(args[1]);
    int preserve = argc >= 3u && jinx_oracle_boolish(args[2]);
    if (array == 0 || length < 1) return jinx_oracle_bool_value(0);

    size_t live = jinx_zend_array_live_count(array);
    size_t chunks = live == 0u ? 0u : (live + (size_t)length - 1u) / (size_t)length;
    JinxZendArray *outer = jinx_zend_array_new_packed(chunks == 0u ? 1u : chunks);
    if (outer == 0) return jinx_oracle_zero_value();

    size_t pos = 0u;
    while (pos < live) {
        size_t take = live - pos;
        if (take > (size_t)length) take = (size_t)length;

        JinxZendArray *chunk = jinx_zend_array_new_packed(take == 0u ? 1u : take);
        if (chunk == 0) {
            jinx_zend_array_release(outer);
            return jinx_oracle_zero_value();
        }

        for (size_t i = 0u; i < take; i++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, pos + i);
            if (!jinx_oracle_zend_add_bucket(chunk, bucket, preserve)) {
                jinx_zend_array_release(chunk);
                jinx_zend_array_release(outer);
                return jinx_oracle_zero_value();
            }
        }

        if (!jinx_zend_array_append(outer, jinx_zend_array_value(chunk))) {
            jinx_zend_array_release(chunk);
            jinx_zend_array_release(outer);
            return jinx_oracle_zero_value();
        }
        jinx_zend_array_release(chunk);
        pos += take;
    }

    return jinx_oracle_zend_array_value_owned(outer);
}

static inline JinxValue jinx_oracle_zend_array_pad_special(const JinxValue *args, size_t argc) {
    if (argc < 3u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    int64_t requested = jinx_oracle_intish(args[1]);
    uint64_t target = requested < 0 ? (uint64_t)(-(requested + 1)) + 1u : (uint64_t)requested;
    size_t live = jinx_zend_array_live_count(array);

    JinxZendValue pad;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(args[2], &pad, &owned_string)) return jinx_oracle_bool_value(0);

    JinxZendArray *result = jinx_zend_array_new_packed(target > live ? (size_t)target : live + 1u);
    if (result == 0) {
        jinx_zend_string_release(owned_string);
        return jinx_oracle_zero_value();
    }

    if (target <= live) {
        for (size_t i = 0u; i < live; i++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
            if (!jinx_oracle_zend_add_bucket(result, bucket, 1)) {
                jinx_zend_string_release(owned_string);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }
        jinx_zend_string_release(owned_string);
        return jinx_oracle_zend_array_value_owned(result);
    }

    size_t pad_count = (size_t)(target - live);
    if (requested < 0) {
        for (size_t i = 0u; i < pad_count; i++) {
            if (!jinx_zend_array_append(result, pad)) {
                jinx_zend_string_release(owned_string);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }
    }

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (!jinx_oracle_zend_add_bucket(result, bucket, 0)) {
            jinx_zend_string_release(owned_string);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    if (requested > 0) {
        for (size_t i = 0u; i < pad_count; i++) {
            if (!jinx_zend_array_append(result, pad)) {
                jinx_zend_string_release(owned_string);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }
    }

    jinx_zend_string_release(owned_string);
    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_unique_default(const JinxValue *args, size_t argc) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    /* PHP default is SORT_STRING (2). Keep alternate comparison modes on fallback. */
    if (argc >= 2u && jinx_oracle_intish(args[1]) != 2) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (!jinx_oracle_zend_array_contains_value_text(result, bucket->value) &&
            !jinx_oracle_zend_add_bucket(result, bucket, 1)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_diff_intersect_special(
    const char *name,
    const JinxValue *args,
    size_t argc
) {
    JinxZendArray *first = jinx_oracle_zend_array_ptr(args[0]);
    if (first == 0) return jinx_oracle_zero_value();

    int intersect = strncmp(name, "array_intersect", 15u) == 0;
    int key_only = strcmp(name, "array_diff_key") == 0 || strcmp(name, "array_intersect_key") == 0;
    int assoc = strcmp(name, "array_diff_assoc") == 0 || strcmp(name, "array_intersect_assoc") == 0;
    size_t live = jinx_zend_array_live_count(first);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(first, i);
        int matched_any = 0;
        int matched_all = argc > 1u ? 1 : 0;

        for (size_t a = 1u; a < argc; a++) {
            JinxZendArray *other = jinx_oracle_zend_array_ptr(args[a]);
            if (other == 0) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }

            int matched = key_only
                ? jinx_oracle_zend_array_contains_key(other, bucket)
                : (assoc
                    ? jinx_oracle_zend_array_contains_assoc(other, bucket)
                    : jinx_oracle_zend_array_contains_value_text(other, bucket->value));

            if (matched) matched_any = 1;
            else matched_all = 0;

            if (!intersect && matched_any) break;
            if (intersect && !matched_all) break;
        }

        if ((intersect && matched_all) || (!intersect && !matched_any)) {
            if (!jinx_oracle_zend_add_bucket(result, bucket, 1)) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}


static inline int64_t jinx_oracle_zend_find_bytes(
    const unsigned char *haystack,
    uint32_t haystack_len,
    const unsigned char *needle,
    uint32_t needle_len,
    uint32_t start
) {
    if (needle_len == 0u || start > haystack_len || needle_len > haystack_len - start) return -1;
    for (uint32_t i = start; i + needle_len <= haystack_len; i++) {
        if (memcmp(haystack + i, needle, needle_len) == 0) return (int64_t)i;
    }
    return -1;
}

static inline int jinx_oracle_zend_append_string_slice(
    JinxZendArray *array,
    const unsigned char *bytes,
    uint32_t start,
    uint32_t length
) {
    JinxZendString *string = jinx_zend_string_new((const char *)bytes + start, length);
    if (string == 0) return 0;
    int ok = jinx_zend_array_append(array, jinx_zend_string_value(string));
    jinx_zend_string_release(string);
    return ok;
}

static inline JinxValue jinx_oracle_zend_explode_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    const unsigned char *separator = jinx_oracle_string_bytes(args[0]);
    uint32_t separator_len = jinx_oracle_string_len(args[0]);
    const unsigned char *text = jinx_oracle_string_bytes(args[1]);
    uint32_t text_len = jinx_oracle_string_len(args[1]);
    int64_t limit = argc >= 3u ? jinx_oracle_intish(args[2]) : INT64_MAX;

    if (separator_len == 0u) return jinx_oracle_zero_value();
    if (limit == 0) limit = 1;

    uint64_t total_parts = 1u;
    uint32_t scan = 0u;
    while (scan <= text_len) {
        int64_t found = jinx_oracle_zend_find_bytes(text, text_len, separator, separator_len, scan);
        if (found < 0) break;
        total_parts++;
        scan = (uint32_t)found + separator_len;
    }

    uint64_t keep_parts = total_parts;
    if (limit < 0) {
        uint64_t drop = (uint64_t)(-(limit + 1)) + 1u;
        keep_parts = drop >= total_parts ? 0u : total_parts - drop;
    } else if ((uint64_t)limit < keep_parts) {
        keep_parts = (uint64_t)limit;
    }

    JinxZendArray *result = jinx_zend_array_new_packed(keep_parts == 0u ? 1u : (size_t)keep_parts);
    if (result == 0) return jinx_oracle_zero_value();
    if (keep_parts == 0u) return jinx_oracle_zend_array_value_owned(result);

    uint32_t start = 0u;
    uint64_t part = 0u;
    while (part + 1u < keep_parts) {
        int64_t found = jinx_oracle_zend_find_bytes(text, text_len, separator, separator_len, start);
        if (found < 0) break;
        if (!jinx_oracle_zend_append_string_slice(result, text, start, (uint32_t)found - start)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
        start = (uint32_t)found + separator_len;
        part++;
    }

    if (limit > 0 && keep_parts < total_parts) {
        if (!jinx_oracle_zend_append_string_slice(result, text, start, text_len - start)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    } else {
        int64_t found = jinx_oracle_zend_find_bytes(text, text_len, separator, separator_len, start);
        if (found >= 0 && part + 1u == keep_parts && limit < 0) {
            if (!jinx_oracle_zend_append_string_slice(result, text, start, (uint32_t)found - start)) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        } else if (!jinx_oracle_zend_append_string_slice(result, text, start, text_len - start)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_str_split_special(const JinxValue *args, size_t argc) {
    if (argc < 1u) return jinx_oracle_zero_value();

    const unsigned char *text = jinx_oracle_string_bytes(args[0]);
    uint32_t text_len = jinx_oracle_string_len(args[0]);
    int64_t length = argc >= 2u ? jinx_oracle_intish(args[1]) : 1;

    if (length < 1) return jinx_oracle_zero_value();

    uint64_t count = text_len == 0u ? 0u : ((uint64_t)text_len + (uint64_t)length - 1u) / (uint64_t)length;
    JinxZendArray *result = jinx_zend_array_new_packed(count == 0u ? 1u : (size_t)count);
    if (result == 0) return jinx_oracle_zero_value();

    for (uint32_t start = 0u; start < text_len;) {
        uint32_t take = text_len - start;
        if ((uint64_t)take > (uint64_t)length) take = (uint32_t)length;
        if (!jinx_oracle_zend_append_string_slice(result, text, start, take)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
        start += take;
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxZendValue *jinx_oracle_zend_row_value(JinxZendArray *row, JinxValue key) {
    if (row == 0) return 0;
    if (key.type == 1u) return jinx_zend_array_index(row, (size_t)key.as.i64);
    if (key.type == 3u) {
        int64_t numeric_key;
        if (jinx_zend_array_numeric_string_key(
            (const char *)key.as.ptr,
            (size_t)key.flags,
            &numeric_key
        )) {
            return jinx_zend_array_index(row, (size_t)numeric_key);
        }
        return jinx_zend_array_find(row, (const char *)key.as.ptr, (size_t)key.flags);
    }
    return 0;
}

static inline int jinx_oracle_zend_array_column_insert(
    JinxZendArray *result,
    JinxZendValue value,
    JinxZendValue *index_value
) {
    if (index_value == 0) return jinx_zend_array_append(result, value);
    return jinx_oracle_zend_add_scalar_key(result, *index_value, value);
}

static inline JinxValue jinx_oracle_zend_array_column_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    JinxZendArray *rows = jinx_oracle_zend_array_ptr(args[0]);
    if (rows == 0) return jinx_oracle_zero_value();

    int whole_row = args[1].type == 0u;
    int use_index = argc >= 3u && args[2].type != 0u;
    size_t live = jinx_zend_array_live_count(rows);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *row_bucket = jinx_zend_array_live_iter_at(rows, i);
        if (row_bucket == 0 || row_bucket->value.type != JINX_ZEND_ARRAY || row_bucket->value.value.array == 0) continue;

        JinxZendArray *row = row_bucket->value.value.array;
        const JinxZendValue *column_value = whole_row ? &row_bucket->value : jinx_oracle_zend_row_value(row, args[1]);
        if (column_value == 0) continue;

        JinxZendValue *index_value = use_index ? jinx_oracle_zend_row_value(row, args[2]) : 0;
        if (!jinx_oracle_zend_array_column_insert(result, *column_value, index_value)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}


static inline int64_t jinx_oracle_zend_count_recursive_inner(
    JinxZendArray *array,
    JinxZendArray **path,
    size_t depth
) {
    if (array == 0 || depth >= 128u) return 0;

    for (size_t i = 0u; i < depth; i++) {
        if (path[i] == array) return 0;
    }

    path[depth] = array;
    int64_t total = (int64_t)jinx_zend_array_live_count(array);
    size_t live = jinx_zend_array_live_count(array);

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket != 0 && bucket->value.type == JINX_ZEND_ARRAY && bucket->value.value.array != 0) {
            total += jinx_oracle_zend_count_recursive_inner(bucket->value.value.array, path, depth + 1u);
        }
    }

    return total;
}

static inline JinxValue jinx_oracle_zend_count_value(const JinxValue *args, size_t argc) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    int64_t mode = argc >= 2u ? jinx_oracle_intish(args[1]) : 0;
    if (mode == 0) return jinx_oracle_int_value((int64_t)jinx_zend_array_live_count(array));
    if (mode != 1) return jinx_oracle_zero_value();

    JinxZendArray *path[128] = {0};
    return jinx_oracle_int_value(jinx_oracle_zend_count_recursive_inner(array, path, 0u));
}

static inline int jinx_oracle_zend_value_boolish(JinxZendValue value) {
    if (value.type == JINX_ZEND_NULL || value.type == JINX_ZEND_FALSE) return 0;
    if (value.type == JINX_ZEND_TRUE) return 1;
    if (value.type == JINX_ZEND_LONG) return value.value.lval != 0;
    if (value.type == JINX_ZEND_DOUBLE) return value.value.dval != 0.0;
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        return value.value.str->len != 0u &&
            !(value.value.str->len == 1u && value.value.str->bytes[0] == '0');
    }
    if (value.type == JINX_ZEND_ARRAY && value.value.array != 0) {
        return jinx_zend_array_live_count(value.value.array) != 0u;
    }
    return 1;
}

static inline int jinx_oracle_zend_value_strict_equal(JinxZendValue a, JinxZendValue b) {
    if (a.type != b.type) return 0;
    if (a.type == JINX_ZEND_NULL || a.type == JINX_ZEND_FALSE || a.type == JINX_ZEND_TRUE) return 1;
    if (a.type == JINX_ZEND_LONG) return a.value.lval == b.value.lval;
    if (a.type == JINX_ZEND_DOUBLE) return a.value.dval == b.value.dval;
    if (a.type == JINX_ZEND_STRING) {
        if (a.value.str == 0 || b.value.str == 0) return a.value.str == b.value.str;
        return a.value.str->len == b.value.str->len &&
            memcmp(a.value.str->bytes, b.value.str->bytes, a.value.str->len) == 0;
    }
    return 0;
}

static inline int jinx_oracle_zend_string_numeric(JinxZendString *string, double *number) {
    if (string == 0 || string->bytes == 0) return 0;
    char *end = 0;
    double parsed = strtod(string->bytes, &end);
    if (end == string->bytes) return 0;
    while (*end == ' ' || *end == '\t' || *end == '\r' || *end == '\n' || *end == '\f' || *end == '\v') end++;
    if (*end != '\0') return 0;
    *number = parsed;
    return 1;
}

static inline int jinx_oracle_zend_value_loose_equal(JinxZendValue a, JinxZendValue b) {
    if (a.type == b.type) return jinx_oracle_zend_value_strict_equal(a, b);

    if (a.type == JINX_ZEND_NULL || a.type == JINX_ZEND_FALSE || a.type == JINX_ZEND_TRUE ||
        b.type == JINX_ZEND_NULL || b.type == JINX_ZEND_FALSE || b.type == JINX_ZEND_TRUE) {
        return jinx_oracle_zend_value_boolish(a) == jinx_oracle_zend_value_boolish(b);
    }

    int a_number = a.type == JINX_ZEND_LONG || a.type == JINX_ZEND_DOUBLE;
    int b_number = b.type == JINX_ZEND_LONG || b.type == JINX_ZEND_DOUBLE;

    if (a_number && b_number) {
        double av = a.type == JINX_ZEND_DOUBLE ? a.value.dval : (double)a.value.lval;
        double bv = b.type == JINX_ZEND_DOUBLE ? b.value.dval : (double)b.value.lval;
        return av == bv;
    }

    if (a_number && b.type == JINX_ZEND_STRING) {
        double bv;
        if (jinx_oracle_zend_string_numeric(b.value.str, &bv)) {
            double av = a.type == JINX_ZEND_DOUBLE ? a.value.dval : (double)a.value.lval;
            return av == bv;
        }
    }

    if (b_number && a.type == JINX_ZEND_STRING) {
        double av;
        if (jinx_oracle_zend_string_numeric(a.value.str, &av)) {
            double bv = b.type == JINX_ZEND_DOUBLE ? b.value.dval : (double)b.value.lval;
            return av == bv;
        }
    }

    return jinx_oracle_zend_scalar_text_equal(a, b);
}

static inline JinxValue jinx_oracle_zend_array_keys_value(const JinxValue *args, size_t argc) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();
    if (argc < 2u) return jinx_oracle_zend_array_value_owned(jinx_zend_array_live_keys(array));

    JinxZendValue filter;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(args[1], &filter, &owned_string)) return jinx_oracle_zero_value();
    if (filter.type == JINX_ZEND_ARRAY || filter.type == JINX_ZEND_OBJECT ||
        filter.type == JINX_ZEND_REFERENCE || filter.type == JINX_ZEND_RESOURCE) {
        jinx_zend_string_release(owned_string);
        return jinx_oracle_zero_value();
    }

    int strict = argc >= 3u && jinx_oracle_boolish(args[2]);
    size_t live = jinx_zend_array_live_count(array);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) {
        jinx_zend_string_release(owned_string);
        return jinx_oracle_zero_value();
    }

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        int equal = strict
            ? jinx_oracle_zend_value_strict_equal(bucket->value, filter)
            : jinx_oracle_zend_value_loose_equal(bucket->value, filter);
        if (!equal) continue;

        JinxZendValue key = bucket->key != 0
            ? jinx_zend_string_value(bucket->key)
            : jinx_zend_long((int64_t)bucket->h);
        if (!jinx_zend_array_append(result, key)) {
            jinx_zend_string_release(owned_string);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    jinx_zend_string_release(owned_string);
    return jinx_oracle_zend_array_value_owned(result);
}

static inline void jinx_oracle_zend_charmask(
    const unsigned char *input,
    uint32_t len,
    unsigned char mask[256]
) {
    memset(mask, 0, 256u);

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = input[i];

        if (i + 3u < len &&
            input[i + 1u] == (unsigned char)'.' &&
            input[i + 2u] == (unsigned char)'.' &&
            input[i + 3u] >= ch) {
            memset(mask + ch, 1, (size_t)(input[i + 3u] - ch + 1u));
            i += 3u;
            continue;
        }

        /*
         * PHP emits warnings for malformed ".." ranges and otherwise keeps
         * processing the mask. Warning transport is not modeled here, so the
         * byte-result path follows php_charmask's useful mask behavior.
         */
        if (i + 1u < len && input[i] == (unsigned char)'.' && input[i + 1u] == (unsigned char)'.') {
            continue;
        }

        mask[ch] = 1u;
    }
}

static inline int jinx_oracle_zend_word_char(
    unsigned char ch,
    const unsigned char *mask,
    int has_mask
) {
    return isalpha((int)ch) ||
        (has_mask && mask[ch]) ||
        ch == (unsigned char)'\'' ||
        ch == (unsigned char)'-';
}

static inline JinxValue jinx_oracle_zend_str_word_count_special(const JinxValue *args, size_t argc) {
    if (argc < 1u) return jinx_oracle_zero_value();

    const unsigned char *text = jinx_oracle_string_bytes(args[0]);
    uint32_t len = jinx_oracle_string_len(args[0]);
    int64_t mode = argc >= 2u ? jinx_oracle_intish(args[1]) : 0;
    unsigned char mask[256];
    int has_mask = argc >= 3u && args[2].type != 0u;
    uint32_t word_count = 0u;

    if (mode < 0 || mode > 2) return jinx_oracle_zero_value();

    if (has_mask) {
        jinx_oracle_zend_charmask(
            jinx_oracle_string_bytes(args[2]),
            jinx_oracle_string_len(args[2]),
            mask
        );
    } else {
        memset(mask, 0, sizeof(mask));
    }

    if (len == 0u) {
        if (mode == 0) return jinx_oracle_int_value(0);
        return jinx_oracle_zend_array_value_owned(jinx_zend_array_new_packed(1u));
    }

    uint32_t start_limit = 0u;
    uint32_t end_limit = len;

    if ((text[0] == (unsigned char)'\'' && (!has_mask || !mask[(unsigned char)'\''])) ||
        (text[0] == (unsigned char)'-' && (!has_mask || !mask[(unsigned char)'-']))) {
        start_limit = 1u;
    }

    if (end_limit > start_limit &&
        text[end_limit - 1u] == (unsigned char)'-' &&
        (!has_mask || !mask[(unsigned char)'-'])) {
        end_limit--;
    }

    JinxZendArray *result = NULL;
    if (mode != 0) {
        result = jinx_zend_array_new_packed(8u);
        if (result == 0) return jinx_oracle_zero_value();
    }

    uint32_t p = start_limit;
    while (p < end_limit) {
        uint32_t start = p;

        while (p < end_limit && jinx_oracle_zend_word_char(text[p], mask, has_mask)) {
            p++;
        }

        if (p > start) {
            if (mode == 0) {
                word_count++;
            } else {
                JinxZendString *word = jinx_zend_string_new((const char *)text + start, p - start);
                if (word == 0) {
                    jinx_zend_array_release(result);
                    return jinx_oracle_zero_value();
                }

                int ok = mode == 1
                    ? jinx_zend_array_append(result, jinx_zend_string_value(word))
                    : jinx_zend_array_add_index(result, start, jinx_zend_string_value(word));
                jinx_zend_string_release(word);

                if (!ok) {
                    jinx_zend_array_release(result);
                    return jinx_oracle_zero_value();
                }
            }
        }

        p++;
    }

    return mode == 0
        ? jinx_oracle_int_value((int64_t)word_count)
        : jinx_oracle_zend_array_value_owned(result);
}


static inline JinxValue jinx_oracle_zend_in_array_search_special(
    const char *name,
    const JinxValue *args,
    size_t argc
) {
    if (argc < 2u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[1]);
    if (array == 0) return jinx_oracle_zero_value();

    JinxZendValue needle;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(args[0], &needle, &owned_string)) {
        return jinx_oracle_zero_value();
    }

    if (needle.type == JINX_ZEND_ARRAY || needle.type == JINX_ZEND_OBJECT ||
        needle.type == JINX_ZEND_REFERENCE || needle.type == JINX_ZEND_RESOURCE) {
        jinx_zend_string_release(owned_string);
        return jinx_oracle_zero_value();
    }

    int strict = argc >= 3u && jinx_oracle_boolish(args[2]);
    size_t live = jinx_zend_array_live_count(array);

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        int equal = strict
            ? jinx_oracle_zend_value_strict_equal(bucket->value, needle)
            : jinx_oracle_zend_value_loose_equal(bucket->value, needle);

        if (!equal) continue;

        jinx_zend_string_release(owned_string);
        if (strcmp(name, "in_array") == 0) return jinx_oracle_bool_value(1);
        return jinx_oracle_zend_bucket_key_value(bucket);
    }

    jinx_zend_string_release(owned_string);
    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_zend_array_filter_default(
    const JinxValue *args,
    size_t argc
) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    /* Exact native subset: omitted/null callback with ARRAY_FILTER_USE_BOTH/USE_KEY not requested. */
    if (argc >= 2u && args[1].type != 0u) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket != 0 && jinx_oracle_zend_value_boolish(bucket->value)) {
            if (!jinx_oracle_zend_add_bucket(result, bucket, 1)) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}


static inline JinxValue jinx_oracle_zend_value_return_copy(JinxZendValue value) {
    if (value.type == JINX_ZEND_NULL) return jinx_oracle_zero_value();
    if (value.type == JINX_ZEND_FALSE) return jinx_oracle_bool_value(0);
    if (value.type == JINX_ZEND_TRUE) return jinx_oracle_bool_value(1);
    if (value.type == JINX_ZEND_LONG) return jinx_oracle_int_value(value.value.lval);
    if (value.type == JINX_ZEND_DOUBLE) return jinx_oracle_float_value(value.value.dval);

    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        uint32_t len = value.value.str->len > UINT32_MAX ? UINT32_MAX : (uint32_t)value.value.str->len;
        char *out = jinx_oracle_scratch_string(len);
        if (len != 0u) memcpy(out, value.value.str->bytes, len);
        return jinx_oracle_string_value_len(out, len);
    }

    if (value.type == JINX_ZEND_ARRAY && value.value.array != 0) {
        return jinx_oracle_zend_array_value_retained(value.value.array);
    }

    return jinx_oracle_zero_value();
}

static inline void jinx_oracle_zend_array_adopt_contents(
    JinxZendArray *target,
    JinxZendArray *source
) {
    if (target == 0 || source == 0) return;

    if (target->buckets != 0) {
        for (size_t i = 0u; i < target->count; i++) {
            if (!jinx_zend_bucket_is_tombstone(&target->buckets[i])) {
                jinx_zend_string_release(target->buckets[i].key);
                jinx_zend_value_release(target->buckets[i].value);
            }
        }
        free(target->buckets);
    }

    target->flags = source->flags;
    target->count = source->count;
    target->capacity = source->capacity;
    target->next_index = source->next_index;
    target->internal_pointer = source->internal_pointer;
    target->buckets = source->buckets;

    source->buckets = 0;
    source->count = 0u;
    source->capacity = 0u;
    source->next_index = 0u;
    source->internal_pointer = 0u;
    free(source);
}

static inline JinxValue jinx_oracle_zend_array_push_special(const JinxValue *args, size_t argc) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    for (size_t i = 1u; i < argc; i++) {
        JinxZendValue value;
        JinxZendString *owned_string = 0;
        if (!jinx_oracle_jinx_value_to_zend(args[i], &value, &owned_string)) return jinx_oracle_zero_value();

        int ok = jinx_zend_array_append(array, value);
        jinx_zend_string_release(owned_string);
        if (!ok) return jinx_oracle_zero_value();
    }

    return jinx_oracle_int_value((int64_t)jinx_zend_array_live_count(array));
}

static inline JinxValue jinx_oracle_zend_array_pop_special(const JinxValue *args, size_t argc) {
    (void)argc;
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    if (live == 0u) return jinx_oracle_zero_value();

    size_t seen = 0u;
    for (size_t i = 0u; i < array->count; i++) {
        JinxZendBucket *bucket = &array->buckets[i];
        if (jinx_zend_bucket_is_tombstone(bucket)) continue;
        seen++;
        if (seen != live) continue;

        JinxValue result = jinx_oracle_zend_value_return_copy(bucket->value);
        int numeric = bucket->key == 0;
        uint64_t numeric_key = bucket->h;

        jinx_zend_bucket_mark_tombstone(bucket);
        jinx_zend_array_compact(array);

        if (numeric && numeric_key != UINT64_MAX &&
            array->next_index == (size_t)(numeric_key + 1u)) {
            array->next_index = (size_t)numeric_key;
        }

        return result;
    }

    return jinx_oracle_zero_value();
}

static inline JinxValue jinx_oracle_zend_array_shift_special(const JinxValue *args, size_t argc) {
    (void)argc;
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    if (live == 0u) return jinx_oracle_zero_value();

    const JinxZendBucket *first = jinx_zend_array_live_iter_at(array, 0u);
    if (first == 0) return jinx_oracle_zero_value();

    JinxValue result = jinx_oracle_zend_value_return_copy(first->value);
    JinxZendArray *rebuilt = jinx_zend_array_new_packed(live);
    if (rebuilt == 0) return jinx_oracle_zero_value();

    for (size_t i = 1u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (!jinx_oracle_zend_add_bucket(rebuilt, bucket, 0)) {
            jinx_zend_array_release(rebuilt);
            jinx_oracle_zend_array_value_release(result);
            return jinx_oracle_zero_value();
        }
    }

    jinx_oracle_zend_array_adopt_contents(array, rebuilt);
    return result;
}

static inline JinxValue jinx_oracle_zend_array_unshift_special(const JinxValue *args, size_t argc) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    JinxZendArray *rebuilt = jinx_zend_array_new_packed(live + argc + 1u);
    if (rebuilt == 0) return jinx_oracle_zero_value();

    for (size_t i = 1u; i < argc; i++) {
        JinxZendValue value;
        JinxZendString *owned_string = 0;
        if (!jinx_oracle_jinx_value_to_zend(args[i], &value, &owned_string)) {
            jinx_zend_array_release(rebuilt);
            return jinx_oracle_zero_value();
        }

        int ok = jinx_zend_array_append(rebuilt, value);
        jinx_zend_string_release(owned_string);
        if (!ok) {
            jinx_zend_array_release(rebuilt);
            return jinx_oracle_zero_value();
        }
    }

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (!jinx_oracle_zend_add_bucket(rebuilt, bucket, 0)) {
            jinx_zend_array_release(rebuilt);
            return jinx_oracle_zero_value();
        }
    }

    jinx_oracle_zend_array_adopt_contents(array, rebuilt);
    return jinx_oracle_int_value((int64_t)jinx_zend_array_live_count(array));
}


static inline JinxValue jinx_oracle_zend_array_splice_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    int64_t offset = jinx_oracle_intish(args[1]);
    int64_t start = offset < 0 ? (int64_t)live + offset : offset;
    if (start < 0) start = 0;
    if (start > (int64_t)live) start = (int64_t)live;

    int64_t end;
    if (argc < 3u || args[2].type == 0u) {
        end = (int64_t)live;
    } else {
        int64_t length = jinx_oracle_intish(args[2]);
        if (length >= 0) {
            end = start + length;
            if (end > (int64_t)live) end = (int64_t)live;
        } else {
            end = (int64_t)live + length;
            if (end < start) end = start;
        }
    }

    JinxZendArray *removed = jinx_zend_array_new_packed((size_t)(end - start) + 1u);
    JinxZendArray *rebuilt = jinx_zend_array_new_packed(live + 4u);
    if (removed == 0 || rebuilt == 0) {
        jinx_zend_array_release(removed);
        jinx_zend_array_release(rebuilt);
        return jinx_oracle_zero_value();
    }

    for (int64_t i = 0; i < start; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, (size_t)i);
        if (!jinx_oracle_zend_add_bucket(rebuilt, bucket, 0)) goto fail;
    }

    for (int64_t i = start; i < end; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, (size_t)i);
        if (!jinx_oracle_zend_add_bucket(removed, bucket, 0)) goto fail;
    }

    if (argc >= 4u && args[3].type != 0u) {
        if (jinx_oracle_value_is_zend_array(args[3])) {
            JinxZendArray *replacement = jinx_oracle_zend_array_ptr(args[3]);
            size_t replacement_live = jinx_zend_array_live_count(replacement);

            for (size_t i = 0u; i < replacement_live; i++) {
                const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(replacement, i);
                if (bucket == 0 || !jinx_zend_array_append(rebuilt, bucket->value)) goto fail;
            }
        } else {
            JinxZendValue replacement;
            JinxZendString *owned_string = 0;
            if (!jinx_oracle_jinx_value_to_zend(args[3], &replacement, &owned_string)) goto fail;
            int ok = jinx_zend_array_append(rebuilt, replacement);
            jinx_zend_string_release(owned_string);
            if (!ok) goto fail;
        }
    }

    for (int64_t i = end; i < (int64_t)live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, (size_t)i);
        if (!jinx_oracle_zend_add_bucket(rebuilt, bucket, 0)) goto fail;
    }

    jinx_oracle_zend_array_adopt_contents(array, rebuilt);
    return jinx_oracle_zend_array_value_owned(removed);

fail:
    jinx_zend_array_release(removed);
    jinx_zend_array_release(rebuilt);
    return jinx_oracle_zero_value();
}


static inline JinxZendArray *jinx_oracle_zend_clone_live_array(JinxZendArray *array) {
    if (array == 0) return 0;

    size_t live = jinx_zend_array_live_count(array);
    JinxZendArray *copy = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (copy == 0) return 0;

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (!jinx_oracle_zend_add_bucket(copy, bucket, 1)) {
            jinx_zend_array_release(copy);
            return 0;
        }
    }

    return copy;
}

static inline JinxZendValue *jinx_oracle_zend_array_value_for_bucket_key(
    JinxZendArray *array,
    const JinxZendBucket *bucket
) {
    if (array == 0 || bucket == 0) return 0;
    return bucket->key != 0
        ? jinx_zend_array_find(array, bucket->key->bytes, bucket->key->len)
        : jinx_zend_array_index(array, (size_t)bucket->h);
}

static inline int jinx_oracle_zend_array_store_bucket_key(
    JinxZendArray *array,
    const JinxZendBucket *bucket,
    JinxZendValue value
) {
    if (array == 0 || bucket == 0) return 0;
    return bucket->key != 0
        ? jinx_zend_array_add_assoc(array, bucket->key->bytes, bucket->key->len, value)
        : jinx_zend_array_add_index(array, (size_t)bucket->h, value);
}

static inline int jinx_oracle_zend_merge_recursive_into(
    JinxZendArray *target,
    JinxZendArray *source,
    size_t depth
) {
    if (target == 0 || source == 0 || depth >= 128u) return 0;

    size_t live = jinx_zend_array_live_count(source);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(source, i);
        if (bucket == 0) return 0;

        if (bucket->key == 0) {
            if (!jinx_zend_array_append(target, bucket->value)) return 0;
            continue;
        }

        JinxZendValue *existing = jinx_zend_array_find(target, bucket->key->bytes, bucket->key->len);
        if (existing == 0) {
            if (!jinx_zend_array_add_assoc(target, bucket->key->bytes, bucket->key->len, bucket->value)) return 0;
            continue;
        }

        JinxZendArray *merged;
        if (existing->type == JINX_ZEND_ARRAY && existing->value.array != 0) {
            merged = jinx_oracle_zend_clone_live_array(existing->value.array);
        } else {
            merged = jinx_zend_array_new_packed(2u);
            if (merged != 0 && !jinx_zend_array_append(merged, *existing)) {
                jinx_zend_array_release(merged);
                merged = 0;
            }
        }

        if (merged == 0) return 0;

        if (bucket->value.type == JINX_ZEND_ARRAY && bucket->value.value.array != 0) {
            if (!jinx_oracle_zend_merge_recursive_into(merged, bucket->value.value.array, depth + 1u)) {
                jinx_zend_array_release(merged);
                return 0;
            }
        } else if (!jinx_zend_array_append(merged, bucket->value)) {
            jinx_zend_array_release(merged);
            return 0;
        }

        JinxZendValue merged_value = jinx_zend_array_value(merged);
        int ok = jinx_zend_array_add_assoc(target, bucket->key->bytes, bucket->key->len, merged_value);
        jinx_zend_array_release(merged);
        if (!ok) return 0;
    }

    return 1;
}

static inline JinxValue jinx_oracle_zend_array_merge_recursive_special(const JinxValue *args, size_t argc) {
    if (argc < 1u) return jinx_oracle_zero_value();

    JinxZendArray *first = jinx_oracle_zend_array_ptr(args[0]);
    if (first == 0) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_oracle_zend_clone_live_array(first);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 1u; i < argc; i++) {
        JinxZendArray *next = jinx_oracle_zend_array_ptr(args[i]);
        if (next == 0 || !jinx_oracle_zend_merge_recursive_into(result, next, 0u)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline int jinx_oracle_zend_replace_recursive_into(
    JinxZendArray *target,
    JinxZendArray *source,
    size_t depth
) {
    if (target == 0 || source == 0 || depth >= 128u) return 0;

    size_t live = jinx_zend_array_live_count(source);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(source, i);
        if (bucket == 0) return 0;

        JinxZendValue *existing = jinx_oracle_zend_array_value_for_bucket_key(target, bucket);

        if (existing != 0 &&
            existing->type == JINX_ZEND_ARRAY && existing->value.array != 0 &&
            bucket->value.type == JINX_ZEND_ARRAY && bucket->value.value.array != 0) {
            JinxZendArray *nested = jinx_oracle_zend_clone_live_array(existing->value.array);
            if (nested == 0 ||
                !jinx_oracle_zend_replace_recursive_into(nested, bucket->value.value.array, depth + 1u)) {
                jinx_zend_array_release(nested);
                return 0;
            }

            JinxZendValue nested_value = jinx_zend_array_value(nested);
            int ok = jinx_oracle_zend_array_store_bucket_key(target, bucket, nested_value);
            jinx_zend_array_release(nested);
            if (!ok) return 0;
            continue;
        }

        if (!jinx_oracle_zend_array_store_bucket_key(target, bucket, bucket->value)) return 0;
    }

    return 1;
}

static inline JinxValue jinx_oracle_zend_array_replace_recursive_special(const JinxValue *args, size_t argc) {
    if (argc < 1u) return jinx_oracle_zero_value();

    JinxZendArray *first = jinx_oracle_zend_array_ptr(args[0]);
    if (first == 0) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_oracle_zend_clone_live_array(first);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 1u; i < argc; i++) {
        JinxZendArray *next = jinx_oracle_zend_array_ptr(args[i]);
        if (next == 0 || !jinx_oracle_zend_replace_recursive_into(result, next, 0u)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}


static inline int jinx_oracle_zend_csv_leading_space(unsigned char ch) {
    return ch == (unsigned char)' ' || ch == (unsigned char)'\t' ||
        ch == (unsigned char)'\v' || ch == (unsigned char)'\f';
}

static inline int jinx_oracle_zend_csv_append_field(
    JinxZendArray *result,
    const char *bytes,
    uint32_t len
) {
    JinxZendString *field = jinx_zend_string_new(bytes, len);
    if (field == 0) return 0;

    int ok = jinx_zend_array_append(result, jinx_zend_string_value(field));
    jinx_zend_string_release(field);
    return ok;
}

static inline JinxValue jinx_oracle_zend_str_getcsv_special(const JinxValue *args, size_t argc) {
    if (argc < 1u) return jinx_oracle_zero_value();

    const unsigned char *input = jinx_oracle_string_bytes(args[0]);
    uint32_t input_len = jinx_oracle_string_len(args[0]);

    const unsigned char *separator_bytes = argc >= 2u
        ? jinx_oracle_string_bytes(args[1])
        : (const unsigned char *)",";
    uint32_t separator_len = argc >= 2u ? jinx_oracle_string_len(args[1]) : 1u;

    const unsigned char *enclosure_bytes = argc >= 3u
        ? jinx_oracle_string_bytes(args[2])
        : (const unsigned char *)"\"";
    uint32_t enclosure_len = argc >= 3u ? jinx_oracle_string_len(args[2]) : 1u;

    const unsigned char *escape_bytes = argc >= 4u
        ? jinx_oracle_string_bytes(args[3])
        : (const unsigned char *)"\\";
    uint32_t escape_len = argc >= 4u ? jinx_oracle_string_len(args[3]) : 1u;

    if (separator_len != 1u || enclosure_len != 1u || escape_len > 1u) {
        return jinx_oracle_zero_value();
    }

    unsigned char separator = separator_bytes[0];
    unsigned char enclosure = enclosure_bytes[0];
    int escape_enabled = escape_len == 1u;
    unsigned char escape = escape_enabled ? escape_bytes[0] : 0u;

    JinxZendArray *result = jinx_zend_array_new_packed(4u);
    if (result == 0) return jinx_oracle_zero_value();

    if (input_len == 0u) {
        if (!jinx_zend_array_append(result, jinx_zend_null())) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
        return jinx_oracle_zend_array_value_owned(result);
    }

    uint32_t record_len = input_len;
    uint32_t pos = 0u;
    for (;;) {
        uint32_t field_start = pos;
        uint32_t quote_probe = pos;

        while (quote_probe < record_len && jinx_oracle_zend_csv_leading_space(input[quote_probe])) {
            quote_probe++;
        }

        int quoted = quote_probe < record_len && input[quote_probe] == enclosure;
        char *field = (char *)malloc((size_t)record_len + 1u);
        if (field == 0) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }

        uint32_t out_len = 0u;

        if (quoted) {
            pos = quote_probe + 1u;
            int closed = 0;
            uint32_t payload_len_at_close = 0u;

            while (pos < record_len) {
                unsigned char ch = input[pos];

                if (escape_enabled && ch == escape &&
                    pos + 1u < record_len && input[pos + 1u] == enclosure) {
                    field[out_len++] = (char)ch;
                    field[out_len++] = (char)enclosure;
                    pos += 2u;
                    continue;
                }

                if (ch == enclosure) {
                    if (pos + 1u < record_len && input[pos + 1u] == enclosure) {
                        field[out_len++] = (char)enclosure;
                        pos += 2u;
                        continue;
                    }

                    pos++;
                    closed = 1;
                    payload_len_at_close = out_len;
                    break;
                }

                field[out_len++] = (char)ch;
                pos++;
            }

            /*
             * PHP keeps bytes between a closing enclosure and the separator.
             * This also reproduces the proprietary-escape-disabled case where
             * a later enclosure is simply ordinary post-quote data.
             */
            if (closed) {
                while (pos < record_len && input[pos] != separator) {
                    field[out_len++] = (char)input[pos++];
                }

                /* Strip only record-ending CR/LF bytes that occur after the closing enclosure. */
                if (pos == record_len && out_len > payload_len_at_close) {
                    if (out_len > payload_len_at_close && field[out_len - 1u] == '\n') {
                        out_len--;
                        if (out_len > payload_len_at_close && field[out_len - 1u] == '\r') out_len--;
                    } else if (out_len > payload_len_at_close && field[out_len - 1u] == '\r') {
                        out_len--;
                    }
                }
            }
        } else {
            pos = field_start;
            while (pos < record_len && input[pos] != separator) {
                field[out_len++] = (char)input[pos++];
            }

            /* Unquoted final fields exclude the record's terminal CR/LF. */
            if (pos == record_len) {
                if (out_len != 0u && field[out_len - 1u] == '\n') {
                    out_len--;
                    if (out_len != 0u && field[out_len - 1u] == '\r') out_len--;
                } else if (out_len != 0u && field[out_len - 1u] == '\r') {
                    out_len--;
                }
            }
        }

        if (!jinx_oracle_zend_csv_append_field(result, field, out_len)) {
            free(field);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
        free(field);

        if (pos >= record_len) break;

        /* The current byte is the separator. Preserve a final empty field. */
        pos++;
        if (pos == record_len) {
            if (!jinx_oracle_zend_csv_append_field(result, "", 0u)) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
            break;
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}


static inline unsigned char jinx_oracle_ascii_lower_tag_byte(unsigned char ch) {
    return ch >= (unsigned char)'A' && ch <= (unsigned char)'Z'
        ? (unsigned char)(ch + ((unsigned char)'a' - (unsigned char)'A'))
        : ch;
}

static inline int jinx_oracle_zend_strip_tag_allowed(
    const char *tag,
    size_t len,
    const char *allowed
) {
    if (tag == 0 || len == 0u || allowed == 0) return 0;

    char *norm = (char *)malloc(len + 2u);
    if (norm == 0) return 0;

    size_t n = 0u;
    int state = 0;

    for (size_t i = 0u; i < len; i++) {
        unsigned char ch = jinx_oracle_ascii_lower_tag_byte((unsigned char)tag[i]);

        if (ch == (unsigned char)'<') {
            norm[n++] = '<';
            continue;
        }
        if (ch == (unsigned char)'>') break;

        if (isspace(ch)) {
            if (state == 1) break;
            continue;
        }

        if (state == 0) state = 1;

        if (ch == (unsigned char)'/' &&
            ((i > 0u && tag[i - 1u] == '<') ||
             (i + 1u < len && tag[i + 1u] == '>'))) {
            continue;
        }

        norm[n++] = (char)ch;
    }

    norm[n++] = '>';
    norm[n] = '\0';

    int found = strstr(allowed, norm) != 0;
    free(norm);
    return found;
}

static inline char *jinx_oracle_zend_strip_tags_allowed_set(
    JinxValue value,
    size_t *out_len
) {
    *out_len = 0u;

    if (value.type == 0u) return 0;

    if (value.type == 3u) {
        uint32_t len = jinx_oracle_string_len(value);
        char *allowed = (char *)malloc((size_t)len + 1u);
        if (allowed == 0) return 0;

        const unsigned char *bytes = jinx_oracle_string_bytes(value);
        for (uint32_t i = 0u; i < len; i++) {
            allowed[i] = (char)jinx_oracle_ascii_lower_tag_byte(bytes[i]);
        }
        allowed[len] = '\0';
        *out_len = len;
        return allowed;
    }

    if (!jinx_oracle_value_is_zend_array(value)) return 0;

    JinxZendArray *array = jinx_oracle_zend_array_ptr(value);
    size_t live = jinx_zend_array_live_count(array);
    uint64_t needed = 0u;

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0) continue;
        int text_len = jinx_oracle_zend_scalar_text_length(bucket->value);
        if (text_len < 0) return 0;
        needed += (uint64_t)text_len + 2u;
    }

    if (needed > SIZE_MAX - 1u) return 0;

    char *allowed = (char *)malloc((size_t)needed + 1u);
    if (allowed == 0) return 0;

    size_t pos = 0u;
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0) continue;

        int text_len = jinx_oracle_zend_scalar_text_length(bucket->value);
        char *tmp = text_len > 0 ? (char *)malloc((size_t)text_len) : 0;
        if (text_len > 0 && tmp == 0) {
            free(allowed);
            return 0;
        }

        uint32_t written = text_len > 0
            ? jinx_oracle_zend_scalar_write(tmp, bucket->value)
            : 0u;

        allowed[pos++] = '<';
        for (uint32_t j = 0u; j < written; j++) {
            allowed[pos++] = (char)jinx_oracle_ascii_lower_tag_byte((unsigned char)tmp[j]);
        }
        allowed[pos++] = '>';
        free(tmp);
    }

    allowed[pos] = '\0';
    *out_len = pos;
    return allowed;
}

static inline JinxValue jinx_oracle_zend_strip_tags_special(const JinxValue *args, size_t argc) {
    const unsigned char *source = jinx_oracle_string_bytes(args[0]);
    uint32_t len = jinx_oracle_string_len(args[0]);

    char *buf = (char *)malloc((size_t)len + 1u);
    if (buf == 0) return jinx_oracle_zero_value();
    if (len != 0u) memcpy(buf, source, len);
    buf[len] = '\0';

    size_t allowed_len = 0u;
    char *allowed = argc >= 2u
        ? jinx_oracle_zend_strip_tags_allowed_set(args[1], &allowed_len)
        : 0;

    if (argc >= 2u && args[1].type != 0u &&
        args[1].type != 3u && !jinx_oracle_value_is_zend_array(args[1])) {
        free(buf);
        free(allowed);
        return jinx_oracle_zero_value();
    }

    char *tag_buf = allowed != 0 ? (char *)malloc((size_t)len + 2u) : 0;
    if (allowed != 0 && tag_buf == 0) {
        free(buf);
        free(allowed);
        return jinx_oracle_zero_value();
    }

    char *out = jinx_oracle_scratch_string(len);
    uint32_t out_len = 0u;
    size_t tag_len = 0u;

    const char *p = buf;
    const char *end = buf + len;
    int bracket_depth = 0;
    int depth = 0;
    int in_quote = 0;
    unsigned char state = 0u;
    char last = '\0';
    int is_xml = 0;

state_0:
    if (p >= end) goto finish;
    switch (*p) {
        case '\0':
            break;
        case '<':
            if (in_quote) break;
            if (p + 1 < end && isspace((unsigned char)p[1])) {
                out[out_len++] = *p;
                break;
            }
            last = '<';
            state = 1u;
            tag_len = 0u;
            if (allowed != 0) tag_buf[tag_len++] = '<';
            p++;
            goto state_1;
        case '>':
            if (depth) {
                depth--;
                break;
            }
            if (in_quote) break;
            out[out_len++] = *p;
            break;
        default:
            out[out_len++] = *p;
            break;
    }
    p++;
    goto state_0;

state_1:
    if (p >= end) goto finish;
    switch (*p) {
        case '\0':
            break;
        case '<':
            if (in_quote) break;
            if (p + 1 < end && isspace((unsigned char)p[1])) goto reg_char_1;
            depth++;
            break;
        case '>':
            if (depth) {
                depth--;
                break;
            }
            if (in_quote) break;

            last = '>';
            if (is_xml && p > buf && *(p - 1) == '-') break;

            in_quote = 0;
            state = 0u;
            is_xml = 0;

            if (allowed != 0) {
                tag_buf[tag_len++] = '>';
                tag_buf[tag_len] = '\0';
                if (jinx_oracle_zend_strip_tag_allowed(tag_buf, tag_len, allowed)) {
                    memcpy(out + out_len, tag_buf, tag_len);
                    out_len += (uint32_t)tag_len;
                }
                tag_len = 0u;
            }
            p++;
            goto state_0;
        case '"':
        case '\'':
            if (p != buf && (!in_quote || *p == in_quote)) {
                in_quote = in_quote ? 0 : *p;
            }
            goto reg_char_1;
        case '!':
            if (p > buf && *(p - 1) == '<') {
                state = 3u;
                last = *p;
                p++;
                goto state_3;
            }
            goto reg_char_1;
        case '?':
            if (p > buf && *(p - 1) == '<') {
                bracket_depth = 0;
                state = 2u;
                p++;
                goto state_2;
            }
            goto reg_char_1;
        default:
reg_char_1:
            if (allowed != 0) tag_buf[tag_len++] = *p;
            break;
    }
    p++;
    goto state_1;

state_2:
    if (p >= end) goto finish;
    switch (*p) {
        case '(':
            if (last != '"' && last != '\'') {
                last = '(';
                bracket_depth++;
            }
            break;
        case ')':
            if (last != '"' && last != '\'') {
                last = ')';
                bracket_depth--;
            }
            break;
        case '>':
            if (depth) {
                depth--;
                break;
            }
            if (in_quote) break;
            if (!bracket_depth && p > buf && last != '"' && *(p - 1) == '?') {
                in_quote = 0;
                state = 0u;
                tag_len = 0u;
                p++;
                goto state_0;
            }
            break;
        case '"':
        case '\'':
            if (p > buf && *(p - 1) != '\\') {
                if (last == *p) last = '\0';
                else if (last != '\\') last = *p;

                if (!in_quote || *p == in_quote) in_quote = in_quote ? 0 : *p;
            }
            break;
        case 'l':
        case 'L':
            if (state == 2u && p > buf + 4 &&
                (*(p - 1) == 'm' || *(p - 1) == 'M') &&
                (*(p - 2) == 'x' || *(p - 2) == 'X') &&
                *(p - 3) == '?' && *(p - 4) == '<') {
                state = 1u;
                is_xml = 1;
                p++;
                goto state_1;
            }
            break;
        default:
            break;
    }
    p++;
    goto state_2;

state_3:
    if (p >= end) goto finish;
    switch (*p) {
        case '>':
            if (depth) {
                depth--;
                break;
            }
            if (in_quote) break;
            in_quote = 0;
            state = 0u;
            tag_len = 0u;
            p++;
            goto state_0;
        case '"':
        case '\'':
            if (p > buf && *(p - 1) != '\\' && (!in_quote || *p == in_quote)) {
                in_quote = in_quote ? 0 : *p;
            }
            break;
        case '-':
            if (p >= buf + 2 && *(p - 1) == '-' && *(p - 2) == '!') {
                state = 4u;
                p++;
                goto state_4;
            }
            break;
        case 'E':
        case 'e':
            if (p > buf + 6 &&
                (*(p - 1) == 'p' || *(p - 1) == 'P') &&
                (*(p - 2) == 'y' || *(p - 2) == 'Y') &&
                (*(p - 3) == 't' || *(p - 3) == 'T') &&
                (*(p - 4) == 'c' || *(p - 4) == 'C') &&
                (*(p - 5) == 'o' || *(p - 5) == 'O') &&
                (*(p - 6) == 'd' || *(p - 6) == 'D')) {
                state = 1u;
                p++;
                goto state_1;
            }
            break;
        default:
            break;
    }
    p++;
    goto state_3;

state_4:
    while (p < end) {
        if (*p == '>' && !in_quote && p >= buf + 2 &&
            *(p - 1) == '-' && *(p - 2) == '-') {
            in_quote = 0;
            state = 0u;
            tag_len = 0u;
            p++;
            goto state_0;
        }
        p++;
    }

finish:
    free(tag_buf);
    free(allowed);
    free(buf);
    return jinx_oracle_string_value_len(out, out_len);
}




typedef struct JinxOracleParseStrToken {
    const char *bytes;
    uint32_t len;
    int append;
} JinxOracleParseStrToken;

static inline JinxZendValue *jinx_oracle_parse_str_lookup(
    JinxZendArray *array,
    const char *key,
    uint32_t key_len
) {
    int64_t numeric_key;
    if (jinx_zend_array_numeric_string_key(key, key_len, &numeric_key)) {
        return jinx_zend_array_index(array, (size_t)numeric_key);
    }
    return jinx_zend_array_find(array, key, key_len);
}

static inline int jinx_oracle_parse_str_store(
    JinxZendArray *array,
    const char *key,
    uint32_t key_len,
    JinxZendValue value
) {
    return jinx_zend_array_add_symtable(array, key, key_len, value);
}

static inline JinxZendString *jinx_oracle_parse_str_decode(
    const unsigned char *bytes,
    uint32_t len
) {
    JinxValue raw = jinx_oracle_string_value_len((const char *)bytes, len);
    JinxValue decoded = jinx_oracle_urldecode_value(raw, 0);
    if (decoded.type != 3u) return 0;
    return jinx_zend_string_new((const char *)decoded.as.ptr, decoded.flags);
}

static inline int jinx_oracle_parse_str_assign(
    JinxZendArray *root,
    JinxZendString *decoded_key,
    JinxZendString *decoded_value
) {
    if (root == 0 || decoded_key == 0 || decoded_value == 0) return 0;

    char *key = (char *)malloc(decoded_key->len + 1u);
    if (key == 0) return 0;
    memcpy(key, decoded_key->bytes, decoded_key->len);
    key[decoded_key->len] = '\0';

    uint32_t key_len = (uint32_t)decoded_key->len;
    uint32_t first_bracket = key_len;
    for (uint32_t i = 0u; i < key_len; i++) {
        if (key[i] == '[') {
            first_bracket = i;
            break;
        }
    }

    if (first_bracket == 0u) {
        free(key);
        return 1;
    }

    for (uint32_t i = 0u; i < first_bracket; i++) {
        if (key[i] == ' ' || key[i] == '.') key[i] = '_';
    }

    JinxOracleParseStrToken tokens[32];
    uint32_t token_count = 1u;
    tokens[0].bytes = key;
    tokens[0].len = first_bracket;
    tokens[0].append = 0;

    uint32_t pos = first_bracket;
    while (pos < key_len && token_count < 32u) {
        if (key[pos] != '[') break;
        uint32_t close = pos + 1u;
        while (close < key_len && key[close] != ']') close++;
        if (close >= key_len) break;

        tokens[token_count].bytes = key + pos + 1u;
        tokens[token_count].len = close - pos - 1u;
        tokens[token_count].append = close == pos + 1u;
        token_count++;
        pos = close + 1u;
    }

    JinxZendValue final_value = jinx_zend_string_value(decoded_value);

    if (token_count == 1u) {
        int ok = jinx_oracle_parse_str_store(
            root,
            tokens[0].bytes,
            tokens[0].len,
            final_value
        );
        free(key);
        return ok;
    }

    JinxZendArray *current = root;

    for (uint32_t t = 0u; t + 1u < token_count; t++) {
        JinxOracleParseStrToken token = tokens[t];
        JinxZendValue *slot = 0;

        if (token.append) {
            JinxZendArray *nested = jinx_zend_array_new_packed(2u);
            if (nested == 0) {
                free(key);
                return 0;
            }

            size_t index = current->next_index;
            if (!jinx_zend_array_append(current, jinx_zend_array_value(nested))) {
                jinx_zend_array_release(nested);
                free(key);
                return 0;
            }
            jinx_zend_array_release(nested);
            slot = jinx_zend_array_index(current, index);
        } else {
            slot = jinx_oracle_parse_str_lookup(current, token.bytes, token.len);
            if (slot == 0 || slot->type != JINX_ZEND_ARRAY || slot->value.array == 0) {
                JinxZendArray *nested = jinx_zend_array_new_packed(2u);
                if (nested == 0) {
                    free(key);
                    return 0;
                }

                if (!jinx_oracle_parse_str_store(
                    current,
                    token.bytes,
                    token.len,
                    jinx_zend_array_value(nested)
                )) {
                    jinx_zend_array_release(nested);
                    free(key);
                    return 0;
                }
                jinx_zend_array_release(nested);
                slot = jinx_oracle_parse_str_lookup(current, token.bytes, token.len);
            }
        }

        if (slot == 0 || slot->type != JINX_ZEND_ARRAY || slot->value.array == 0) {
            free(key);
            return 0;
        }

        current = slot->value.array;
    }

    JinxOracleParseStrToken last = tokens[token_count - 1u];
    int ok = last.append
        ? jinx_zend_array_append(current, final_value)
        : jinx_oracle_parse_str_store(current, last.bytes, last.len, final_value);

    free(key);
    return ok;
}

static inline JinxValue jinx_oracle_zend_parse_str_special(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 2u || args[0].type != 3u) {
        return jinx_oracle_zero_value();
    }

    const unsigned char *query = jinx_oracle_string_bytes(args[0]);
    uint32_t query_len = jinx_oracle_string_len(args[0]);
    JinxZendArray *result = jinx_zend_array_new_packed(8u);
    if (result == 0) return jinx_oracle_zero_value();

    uint32_t start = 0u;
    while (start <= query_len) {
        uint32_t end = start;
        while (end < query_len && query[end] != '&') end++;

        if (end > start) {
            uint32_t eq = start;
            while (eq < end && query[eq] != '=') eq++;

            uint32_t key_len = eq - start;
            uint32_t value_start = eq < end ? eq + 1u : end;
            uint32_t value_len = end - value_start;

            JinxZendString *decoded_key = jinx_oracle_parse_str_decode(
                query + start,
                key_len
            );
            JinxZendString *decoded_value = jinx_oracle_parse_str_decode(
                query + value_start,
                value_len
            );

            if (decoded_key == 0 || decoded_value == 0 ||
                !jinx_oracle_parse_str_assign(result, decoded_key, decoded_value)) {
                jinx_zend_string_release(decoded_key);
                jinx_zend_string_release(decoded_value);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }

            jinx_zend_string_release(decoded_key);
            jinx_zend_string_release(decoded_value);
        }

        if (end == query_len) break;
        start = end + 1u;
    }

    jinx_oracle_zend_array_value_release(args[1]);
    args[1] = jinx_oracle_zend_array_value_owned(result);
    return jinx_oracle_zero_value();
}


static inline int jinx_oracle_zend_add_cstring(
    JinxZendArray *array,
    const char *key,
    const char *value
) {
    if (value == 0) value = "";

    JinxZendString *string = jinx_zend_string_new(value, strlen(value));
    if (string == 0) return 0;

    int ok = jinx_zend_array_add_assoc(
        array,
        key,
        strlen(key),
        jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static inline JinxZendArray *jinx_oracle_zend_locale_grouping_array(const char *grouping) {
    JinxZendArray *result = jinx_zend_array_new_packed(4u);
    if (result == 0) return 0;
    if (grouping == 0) return result;

    for (size_t i = 0u; grouping[i] != '\0'; i++) {
        unsigned char value = (unsigned char)grouping[i];
        if (!jinx_zend_array_append(result, jinx_zend_long((int64_t)value))) {
            jinx_zend_array_release(result);
            return 0;
        }
        if (value == (unsigned char)CHAR_MAX) break;
    }

    return result;
}

static inline JinxValue jinx_oracle_zend_localeconv_special(void) {
    struct lconv *lc = localeconv();
    if (lc == 0) return jinx_oracle_zero_value();

    JinxZendArray *result = jinx_zend_array_new_packed(18u);
    JinxZendArray *grouping = jinx_oracle_zend_locale_grouping_array(lc->grouping);
    JinxZendArray *mon_grouping = jinx_oracle_zend_locale_grouping_array(lc->mon_grouping);
    if (result == 0 || grouping == 0 || mon_grouping == 0) {
        jinx_zend_array_release(result);
        jinx_zend_array_release(grouping);
        jinx_zend_array_release(mon_grouping);
        return jinx_oracle_zero_value();
    }

    int ok =
        jinx_oracle_zend_add_cstring(result, "decimal_point", lc->decimal_point) &&
        jinx_oracle_zend_add_cstring(result, "thousands_sep", lc->thousands_sep) &&
        jinx_oracle_zend_add_cstring(result, "int_curr_symbol", lc->int_curr_symbol) &&
        jinx_oracle_zend_add_cstring(result, "currency_symbol", lc->currency_symbol) &&
        jinx_oracle_zend_add_cstring(result, "mon_decimal_point", lc->mon_decimal_point) &&
        jinx_oracle_zend_add_cstring(result, "mon_thousands_sep", lc->mon_thousands_sep) &&
        jinx_oracle_zend_add_cstring(result, "positive_sign", lc->positive_sign) &&
        jinx_oracle_zend_add_cstring(result, "negative_sign", lc->negative_sign) &&
        jinx_zend_array_add_assoc(result, "int_frac_digits", 15u, jinx_zend_long((unsigned char)lc->int_frac_digits)) &&
        jinx_zend_array_add_assoc(result, "frac_digits", 11u, jinx_zend_long((unsigned char)lc->frac_digits)) &&
        jinx_zend_array_add_assoc(result, "p_cs_precedes", 13u, jinx_zend_long((unsigned char)lc->p_cs_precedes)) &&
        jinx_zend_array_add_assoc(result, "p_sep_by_space", 14u, jinx_zend_long((unsigned char)lc->p_sep_by_space)) &&
        jinx_zend_array_add_assoc(result, "n_cs_precedes", 13u, jinx_zend_long((unsigned char)lc->n_cs_precedes)) &&
        jinx_zend_array_add_assoc(result, "n_sep_by_space", 14u, jinx_zend_long((unsigned char)lc->n_sep_by_space)) &&
        jinx_zend_array_add_assoc(result, "p_sign_posn", 11u, jinx_zend_long((unsigned char)lc->p_sign_posn)) &&
        jinx_zend_array_add_assoc(result, "n_sign_posn", 11u, jinx_zend_long((unsigned char)lc->n_sign_posn)) &&
        jinx_zend_array_add_assoc(result, "grouping", 8u, jinx_zend_array_value(grouping)) &&
        jinx_zend_array_add_assoc(result, "mon_grouping", 12u, jinx_zend_array_value(mon_grouping));

    jinx_zend_array_release(grouping);
    jinx_zend_array_release(mon_grouping);

    if (!ok) {
        jinx_zend_array_release(result);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline int jinx_oracle_zend_http_query_scalar(
    JinxZendValue value,
    JinxValue *out
) {
    if (value.type == JINX_ZEND_NULL) return 0;
    if (value.type == JINX_ZEND_FALSE) { *out = jinx_oracle_string_value("0"); return 1; }
    if (value.type == JINX_ZEND_TRUE) { *out = jinx_oracle_string_value("1"); return 1; }
    if (value.type == JINX_ZEND_LONG) {
        char *buf = jinx_oracle_scratch_string(32u);
        int n = snprintf(buf, 32u, "%lld", (long long)value.value.lval);
        *out = jinx_oracle_string_value_len(buf, n < 0 ? 0u : (uint32_t)n);
        return 1;
    }
    if (value.type == JINX_ZEND_DOUBLE) {
        char *buf = jinx_oracle_scratch_string(64u);
        int n = snprintf(buf, 64u, "%g", value.value.dval);
        *out = jinx_oracle_string_value_len(buf, n < 0 ? 0u : (uint32_t)n);
        return 1;
    }
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        *out = jinx_oracle_string_value_len(
            value.value.str->bytes,
            (uint32_t)value.value.str->len
        );
        return 1;
    }
    return -1;
}

static inline int jinx_oracle_zend_http_query_append_pair(
    JinxOracleFormatBuffer *out,
    JinxValue key,
    JinxValue value,
    const unsigned char *separator,
    uint32_t separator_len,
    int raw_encoding,
    int *first
) {
    JinxValue encoded_key = jinx_oracle_urlencode_value(key, raw_encoding);
    if (encoded_key.type != 3u) return 0;

    if (!*first && separator_len != 0u &&
        !jinx_oracle_format_append(out, (const char *)separator, separator_len)) {
        return 0;
    }

    if (!jinx_oracle_format_append(
        out,
        (const char *)encoded_key.as.ptr,
        encoded_key.flags
    )) return 0;

    if (!jinx_oracle_format_append(out, "=", 1u)) return 0;

    JinxValue encoded_value = jinx_oracle_urlencode_value(value, raw_encoding);
    if (encoded_value.type != 3u) return 0;

    if (!jinx_oracle_format_append(
        out,
        (const char *)encoded_value.as.ptr,
        encoded_value.flags
    )) return 0;

    *first = 0;
    return 1;
}

static inline int jinx_oracle_zend_http_query_recurse(
    JinxOracleFormatBuffer *out,
    JinxZendArray *array,
    const unsigned char *parent,
    uint32_t parent_len,
    const unsigned char *numeric_prefix,
    uint32_t numeric_prefix_len,
    const unsigned char *separator,
    uint32_t separator_len,
    int raw_encoding,
    int top_level,
    int *first
) {
    size_t live = jinx_zend_array_live_count(array);

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0) continue;

        char number[32];
        const unsigned char *key_bytes;
        uint32_t key_len;
        int numeric = bucket->key == 0;

        if (numeric) {
            int n = snprintf(number, sizeof(number), "%llu", (unsigned long long)bucket->h);
            if (n < 0) return 0;
            key_bytes = (const unsigned char *)number;
            key_len = (uint32_t)n;
        } else {
            key_bytes = (const unsigned char *)bucket->key->bytes;
            key_len = (uint32_t)bucket->key->len;
        }

        uint64_t raw_len64;
        if (top_level) {
            raw_len64 = (numeric ? numeric_prefix_len : 0u) + key_len;
        } else {
            raw_len64 = (uint64_t)parent_len + 2u + key_len;
        }
        if (raw_len64 > UINT32_MAX) return 0;

        uint32_t raw_len = (uint32_t)raw_len64;
        char *raw = (char *)malloc((size_t)raw_len + 1u);
        if (raw == 0) return 0;

        uint32_t pos = 0u;
        if (top_level) {
            if (numeric && numeric_prefix_len != 0u) {
                memcpy(raw + pos, numeric_prefix, numeric_prefix_len);
                pos += numeric_prefix_len;
            }
            if (key_len != 0u) memcpy(raw + pos, key_bytes, key_len);
            pos += key_len;
        } else {
            if (parent_len != 0u) memcpy(raw + pos, parent, parent_len);
            pos += parent_len;
            raw[pos++] = '[';
            if (key_len != 0u) memcpy(raw + pos, key_bytes, key_len);
            pos += key_len;
            raw[pos++] = ']';
        }
        raw[pos] = '\0';

        if (bucket->value.type == JINX_ZEND_ARRAY && bucket->value.value.array != 0) {
            int ok = jinx_oracle_zend_http_query_recurse(
                out,
                bucket->value.value.array,
                (const unsigned char *)raw,
                raw_len,
                numeric_prefix,
                numeric_prefix_len,
                separator,
                separator_len,
                raw_encoding,
                0,
                first
            );
            free(raw);
            if (!ok) return 0;
            continue;
        }

        JinxValue scalar;
        int scalar_state = jinx_oracle_zend_http_query_scalar(bucket->value, &scalar);
        if (scalar_state == 0) {
            free(raw);
            continue;
        }
        if (scalar_state < 0) {
            free(raw);
            return 0;
        }

        JinxValue key = jinx_oracle_string_value_len(raw, raw_len);
        int ok = jinx_oracle_zend_http_query_append_pair(
            out,
            key,
            scalar,
            separator,
            separator_len,
            raw_encoding,
            first
        );
        free(raw);
        if (!ok) return 0;
    }

    return 1;
}

static inline JinxValue jinx_oracle_zend_http_build_query_special(
    const JinxValue *args,
    size_t argc
) {
    if (argc < 1u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    const unsigned char *numeric_prefix = (const unsigned char *)"";
    uint32_t numeric_prefix_len = 0u;
    if (argc >= 2u && args[1].type == 3u) {
        numeric_prefix = jinx_oracle_string_bytes(args[1]);
        numeric_prefix_len = jinx_oracle_string_len(args[1]);
    }

    const unsigned char *separator = (const unsigned char *)"&";
    uint32_t separator_len = 1u;
    if (argc >= 3u && args[2].type != 0u) {
        if (args[2].type != 3u) return jinx_oracle_zero_value();
        separator = jinx_oracle_string_bytes(args[2]);
        separator_len = jinx_oracle_string_len(args[2]);
    }

    int64_t encoding_type = argc >= 4u ? jinx_oracle_intish(args[3]) : 1;
    if (encoding_type != 1 && encoding_type != 2) {
        return jinx_oracle_zero_value();
    }

    JinxOracleFormatBuffer out = {0};
    int first = 1;
    int ok = jinx_oracle_zend_http_query_recurse(
        &out,
        array,
        NULL,
        0u,
        numeric_prefix,
        numeric_prefix_len,
        separator,
        separator_len,
        encoding_type == 2,
        1,
        &first
    );

    if (!ok || out.len > UINT32_MAX) {
        free(out.data);
        return jinx_oracle_zero_value();
    }

    char *scratch = jinx_oracle_scratch_string((uint32_t)out.len);
    if (out.len != 0u) memcpy(scratch, out.data, out.len);
    uint32_t len = (uint32_t)out.len;
    free(out.data);
    return jinx_oracle_string_value_len(scratch, len);
}


static inline int jinx_oracle_zend_add_jinx_string(
    JinxZendArray *array,
    const char *key,
    JinxValue value
) {
    if (value.type != 3u) return 0;

    JinxZendString *string = jinx_zend_string_new(
        (const char *)value.as.ptr,
        value.flags
    );
    if (string == 0) return 0;

    int ok = jinx_zend_array_add_assoc(
        array,
        key,
        strlen(key),
        jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static inline JinxValue jinx_oracle_zend_pathinfo_special(
    const JinxValue *args,
    size_t argc
) {
    if (argc < 1u || args[0].type != 3u) {
        return jinx_oracle_zero_value();
    }

    JinxValue path = args[0];
    JinxValue dirname = jinx_oracle_dirname_value(
        path,
        jinx_oracle_int_value(1),
        1u
    );
    JinxValue basename = jinx_oracle_basename_value(
        path,
        jinx_oracle_zero_value(),
        1u
    );

    const unsigned char *base = jinx_oracle_string_bytes(basename);
    uint32_t base_len = jinx_oracle_string_len(basename);
    uint32_t dot = UINT32_MAX;

    for (uint32_t i = 0u; i < base_len; i++) {
        if (base[i] == (unsigned char)'.') dot = i;
    }

    int has_extension = dot != UINT32_MAX;
    JinxValue extension = has_extension
        ? jinx_oracle_string_slice_copy(base, dot + 1u, base_len - dot - 1u)
        : jinx_oracle_string_value_len("", 0u);
    JinxValue filename = has_extension
        ? jinx_oracle_string_slice_copy(base, 0u, dot)
        : jinx_oracle_string_slice_copy(base, 0u, base_len);

    int64_t flags = argc >= 2u ? jinx_oracle_intish(args[1]) : 15;

    if (argc >= 2u && flags != 15) {
        if (flags == 1) return dirname;
        if (flags == 2) return basename;
        if (flags == 4) return extension;
        if (flags == 8) return filename;
        return jinx_oracle_zero_value();
    }

    JinxZendArray *result = jinx_zend_array_new_packed(4u);
    if (result == 0) return jinx_oracle_zero_value();

    if (!jinx_oracle_zend_add_jinx_string(result, "dirname", dirname) ||
        !jinx_oracle_zend_add_jinx_string(result, "basename", basename) ||
        (has_extension && !jinx_oracle_zend_add_jinx_string(result, "extension", extension)) ||
        !jinx_oracle_zend_add_jinx_string(result, "filename", filename)) {
        jinx_zend_array_release(result);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_array_value_owned(result);
}

typedef struct JinxOracleParsedUrl {
    const unsigned char *src;
    uint32_t len;
    uint32_t scheme_start, scheme_len;
    uint32_t host_start, host_len;
    uint32_t user_start, user_len;
    uint32_t pass_start, pass_len;
    uint32_t path_start, path_len;
    uint32_t query_start, query_len;
    uint32_t fragment_start, fragment_len;
    int64_t port;
    uint8_t has_scheme, has_host, has_user, has_pass, has_path;
    uint8_t has_query, has_fragment, has_port;
} JinxOracleParsedUrl;

static inline int jinx_oracle_url_scheme_char(unsigned char c, int first) {
    if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z')) return 1;
    return !first && ((c >= '0' && c <= '9') || c == '+' || c == '-' || c == '.');
}

static inline int jinx_oracle_url_all_digits(
    const unsigned char *src,
    uint32_t start,
    uint32_t end,
    int64_t *value
) {
    if (start >= end) return 0;
    int64_t n = 0;
    for (uint32_t i = start; i < end; i++) {
        if (src[i] < '0' || src[i] > '9') return 0;
        n = n * 10 + (int64_t)(src[i] - '0');
        if (n > 65535) return 0;
    }
    *value = n;
    return 1;
}

static inline int jinx_oracle_parse_url_parts(JinxValue value, JinxOracleParsedUrl *out) {
    memset(out, 0, sizeof(*out));
    out->src = jinx_oracle_string_bytes(value);
    out->len = jinx_oracle_string_len(value);

    const unsigned char *src = out->src;
    uint32_t len = out->len;
    uint32_t main_end = len;

    for (uint32_t i = 0u; i < len; i++) {
        if (src[i] == '#') {
            out->has_fragment = 1u;
            out->fragment_start = i + 1u;
            out->fragment_len = len - i - 1u;
            main_end = i;
            break;
        }
    }

    for (uint32_t i = 0u; i < main_end; i++) {
        if (src[i] == '?') {
            out->has_query = 1u;
            out->query_start = i + 1u;
            out->query_len = main_end - i - 1u;
            main_end = i;
            break;
        }
    }

    uint32_t rest = 0u;
    uint32_t colon = UINT32_MAX;
    int valid_scheme = len != 0u && jinx_oracle_url_scheme_char(src[0], 1);

    if (valid_scheme) {
        for (uint32_t i = 1u; i < main_end; i++) {
            if (src[i] == ':') {
                colon = i;
                break;
            }
            if (src[i] == '/' || !jinx_oracle_url_scheme_char(src[i], 0)) {
                valid_scheme = 0;
                break;
            }
        }
    }

    if (valid_scheme && colon != UINT32_MAX) {
        out->has_scheme = 1u;
        out->scheme_start = 0u;
        out->scheme_len = colon;
        rest = colon + 1u;
    }

    int has_authority = 0;
    uint32_t authority_start = 0u;
    uint32_t authority_end = 0u;

    if (rest + 1u < main_end && src[rest] == '/' && src[rest + 1u] == '/') {
        has_authority = 1;
        authority_start = rest + 2u;
    } else if (!out->has_scheme && main_end >= 2u && src[0] == '/' && src[1] == '/') {
        has_authority = 1;
        authority_start = 2u;
        rest = 0u;
    }

    if (has_authority) {
        authority_end = authority_start;
        while (authority_end < main_end && src[authority_end] != '/') authority_end++;

        uint32_t hostport_start = authority_start;
        uint32_t at = UINT32_MAX;
        for (uint32_t i = authority_start; i < authority_end; i++) {
            if (src[i] == '@') at = i;
        }

        if (at != UINT32_MAX) {
            uint32_t userinfo_end = at;
            uint32_t pass_colon = UINT32_MAX;
            for (uint32_t i = authority_start; i < userinfo_end; i++) {
                if (src[i] == ':') {
                    pass_colon = i;
                    break;
                }
            }

            out->has_user = 1u;
            out->user_start = authority_start;
            out->user_len = (pass_colon == UINT32_MAX ? userinfo_end : pass_colon) - authority_start;

            if (pass_colon != UINT32_MAX) {
                out->has_pass = 1u;
                out->pass_start = pass_colon + 1u;
                out->pass_len = userinfo_end - pass_colon - 1u;
            }

            hostport_start = at + 1u;
        }

        if (hostport_start < authority_end && src[hostport_start] == '[') {
            uint32_t close = hostport_start + 1u;
            while (close < authority_end && src[close] != ']') close++;
            if (close >= authority_end) return 0;

            out->has_host = 1u;
            out->host_start = hostport_start;
            out->host_len = close - hostport_start + 1u;

            if (close + 1u < authority_end) {
                if (src[close + 1u] != ':') return 0;
                int64_t port = 0;
                if (!jinx_oracle_url_all_digits(src, close + 2u, authority_end, &port)) return 0;
                out->has_port = 1u;
                out->port = port;
            }
        } else if (hostport_start < authority_end) {
            uint32_t port_colon = UINT32_MAX;
            for (uint32_t i = hostport_start; i < authority_end; i++) {
                if (src[i] == ':') port_colon = i;
            }

            if (port_colon != UINT32_MAX) {
                int64_t port = 0;
                if (!jinx_oracle_url_all_digits(src, port_colon + 1u, authority_end, &port)) return 0;
                out->has_port = 1u;
                out->port = port;
                out->has_host = port_colon > hostport_start;
                out->host_start = hostport_start;
                out->host_len = port_colon - hostport_start;
            } else {
                out->has_host = 1u;
                out->host_start = hostport_start;
                out->host_len = authority_end - hostport_start;
            }
        }

        if (authority_end < main_end) {
            out->has_path = 1u;
            out->path_start = authority_end;
            out->path_len = main_end - authority_end;
        }
    } else {
        uint32_t path_start = out->has_scheme ? rest : 0u;
        out->has_path = 1u;
        out->path_start = path_start;
        out->path_len = main_end >= path_start ? main_end - path_start : 0u;
    }

    return 1;
}

static inline JinxValue jinx_oracle_url_component_string(
    const JinxOracleParsedUrl *url,
    uint32_t start,
    uint32_t len
) {
    char *out = jinx_oracle_scratch_string(len);
    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = url->src[start + i];
        out[i] = iscntrl((int)ch) ? '_' : (char)ch;
    }
    return jinx_oracle_string_value_len(out, len);
}

static inline int jinx_oracle_zend_add_url_string(
    JinxZendArray *array,
    const char *key,
    const JinxOracleParsedUrl *url,
    uint32_t start,
    uint32_t len
) {
    char *tmp = (char *)malloc((size_t)len + 1u);
    if (tmp == 0) return 0;

    for (uint32_t i = 0u; i < len; i++) {
        unsigned char ch = url->src[start + i];
        tmp[i] = iscntrl((int)ch) ? '_' : (char)ch;
    }
    tmp[len] = '\0';

    JinxZendString *string = jinx_zend_string_new(tmp, len);
    free(tmp);
    if (string == 0) return 0;

    int ok = jinx_zend_array_add_assoc(array, key, strlen(key), jinx_zend_string_value(string));
    jinx_zend_string_release(string);
    return ok;
}

static inline JinxValue jinx_oracle_zend_parse_url_special(const JinxValue *args, size_t argc) {
    JinxOracleParsedUrl url;
    if (argc < 1u || !jinx_oracle_parse_url_parts(args[0], &url)) {
        return jinx_oracle_bool_value(0);
    }

    int64_t component = argc >= 2u ? jinx_oracle_intish(args[1]) : -1;

    if (component >= 0) {
        switch (component) {
            case 0:
                return url.has_scheme
                    ? jinx_oracle_url_component_string(&url, url.scheme_start, url.scheme_len)
                    : jinx_oracle_zero_value();
            case 1:
                return url.has_host
                    ? jinx_oracle_url_component_string(&url, url.host_start, url.host_len)
                    : jinx_oracle_zero_value();
            case 2:
                return url.has_port ? jinx_oracle_int_value(url.port) : jinx_oracle_zero_value();
            case 3:
                return url.has_user
                    ? jinx_oracle_url_component_string(&url, url.user_start, url.user_len)
                    : jinx_oracle_zero_value();
            case 4:
                return url.has_pass
                    ? jinx_oracle_url_component_string(&url, url.pass_start, url.pass_len)
                    : jinx_oracle_zero_value();
            case 5:
                return url.has_path
                    ? jinx_oracle_url_component_string(&url, url.path_start, url.path_len)
                    : jinx_oracle_zero_value();
            case 6:
                return url.has_query
                    ? jinx_oracle_url_component_string(&url, url.query_start, url.query_len)
                    : jinx_oracle_zero_value();
            case 7:
                return url.has_fragment
                    ? jinx_oracle_url_component_string(&url, url.fragment_start, url.fragment_len)
                    : jinx_oracle_zero_value();
            default:
                return jinx_oracle_bool_value(0);
        }
    }

    JinxZendArray *result = jinx_zend_array_new_packed(8u);
    if (result == 0) return jinx_oracle_zero_value();

    if ((url.has_scheme && !jinx_oracle_zend_add_url_string(result, "scheme", &url, url.scheme_start, url.scheme_len)) ||
        (url.has_host && !jinx_oracle_zend_add_url_string(result, "host", &url, url.host_start, url.host_len)) ||
        (url.has_port && !jinx_zend_array_add_assoc(result, "port", 4u, jinx_zend_long(url.port))) ||
        (url.has_user && !jinx_oracle_zend_add_url_string(result, "user", &url, url.user_start, url.user_len)) ||
        (url.has_pass && !jinx_oracle_zend_add_url_string(result, "pass", &url, url.pass_start, url.pass_len)) ||
        (url.has_path && !jinx_oracle_zend_add_url_string(result, "path", &url, url.path_start, url.path_len)) ||
        (url.has_query && !jinx_oracle_zend_add_url_string(result, "query", &url, url.query_start, url.query_len)) ||
        (url.has_fragment && !jinx_oracle_zend_add_url_string(result, "fragment", &url, url.fragment_start, url.fragment_len))) {
        jinx_zend_array_release(result);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_array_value_owned(result);
}



typedef struct JinxOracleSortEntry {
    const JinxZendBucket *bucket;
    size_t ordinal;
} JinxOracleSortEntry;

static inline int jinx_oracle_sort_text(
    JinxZendValue value,
    char **owned,
    const unsigned char **bytes,
    uint32_t *len
) {
    *owned = 0;
    *bytes = (const unsigned char *)"";
    *len = 0u;

    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        *bytes = (const unsigned char *)value.value.str->bytes;
        *len = (uint32_t)value.value.str->len;
        return 1;
    }

    int text_len = jinx_oracle_zend_scalar_text_length(value);
    if (text_len < 0) return 0;

    char *buffer = (char *)malloc((size_t)text_len + 1u);
    if (buffer == 0) return 0;

    uint32_t written = jinx_oracle_zend_scalar_write(buffer, value);
    buffer[written] = '\0';

    *owned = buffer;
    *bytes = (const unsigned char *)buffer;
    *len = written;
    return 1;
}

static inline int jinx_oracle_sort_binary_compare(
    const unsigned char *a,
    uint32_t a_len,
    const unsigned char *b,
    uint32_t b_len,
    int fold_case
) {
    uint32_t limit = a_len < b_len ? a_len : b_len;

    for (uint32_t i = 0u; i < limit; i++) {
        unsigned char ac = a[i];
        unsigned char bc = b[i];
        if (fold_case) {
            ac = jinx_oracle_ascii_lower_byte(ac);
            bc = jinx_oracle_ascii_lower_byte(bc);
        }
        if (ac < bc) return -1;
        if (ac > bc) return 1;
    }

    if (a_len == b_len) return 0;
    return a_len < b_len ? -1 : 1;
}

static inline int jinx_oracle_sort_numeric_value(JinxZendValue value, double *out) {
    if (value.type == JINX_ZEND_LONG) { *out = (double)value.value.lval; return 1; }
    if (value.type == JINX_ZEND_DOUBLE) { *out = value.value.dval; return 1; }
    if (value.type == JINX_ZEND_TRUE) { *out = 1.0; return 1; }
    if (value.type == JINX_ZEND_FALSE || value.type == JINX_ZEND_NULL) { *out = 0.0; return 1; }
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        char *end = 0;
        double parsed = strtod(value.value.str->bytes, &end);
        *out = end == value.value.str->bytes ? 0.0 : parsed;
        return 1;
    }
    return 0;
}

static inline int jinx_oracle_sort_regular_compare(JinxZendValue a, JinxZendValue b, int *ok) {
    *ok = 1;

    if (a.type == JINX_ZEND_NULL || a.type == JINX_ZEND_FALSE || a.type == JINX_ZEND_TRUE ||
        b.type == JINX_ZEND_NULL || b.type == JINX_ZEND_FALSE || b.type == JINX_ZEND_TRUE) {
        int av = jinx_oracle_zend_value_boolish(a);
        int bv = jinx_oracle_zend_value_boolish(b);
        return av == bv ? 0 : (av < bv ? -1 : 1);
    }

    int a_number = a.type == JINX_ZEND_LONG || a.type == JINX_ZEND_DOUBLE;
    int b_number = b.type == JINX_ZEND_LONG || b.type == JINX_ZEND_DOUBLE;

    if (a_number && b_number) {
        double av = a.type == JINX_ZEND_DOUBLE ? a.value.dval : (double)a.value.lval;
        double bv = b.type == JINX_ZEND_DOUBLE ? b.value.dval : (double)b.value.lval;
        return av == bv ? 0 : (av < bv ? -1 : 1);
    }

    if (a.type == JINX_ZEND_STRING && b.type == JINX_ZEND_STRING &&
        a.value.str != 0 && b.value.str != 0) {
        double av = 0.0;
        double bv = 0.0;
        int an = jinx_oracle_zend_string_numeric(a.value.str, &av);
        int bn = jinx_oracle_zend_string_numeric(b.value.str, &bv);

        if (an && bn) return av == bv ? 0 : (av < bv ? -1 : 1);

        return jinx_oracle_sort_binary_compare(
            (const unsigned char *)a.value.str->bytes, (uint32_t)a.value.str->len,
            (const unsigned char *)b.value.str->bytes, (uint32_t)b.value.str->len,
            0
        );
    }

    if (a_number && b.type == JINX_ZEND_STRING && b.value.str != 0) {
        double bv = 0.0;
        if (jinx_oracle_zend_string_numeric(b.value.str, &bv)) {
            double av = a.type == JINX_ZEND_DOUBLE ? a.value.dval : (double)a.value.lval;
            return av == bv ? 0 : (av < bv ? -1 : 1);
        }
    }
    if (b_number && a.type == JINX_ZEND_STRING && a.value.str != 0) {
        double av = 0.0;
        if (jinx_oracle_zend_string_numeric(a.value.str, &av)) {
            double bv = b.type == JINX_ZEND_DOUBLE ? b.value.dval : (double)b.value.lval;
            return av == bv ? 0 : (av < bv ? -1 : 1);
        }
    }

    char *a_owned = 0;
    char *b_owned = 0;
    const unsigned char *a_bytes = 0;
    const unsigned char *b_bytes = 0;
    uint32_t a_len = 0u;
    uint32_t b_len = 0u;

    if (!jinx_oracle_sort_text(a, &a_owned, &a_bytes, &a_len) ||
        !jinx_oracle_sort_text(b, &b_owned, &b_bytes, &b_len)) {
        free(a_owned);
        free(b_owned);
        *ok = 0;
        return 0;
    }

    int result = jinx_oracle_sort_binary_compare(a_bytes, a_len, b_bytes, b_len, 0);
    free(a_owned);
    free(b_owned);
    return result;
}

static inline int jinx_oracle_sort_value_compare(
    JinxZendValue a,
    JinxZendValue b,
    int sort_type,
    int *ok
) {
    int base_type = sort_type & ~8;
    int fold_case = (sort_type & 8) != 0;
    *ok = 1;

    if (base_type == 0) return jinx_oracle_sort_regular_compare(a, b, ok);

    if (base_type == 1) {
        double av = 0.0, bv = 0.0;
        if (!jinx_oracle_sort_numeric_value(a, &av) ||
            !jinx_oracle_sort_numeric_value(b, &bv)) {
            *ok = 0;
            return 0;
        }
        return av == bv ? 0 : (av < bv ? -1 : 1);
    }

    if (base_type == 2 || base_type == 6) {
        char *a_owned = 0;
        char *b_owned = 0;
        const unsigned char *a_bytes = 0;
        const unsigned char *b_bytes = 0;
        uint32_t a_len = 0u, b_len = 0u;

        if (!jinx_oracle_sort_text(a, &a_owned, &a_bytes, &a_len) ||
            !jinx_oracle_sort_text(b, &b_owned, &b_bytes, &b_len)) {
            free(a_owned);
            free(b_owned);
            *ok = 0;
            return 0;
        }

        int result = base_type == 6
            ? jinx_oracle_strnatcmp_bytes(a_bytes, a_len, b_bytes, b_len, fold_case)
            : jinx_oracle_sort_binary_compare(a_bytes, a_len, b_bytes, b_len, fold_case);
        free(a_owned);
        free(b_owned);
        return result;
    }

    *ok = 0;
    return 0;
}

static inline JinxZendValue jinx_oracle_sort_bucket_key(const JinxZendBucket *bucket) {
    return bucket->key != 0
        ? jinx_zend_string_value(bucket->key)
        : jinx_zend_long((int64_t)bucket->h);
}

static inline int jinx_oracle_sort_entry_compare(
    const JinxOracleSortEntry *a,
    const JinxOracleSortEntry *b,
    int sort_type,
    int by_key,
    int reverse,
    int *ok
) {
    JinxZendValue av = by_key ? jinx_oracle_sort_bucket_key(a->bucket) : a->bucket->value;
    JinxZendValue bv = by_key ? jinx_oracle_sort_bucket_key(b->bucket) : b->bucket->value;
    int cmp = jinx_oracle_sort_value_compare(av, bv, sort_type, ok);
    return *ok && reverse ? -cmp : cmp;
}

static inline int jinx_oracle_stable_sort_entries(
    JinxOracleSortEntry *entries,
    size_t count,
    int sort_type,
    int by_key,
    int reverse
) {
    if (count < 2u) return 1;

    JinxOracleSortEntry *tmp = (JinxOracleSortEntry *)malloc(count * sizeof(*tmp));
    if (tmp == 0) return 0;

    for (size_t width = 1u; width < count; width *= 2u) {
        for (size_t left = 0u; left < count; left += width * 2u) {
            size_t mid = left + width;
            size_t right = left + width * 2u;
            if (mid > count) mid = count;
            if (right > count) right = count;

            size_t i = left, j = mid, k = left;
            while (i < mid && j < right) {
                int ok = 0;
                int cmp = jinx_oracle_sort_entry_compare(
                    &entries[i], &entries[j], sort_type, by_key, reverse, &ok
                );
                if (!ok) { free(tmp); return 0; }
                tmp[k++] = cmp <= 0 ? entries[i++] : entries[j++];
            }
            while (i < mid) tmp[k++] = entries[i++];
            while (j < right) tmp[k++] = entries[j++];
            for (k = left; k < right; k++) entries[k] = tmp[k];
        }
        if (width > count / 2u) break;
    }

    free(tmp);
    return 1;
}


static inline int jinx_oracle_replace_array_contents(
    JinxZendArray *target,
    JinxZendArray *replacement
);

static inline int jinx_oracle_user_sort_entry_compare(
    const JinxOracleSortEntry *a,
    const JinxOracleSortEntry *b,
    int by_key,
    JinxValue callback,
    int *ok
) {
    JinxValue av;
    JinxValue bv;

    if (by_key) {
        JinxZendValue ak = jinx_oracle_sort_bucket_key(a->bucket);
        JinxZendValue bk = jinx_oracle_sort_bucket_key(b->bucket);

        if (!jinx_oracle_zend_to_jinx_borrowed(ak, &av) ||
            !jinx_oracle_zend_to_jinx_borrowed(bk, &bv)) {
            *ok = 0;
            return 0;
        }
    } else {
        if (!jinx_oracle_zend_to_jinx_borrowed(a->bucket->value, &av) ||
            !jinx_oracle_zend_to_jinx_borrowed(b->bucket->value, &bv)) {
            *ok = 0;
            return 0;
        }
    }

    return jinx_oracle_named_comparator_result(
        callback,
        av,
        bv,
        ok
    );
}

static inline int jinx_oracle_stable_user_sort_entries(
    JinxOracleSortEntry *entries,
    size_t count,
    int by_key,
    JinxValue callback
) {
    if (count < 2u) return 1;

    JinxOracleSortEntry *tmp = (JinxOracleSortEntry *)malloc(count * sizeof(*tmp));
    if (tmp == 0) return 0;

    for (size_t width = 1u; width < count; width *= 2u) {
        for (size_t left = 0u; left < count; left += width * 2u) {
            size_t mid = left + width;
            size_t right = left + width * 2u;
            if (mid > count) mid = count;
            if (right > count) right = count;

            size_t i = left;
            size_t j = mid;
            size_t k = left;

            while (i < mid && j < right) {
                int ok = 0;
                int cmp = jinx_oracle_user_sort_entry_compare(
                    &entries[i],
                    &entries[j],
                    by_key,
                    callback,
                    &ok
                );
                if (!ok) {
                    free(tmp);
                    return 0;
                }

                tmp[k++] = cmp <= 0 ? entries[i++] : entries[j++];
            }

            while (i < mid) tmp[k++] = entries[i++];
            while (j < right) tmp[k++] = entries[j++];

            for (k = left; k < right; k++) {
                entries[k] = tmp[k];
            }
        }

        if (width > count / 2u) break;
    }

    free(tmp);
    return 1;
}

static inline JinxValue jinx_oracle_zend_user_sort_special(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    if (name == 0 || args == 0 || argc < 2u || args[1].type != 3u) {
        return jinx_oracle_zero_value();
    }

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    int by_key = strcmp(name, "uksort") == 0;
    int renumber = strcmp(name, "usort") == 0;
    size_t live = jinx_zend_array_live_count(array);

    JinxOracleSortEntry *entries = live == 0u
        ? 0
        : (JinxOracleSortEntry *)malloc(live * sizeof(*entries));

    if (live != 0u && entries == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        entries[i].bucket = jinx_zend_array_live_iter_at(array, i);
        entries[i].ordinal = i;
    }

    if (!jinx_oracle_stable_user_sort_entries(
        entries,
        live,
        by_key,
        args[1]
    )) {
        free(entries);
        return jinx_oracle_zero_value();
    }

    JinxZendArray *result = jinx_zend_array_new_packed(
        live == 0u ? 1u : live
    );
    if (result == 0) {
        free(entries);
        return jinx_oracle_zero_value();
    }

    for (size_t i = 0u; i < live; i++) {
        int added = renumber
            ? jinx_zend_array_append(result, entries[i].bucket->value)
            : jinx_oracle_zend_add_bucket(result, entries[i].bucket, 1);

        if (!added) {
            free(entries);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    free(entries);

    if (!jinx_oracle_replace_array_contents(array, result)) {
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_bool_value(1);
}

static inline int jinx_oracle_replace_array_contents(
    JinxZendArray *target,
    JinxZendArray *replacement
) {
    if (target == 0 || replacement == 0) return 0;

    if (target->buckets != 0) {
        for (size_t i = 0u; i < target->count; i++) {
            if (!jinx_zend_bucket_is_tombstone(&target->buckets[i])) {
                jinx_zend_string_release(target->buckets[i].key);
                jinx_zend_value_release(target->buckets[i].value);
            }
        }
        free(target->buckets);
    }

    uint32_t refcount = target->refcount;
    target->flags = replacement->flags;
    target->count = replacement->count;
    target->capacity = replacement->capacity;
    target->next_index = replacement->next_index;
    target->internal_pointer = replacement->internal_pointer;
    target->buckets = replacement->buckets;
    target->refcount = refcount;

    replacement->buckets = 0;
    replacement->count = 0u;
    replacement->capacity = 0u;
    replacement->next_index = 0u;
    replacement->internal_pointer = 0u;
    jinx_zend_array_release(replacement);
    return 1;
}

static inline JinxValue jinx_oracle_zend_sort_special(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    JinxZendArray *array = argc >= 1u ? jinx_oracle_zend_array_ptr(args[0]) : 0;
    if (array == 0) return jinx_oracle_zero_value();

    int sort_type = argc >= 2u ? (int)jinx_oracle_intish(args[1]) : 0;
    int by_key = strcmp(name, "ksort") == 0 || strcmp(name, "krsort") == 0;
    int reverse = strcmp(name, "rsort") == 0 || strcmp(name, "arsort") == 0 ||
        strcmp(name, "krsort") == 0;
    int renumber = strcmp(name, "sort") == 0 || strcmp(name, "rsort") == 0;

    if (strcmp(name, "natsort") == 0 || strcmp(name, "natcasesort") == 0) {
        sort_type = 6 | (strcmp(name, "natcasesort") == 0 ? 8 : 0);
        by_key = 0;
        reverse = 0;
        renumber = 0;
    }

    int base_type = sort_type & ~8;
    if (base_type == 5 || (base_type != 0 && base_type != 1 && base_type != 2 && base_type != 6)) {
        return jinx_oracle_zero_value();
    }

    size_t live = jinx_zend_array_live_count(array);
    JinxOracleSortEntry *entries = live == 0u ? 0 :
        (JinxOracleSortEntry *)malloc(live * sizeof(*entries));
    if (live != 0u && entries == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        entries[i].bucket = jinx_zend_array_live_iter_at(array, i);
        entries[i].ordinal = i;
    }

    if (!jinx_oracle_stable_sort_entries(entries, live, sort_type, by_key, reverse)) {
        free(entries);
        return jinx_oracle_zero_value();
    }

    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) { free(entries); return jinx_oracle_zero_value(); }

    for (size_t i = 0u; i < live; i++) {
        int ok = renumber
            ? jinx_zend_array_append(result, entries[i].bucket->value)
            : jinx_oracle_zend_add_bucket(result, entries[i].bucket, 1);
        if (!ok) {
            free(entries);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    free(entries);
    if (!jinx_oracle_replace_array_contents(array, result)) return jinx_oracle_zero_value();
    return jinx_oracle_bool_value(1);
}


typedef struct JinxOracleMultiSortColumn {
    JinxZendArray *array;
    int sort_type;
    int reverse;
} JinxOracleMultiSortColumn;

typedef struct JinxOracleMultiSortRow {
    size_t index;
} JinxOracleMultiSortRow;

static inline int jinx_oracle_multisort_row_compare(
    const JinxOracleMultiSortRow *a,
    const JinxOracleMultiSortRow *b,
    const JinxOracleMultiSortColumn *columns,
    size_t column_count,
    int *ok
) {
    *ok = 1;

    for (size_t i = 0u; i < column_count; i++) {
        const JinxZendBucket *ab = jinx_zend_array_live_iter_at(columns[i].array, a->index);
        const JinxZendBucket *bb = jinx_zend_array_live_iter_at(columns[i].array, b->index);
        if (ab == 0 || bb == 0) {
            *ok = 0;
            return 0;
        }

        int cmp = jinx_oracle_sort_value_compare(
            ab->value,
            bb->value,
            columns[i].sort_type,
            ok
        );
        if (!*ok) return 0;

        if (cmp != 0) {
            return columns[i].reverse ? -cmp : cmp;
        }
    }

    return a->index == b->index ? 0 : (a->index < b->index ? -1 : 1);
}

static inline int jinx_oracle_stable_multisort_rows(
    JinxOracleMultiSortRow *rows,
    size_t count,
    const JinxOracleMultiSortColumn *columns,
    size_t column_count
) {
    if (count < 2u) return 1;

    JinxOracleMultiSortRow *tmp = (JinxOracleMultiSortRow *)malloc(count * sizeof(*tmp));
    if (tmp == 0) return 0;

    for (size_t width = 1u; width < count; width *= 2u) {
        for (size_t left = 0u; left < count; left += width * 2u) {
            size_t mid = left + width;
            size_t right = left + width * 2u;
            if (mid > count) mid = count;
            if (right > count) right = count;

            size_t i = left;
            size_t j = mid;
            size_t k = left;

            while (i < mid && j < right) {
                int ok = 0;
                int cmp = jinx_oracle_multisort_row_compare(
                    &rows[i],
                    &rows[j],
                    columns,
                    column_count,
                    &ok
                );
                if (!ok) {
                    free(tmp);
                    return 0;
                }

                tmp[k++] = cmp <= 0 ? rows[i++] : rows[j++];
            }

            while (i < mid) tmp[k++] = rows[i++];
            while (j < right) tmp[k++] = rows[j++];

            for (k = left; k < right; k++) rows[k] = tmp[k];
        }

        if (width > count / 2u) break;
    }

    free(tmp);
    return 1;
}

static inline JinxValue jinx_oracle_zend_array_multisort_special(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc == 0u) return jinx_oracle_zero_value();

    if (strcmp(name, "json_decode") == 0) {
        return jinx_oracle_zend_json_decode_special(args, argc);
    }

    JinxOracleMultiSortColumn columns[32];
    size_t column_count = 0u;
    size_t row_count = SIZE_MAX;
    int current = -1;

    for (size_t i = 0u; i < argc; i++) {
        if (jinx_oracle_value_is_zend_array(args[i])) {
            JinxZendArray *array = jinx_oracle_zend_array_ptr(args[i]);
            if (array == 0 || column_count >= 32u) return jinx_oracle_zero_value();

            for (size_t prior = 0u; prior < column_count; prior++) {
                if (columns[prior].array == array) {
                    return jinx_oracle_zero_value();
                }
            }

            size_t live = jinx_zend_array_live_count(array);
            if (row_count == SIZE_MAX) {
                row_count = live;
            } else if (row_count != live) {
                return jinx_oracle_zero_value();
            }

            columns[column_count].array = array;
            columns[column_count].sort_type = 0;
            columns[column_count].reverse = 0;
            current = (int)column_count;
            column_count++;
            continue;
        }

        if (args[i].type != 1u || current < 0) {
            return jinx_oracle_zero_value();
        }

        int flag = (int)jinx_oracle_intish(args[i]);

        if (flag == 3) {
            columns[current].reverse = 1;
            continue;
        }
        if (flag == 4) {
            columns[current].reverse = 0;
            continue;
        }

        int base_type = flag & ~8;
        if (base_type == 5) {
            return jinx_oracle_zero_value();
        }
        if (base_type != 0 && base_type != 1 && base_type != 2 && base_type != 6) {
            return jinx_oracle_zero_value();
        }

        columns[current].sort_type = flag;
    }

    if (column_count == 0u || row_count == SIZE_MAX) return jinx_oracle_zero_value();

    JinxOracleMultiSortRow *rows = row_count == 0u
        ? 0
        : (JinxOracleMultiSortRow *)malloc(row_count * sizeof(*rows));
    if (row_count != 0u && rows == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < row_count; i++) rows[i].index = i;

    if (!jinx_oracle_stable_multisort_rows(rows, row_count, columns, column_count)) {
        free(rows);
        return jinx_oracle_zero_value();
    }

    JinxZendArray *rebuilt[32] = {0};

    for (size_t col = 0u; col < column_count; col++) {
        rebuilt[col] = jinx_zend_array_new_packed(row_count == 0u ? 1u : row_count);
        if (rebuilt[col] == 0) {
            for (size_t n = 0u; n < col; n++) jinx_zend_array_release(rebuilt[n]);
            free(rows);
            return jinx_oracle_zero_value();
        }

        for (size_t row = 0u; row < row_count; row++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(
                columns[col].array,
                rows[row].index
            );
            if (bucket == 0) {
                for (size_t n = 0u; n <= col; n++) jinx_zend_array_release(rebuilt[n]);
                free(rows);
                return jinx_oracle_zero_value();
            }

            int added = bucket->key != 0
                ? jinx_zend_array_add_assoc(
                    rebuilt[col],
                    bucket->key->bytes,
                    bucket->key->len,
                    bucket->value
                )
                : jinx_zend_array_append(rebuilt[col], bucket->value);

            if (!added) {
                for (size_t n = 0u; n <= col; n++) jinx_zend_array_release(rebuilt[n]);
                free(rows);
                return jinx_oracle_zero_value();
            }
        }
    }

    free(rows);

    for (size_t col = 0u; col < column_count; col++) {
        if (!jinx_oracle_replace_array_contents(columns[col].array, rebuilt[col])) {
            for (size_t n = col + 1u; n < column_count; n++) jinx_zend_array_release(rebuilt[n]);
            return jinx_oracle_zero_value();
        }
        rebuilt[col] = 0;
    }

    return jinx_oracle_bool_value(1);
}


static inline int jinx_oracle_zend_to_jinx_borrowed(
    JinxZendValue value,
    JinxValue *out
) {
    if (out == 0) return 0;

    if (value.type == JINX_ZEND_NULL) {
        *out = jinx_oracle_zero_value();
        return 1;
    }
    if (value.type == JINX_ZEND_FALSE) {
        *out = jinx_oracle_bool_value(0);
        return 1;
    }
    if (value.type == JINX_ZEND_TRUE) {
        *out = jinx_oracle_bool_value(1);
        return 1;
    }
    if (value.type == JINX_ZEND_LONG) {
        *out = jinx_oracle_int_value(value.value.lval);
        return 1;
    }
    if (value.type == JINX_ZEND_DOUBLE) {
        *out = jinx_oracle_float_value(value.value.dval);
        return 1;
    }
    if (value.type == JINX_ZEND_STRING && value.value.str != 0) {
        if (value.value.str->len > UINT32_MAX) return 0;
        *out = jinx_oracle_string_value_len(
            value.value.str->bytes,
            (uint32_t)value.value.str->len
        );
        return 1;
    }
    if (value.type == JINX_ZEND_ARRAY && value.value.array != 0) {
        *out = jinx_oracle_zend_array_value_borrowed(value.value.array);
        return 1;
    }

    return 0;
}

static inline int jinx_oracle_callback_truthy(JinxValue value) {
    if (jinx_oracle_value_is_zend_array(value)) {
        JinxZendArray *array = jinx_oracle_zend_array_ptr(value);
        return array != 0 && jinx_zend_array_live_count(array) != 0u;
    }
    return jinx_oracle_boolish(value);
}

static inline int jinx_oracle_invoke_named_callback(
    JinxValue callback,
    JinxValue *args,
    size_t argc,
    JinxValue *result
) {
    if (result == 0 || callback.type != 3u || callback.as.ptr == 0) return 0;

    const unsigned char *bytes = jinx_oracle_string_bytes(callback);
    uint32_t len = jinx_oracle_string_len(callback);
    if (len == 0u) return 0;

    for (uint32_t i = 0u; i < len; i++) {
        if (bytes[i] == 0u) return 0;
    }

    char *name = (char *)malloc((size_t)len + 1u);
    if (name == 0) return 0;
    memcpy(name, bytes, len);
    name[len] = '\0';

    int ok = 0;
    *result = jinx_call_builtin_through_oracle_checked(name, args, argc, &ok);
    free(name);
    return ok;
}

static inline int jinx_oracle_zend_owned_from_jinx(
    JinxValue value,
    JinxZendValue *out
) {
    if (out == 0) return 0;

    JinxZendValue temporary;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(value, &temporary, &owned_string)) {
        return 0;
    }

    *out = jinx_zend_value_copy(temporary);
    jinx_zend_string_release(owned_string);
    return 1;
}

static inline int jinx_oracle_store_callback_result(
    JinxZendArray *target,
    const JinxZendBucket *source_bucket,
    int preserve_key,
    JinxValue callback_result
) {
    if (target == 0) {
        jinx_oracle_zend_array_value_release(callback_result);
        return 0;
    }

    JinxZendValue zend_result;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(
        callback_result,
        &zend_result,
        &owned_string
    )) {
        jinx_oracle_zend_array_value_release(callback_result);
        return 0;
    }

    int ok;
    if (preserve_key && source_bucket != 0) {
        ok = source_bucket->key != 0
            ? jinx_zend_array_add_assoc(
                target,
                source_bucket->key->bytes,
                source_bucket->key->len,
                zend_result
            )
            : jinx_zend_array_add_index(
                target,
                (size_t)source_bucket->h,
                zend_result
            );
    } else {
        ok = jinx_zend_array_append(target, zend_result);
    }

    jinx_zend_string_release(owned_string);
    jinx_oracle_zend_array_value_release(callback_result);
    return ok;
}


static inline JinxValue jinx_oracle_zend_array_map_named(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 2u) return jinx_oracle_zero_value();

    size_t array_count = argc - 1u;
    JinxZendArray **arrays = (JinxZendArray **)calloc(array_count, sizeof(*arrays));
    if (arrays == 0) return jinx_oracle_zero_value();

    size_t max_live = 0u;
    for (size_t i = 0u; i < array_count; i++) {
        arrays[i] = jinx_oracle_zend_array_ptr(args[i + 1u]);
        if (arrays[i] == 0) {
            free(arrays);
            return jinx_oracle_zero_value();
        }
        size_t live = jinx_zend_array_live_count(arrays[i]);
        if (live > max_live) max_live = live;
    }

    if (args[0].type == 0u) {
        if (array_count == 1u) {
            JinxZendArray *copy = jinx_oracle_zend_clone_live_array(arrays[0]);
            free(arrays);
            return jinx_oracle_zend_array_value_owned(copy);
        }

        JinxZendArray *result = jinx_zend_array_new_packed(max_live == 0u ? 1u : max_live);
        if (result == 0) {
            free(arrays);
            return jinx_oracle_zero_value();
        }

        for (size_t pos = 0u; pos < max_live; pos++) {
            JinxZendArray *row = jinx_zend_array_new_packed(array_count);
            if (row == 0) {
                free(arrays);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }

            for (size_t a = 0u; a < array_count; a++) {
                const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(arrays[a], pos);
                JinxZendValue value = bucket != 0 ? bucket->value : jinx_zend_null();
                if (!jinx_zend_array_append(row, value)) {
                    jinx_zend_array_release(row);
                    free(arrays);
                    jinx_zend_array_release(result);
                    return jinx_oracle_zero_value();
                }
            }

            if (!jinx_zend_array_append(result, jinx_zend_array_value(row))) {
                jinx_zend_array_release(row);
                free(arrays);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
            jinx_zend_array_release(row);
        }

        free(arrays);
        return jinx_oracle_zend_array_value_owned(result);
    }

    if (args[0].type != 3u) {
        free(arrays);
        return jinx_oracle_zero_value();
    }

    JinxZendArray *result = jinx_zend_array_new_packed(max_live == 0u ? 1u : max_live);
    if (result == 0) {
        free(arrays);
        return jinx_oracle_zero_value();
    }

    if (array_count == 1u) {
        size_t live = jinx_zend_array_live_count(arrays[0]);
        for (size_t pos = 0u; pos < live; pos++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(arrays[0], pos);
            JinxValue cb_args[1];
            if (bucket == 0 ||
                !jinx_oracle_zend_to_jinx_borrowed(bucket->value, &cb_args[0])) {
                free(arrays);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }

            JinxValue cb_result;
            if (!jinx_oracle_invoke_named_callback(args[0], cb_args, 1u, &cb_result) ||
                !jinx_oracle_store_callback_result(result, bucket, 1, cb_result)) {
                free(arrays);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }

        free(arrays);
        return jinx_oracle_zend_array_value_owned(result);
    }

    JinxValue *cb_args = (JinxValue *)malloc(array_count * sizeof(*cb_args));
    if (cb_args == 0) {
        free(arrays);
        jinx_zend_array_release(result);
        return jinx_oracle_zero_value();
    }

    for (size_t pos = 0u; pos < max_live; pos++) {
        for (size_t a = 0u; a < array_count; a++) {
            const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(arrays[a], pos);
            if (bucket == 0) {
                cb_args[a] = jinx_oracle_zero_value();
            } else if (!jinx_oracle_zend_to_jinx_borrowed(bucket->value, &cb_args[a])) {
                free(cb_args);
                free(arrays);
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }

        JinxValue cb_result;
        if (!jinx_oracle_invoke_named_callback(args[0], cb_args, array_count, &cb_result) ||
            !jinx_oracle_store_callback_result(result, 0, 0, cb_result)) {
            free(cb_args);
            free(arrays);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    free(cb_args);
    free(arrays);
    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_reduce_named(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 2u || args[1].type != 3u) {
        return jinx_oracle_zero_value();
    }

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    JinxZendValue accumulator;
    JinxValue initial = argc >= 3u ? args[2] : jinx_oracle_zero_value();
    if (!jinx_oracle_zend_owned_from_jinx(initial, &accumulator)) {
        return jinx_oracle_zero_value();
    }

    size_t live = jinx_zend_array_live_count(array);
    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        JinxValue cb_args[2];

        if (bucket == 0 ||
            !jinx_oracle_zend_to_jinx_borrowed(accumulator, &cb_args[0]) ||
            !jinx_oracle_zend_to_jinx_borrowed(bucket->value, &cb_args[1])) {
            jinx_zend_value_release(accumulator);
            return jinx_oracle_zero_value();
        }

        JinxValue cb_result;
        if (!jinx_oracle_invoke_named_callback(args[1], cb_args, 2u, &cb_result)) {
            jinx_zend_value_release(accumulator);
            return jinx_oracle_zero_value();
        }

        JinxZendValue next;
        if (!jinx_oracle_zend_owned_from_jinx(cb_result, &next)) {
            jinx_oracle_zend_array_value_release(cb_result);
            jinx_zend_value_release(accumulator);
            return jinx_oracle_zero_value();
        }

        jinx_oracle_zend_array_value_release(cb_result);
        jinx_zend_value_release(accumulator);
        accumulator = next;
    }

    JinxValue result = jinx_oracle_zend_value_return_copy(accumulator);
    jinx_zend_value_release(accumulator);
    return result;
}

static inline JinxValue jinx_oracle_zend_array_filter_named(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 1u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    if (argc < 2u || args[1].type == 0u) {
        return jinx_oracle_zend_array_filter_default(args, argc);
    }

    if (args[1].type != 3u) return jinx_oracle_zero_value();

    int mode = argc >= 3u ? (int)jinx_oracle_intish(args[2]) : 0;
    if (mode != 0 && mode != 1 && mode != 2) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }

        JinxValue cb_args[2];
        size_t cb_argc = 1u;

        if (mode == 2) {
            cb_args[0] = jinx_oracle_zend_bucket_key_value(bucket);
        } else {
            if (!jinx_oracle_zend_to_jinx_borrowed(bucket->value, &cb_args[0])) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
            if (mode == 1) {
                cb_args[1] = jinx_oracle_zend_bucket_key_value(bucket);
                cb_argc = 2u;
            }
        }

        JinxValue cb_result;
        if (!jinx_oracle_invoke_named_callback(args[1], cb_args, cb_argc, &cb_result)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }

        int keep = jinx_oracle_callback_truthy(cb_result);
        jinx_oracle_zend_array_value_release(cb_result);

        if (keep && !jinx_oracle_zend_add_bucket(result, bucket, 1)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_predicate_named(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    if (name == 0 || args == 0 || argc < 2u || args[1].type != 3u) {
        return jinx_oracle_zero_value();
    }

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        JinxValue cb_args[2];

        if (bucket == 0 ||
            !jinx_oracle_zend_to_jinx_borrowed(bucket->value, &cb_args[0])) {
            return jinx_oracle_zero_value();
        }
        cb_args[1] = jinx_oracle_zend_bucket_key_value(bucket);

        JinxValue cb_result;
        if (!jinx_oracle_invoke_named_callback(args[1], cb_args, 2u, &cb_result)) {
            return jinx_oracle_zero_value();
        }

        int matched = jinx_oracle_callback_truthy(cb_result);
        jinx_oracle_zend_array_value_release(cb_result);

        if (strcmp(name, "array_all") == 0) {
            if (!matched) return jinx_oracle_bool_value(0);
            continue;
        }

        if (!matched) continue;

        if (strcmp(name, "array_any") == 0) {
            return jinx_oracle_bool_value(1);
        }
        if (strcmp(name, "array_find") == 0) {
            return jinx_oracle_zend_value_return_copy(bucket->value);
        }
        if (strcmp(name, "array_find_key") == 0) {
            return jinx_oracle_zend_bucket_key_value(bucket);
        }
    }

    if (strcmp(name, "array_all") == 0) return jinx_oracle_bool_value(1);
    if (strcmp(name, "array_any") == 0) return jinx_oracle_bool_value(0);
    return jinx_oracle_zero_value();
}


static inline int jinx_oracle_named_comparator_result(
    JinxValue callback,
    JinxValue left,
    JinxValue right,
    int *ok
) {
    JinxValue cb_args[2];
    JinxValue cb_result;

    *ok = 0;
    cb_args[0] = left;
    cb_args[1] = right;

    if (!jinx_oracle_invoke_named_callback(callback, cb_args, 2u, &cb_result)) {
        return 0;
    }

    if (jinx_oracle_value_is_zend_array(cb_result) || cb_result.type == 4u) {
        jinx_oracle_zend_array_value_release(cb_result);
        return 0;
    }

    int64_t raw = jinx_oracle_intish(cb_result);
    jinx_oracle_zend_array_value_release(cb_result);
    *ok = 1;
    return raw < 0 ? -1 : (raw > 0 ? 1 : 0);
}

static inline int jinx_oracle_named_comparator_equal(
    JinxValue callback,
    JinxValue left,
    JinxValue right,
    int *ok
) {
    int cmp = jinx_oracle_named_comparator_result(
        callback,
        left,
        right,
        ok
    );
    return *ok && cmp == 0;
}

static inline int jinx_oracle_user_compare_bucket_match(
    const JinxZendBucket *first,
    const JinxZendBucket *other,
    int key_required,
    int key_user,
    JinxValue key_callback,
    int data_required,
    int data_user,
    JinxValue data_callback,
    int *ok
) {
    *ok = 0;
    if (first == 0 || other == 0) return 0;

    if (key_required) {
        int key_equal;

        if (key_user) {
            JinxValue first_key = jinx_oracle_zend_bucket_key_value(first);
            JinxValue other_key = jinx_oracle_zend_bucket_key_value(other);
            key_equal = jinx_oracle_named_comparator_equal(
                key_callback,
                first_key,
                other_key,
                ok
            );
            if (!*ok) return 0;
        } else {
            key_equal = jinx_oracle_zend_bucket_key_equal(first, other);
            *ok = 1;
        }

        if (!key_equal) return 0;
    }

    if (!data_required) {
        *ok = 1;
        return 1;
    }

    if (data_user) {
        JinxValue first_value;
        JinxValue other_value;

        if (!jinx_oracle_zend_to_jinx_borrowed(first->value, &first_value) ||
            !jinx_oracle_zend_to_jinx_borrowed(other->value, &other_value)) {
            return 0;
        }

        return jinx_oracle_named_comparator_equal(
            data_callback,
            first_value,
            other_value,
            ok
        );
    }

    *ok = 1;
    return jinx_oracle_zend_scalar_text_equal(first->value, other->value);
}

static inline JinxValue jinx_oracle_zend_user_diff_intersect_special(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    if (name == 0 || args == 0) return jinx_oracle_zero_value();

    int intersect = strstr(name, "intersect") != 0;
    int dual_callback =
        strcmp(name, "array_udiff_uassoc") == 0 ||
        strcmp(name, "array_uintersect_uassoc") == 0;

    int user_data =
        strcmp(name, "array_udiff") == 0 ||
        strcmp(name, "array_udiff_assoc") == 0 ||
        strcmp(name, "array_udiff_uassoc") == 0 ||
        strcmp(name, "array_uintersect") == 0 ||
        strcmp(name, "array_uintersect_assoc") == 0 ||
        strcmp(name, "array_uintersect_uassoc") == 0;

    int user_key =
        strcmp(name, "array_diff_uassoc") == 0 ||
        strcmp(name, "array_diff_ukey") == 0 ||
        strcmp(name, "array_udiff_uassoc") == 0 ||
        strcmp(name, "array_intersect_uassoc") == 0 ||
        strcmp(name, "array_intersect_ukey") == 0 ||
        strcmp(name, "array_uintersect_uassoc") == 0;

    int key_only =
        strcmp(name, "array_diff_ukey") == 0 ||
        strcmp(name, "array_intersect_ukey") == 0;

    int assoc =
        strcmp(name, "array_diff_uassoc") == 0 ||
        strcmp(name, "array_udiff_assoc") == 0 ||
        strcmp(name, "array_udiff_uassoc") == 0 ||
        strcmp(name, "array_intersect_uassoc") == 0 ||
        strcmp(name, "array_uintersect_assoc") == 0 ||
        strcmp(name, "array_uintersect_uassoc") == 0;

    size_t callback_count = dual_callback ? 2u : 1u;
    if (argc < 2u + callback_count) return jinx_oracle_zero_value();

    size_t array_count = argc - callback_count;
    if (array_count < 2u) return jinx_oracle_zero_value();

    JinxValue data_callback = jinx_oracle_zero_value();
    JinxValue key_callback = jinx_oracle_zero_value();

    if (dual_callback) {
        data_callback = args[argc - 2u];
        key_callback = args[argc - 1u];
        if (data_callback.type != 3u || key_callback.type != 3u) {
            return jinx_oracle_zero_value();
        }
    } else if (user_data) {
        data_callback = args[argc - 1u];
        if (data_callback.type != 3u) return jinx_oracle_zero_value();
    } else if (user_key) {
        key_callback = args[argc - 1u];
        if (key_callback.type != 3u) return jinx_oracle_zero_value();
    } else {
        return jinx_oracle_zero_value();
    }

    JinxZendArray *first = jinx_oracle_zend_array_ptr(args[0]);
    if (first == 0) return jinx_oracle_zero_value();

    for (size_t a = 1u; a < array_count; a++) {
        if (!jinx_oracle_value_is_zend_array(args[a])) {
            return jinx_oracle_zero_value();
        }
    }

    size_t live = jinx_zend_array_live_count(first);
    JinxZendArray *result = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(first, i);
        if (bucket == 0) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }

        int matched_any = 0;
        int matched_all = 1;

        for (size_t a = 1u; a < array_count; a++) {
            JinxZendArray *other = jinx_oracle_zend_array_ptr(args[a]);
            size_t other_live = jinx_zend_array_live_count(other);
            int matched_this = 0;

            for (size_t j = 0u; j < other_live; j++) {
                const JinxZendBucket *candidate = jinx_zend_array_live_iter_at(other, j);
                int compare_ok = 0;
                int match = jinx_oracle_user_compare_bucket_match(
                    bucket,
                    candidate,
                    key_only || assoc,
                    user_key,
                    key_callback,
                    !key_only,
                    user_data,
                    data_callback,
                    &compare_ok
                );

                if (!compare_ok) {
                    jinx_zend_array_release(result);
                    return jinx_oracle_zero_value();
                }

                if (match) {
                    matched_this = 1;
                    break;
                }
            }

            if (matched_this) matched_any = 1;
            else matched_all = 0;

            if (!intersect && matched_any) break;
            if (intersect && !matched_all) break;
        }

        if ((intersect && matched_all) || (!intersect && !matched_any)) {
            if (!jinx_oracle_zend_add_bucket(result, bucket, 1)) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}


static inline int jinx_oracle_walk_seen(
    JinxZendArray **stack,
    size_t depth,
    JinxZendArray *array
) {
    for (size_t i = 0u; i < depth; i++) {
        if (stack[i] == array) return 1;
    }
    return 0;
}

static inline int jinx_oracle_zend_walk_array(
    JinxZendArray *array,
    JinxValue callback,
    JinxValue userdata,
    int has_userdata,
    int recursive,
    JinxZendArray **stack,
    size_t depth
) {
    if (array == 0 || callback.type != 3u || depth >= 64u) return 0;
    if (jinx_oracle_walk_seen(stack, depth, array)) return 0;

    stack[depth] = array;
    size_t live = jinx_zend_array_live_count(array);

    for (size_t i = 0u; i < live; i++) {
        JinxZendBucket *bucket = (JinxZendBucket *)jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0) return 0;

        if (recursive &&
            bucket->value.type == JINX_ZEND_ARRAY &&
            bucket->value.value.array != 0) {
            JinxZendArray *child = jinx_zend_array_separate(&bucket->value.value.array);
            if (child == 0 ||
                !jinx_oracle_zend_walk_array(
                    child,
                    callback,
                    userdata,
                    has_userdata,
                    1,
                    stack,
                    depth + 1u
                )) {
                return 0;
            }
            continue;
        }

        JinxValue cb_args[3];
        if (!jinx_oracle_zend_to_jinx_borrowed(bucket->value, &cb_args[0])) {
            return 0;
        }
        cb_args[1] = jinx_oracle_zend_bucket_key_value(bucket);
        size_t cb_argc = 2u;

        if (has_userdata) {
            cb_args[2] = userdata;
            cb_argc = 3u;
        }

        JinxValue cb_result;
        if (!jinx_oracle_invoke_named_callback(
            callback,
            cb_args,
            cb_argc,
            &cb_result
        )) {
            return 0;
        }
        jinx_oracle_zend_array_value_release(cb_result);

        JinxZendValue replacement;
        if (!jinx_oracle_zend_owned_from_jinx(cb_args[0], &replacement)) {
            jinx_oracle_zend_array_value_release(cb_args[0]);
            return 0;
        }

        jinx_oracle_zend_array_value_release(cb_args[0]);
        jinx_zend_value_release(bucket->value);
        bucket->value = replacement;
    }

    return 1;
}

static inline JinxValue jinx_oracle_zend_array_walk_named(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    if (name == 0 || args == 0 || argc < 2u || args[1].type != 3u) {
        return jinx_oracle_zero_value();
    }

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    JinxZendArray *stack[64] = {0};
    int recursive = strcmp(name, "array_walk_recursive") == 0;
    int ok = jinx_oracle_zend_walk_array(
        array,
        args[1],
        argc >= 3u ? args[2] : jinx_oracle_zero_value(),
        argc >= 3u,
        recursive,
        stack,
        0u
    );

    return ok ? jinx_oracle_bool_value(1) : jinx_oracle_zero_value();
}


static inline JinxValue jinx_oracle_zend_array_rand_special(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 1u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    if (live == 0u) return jinx_oracle_zero_value();

    int64_t requested = argc >= 2u ? jinx_oracle_intish(args[1]) : 1;
    if (requested <= 0 || (uint64_t)requested > (uint64_t)live) {
        return jinx_oracle_zero_value();
    }

    if (requested == 1) {
        const JinxZendBucket *bucket = 0;
        int range_ok = 0;

        if (live < array->count - (array->count >> 1u)) {
            uint32_t position = jinx_oracle_mt_range32((uint32_t)(live - 1u), &range_ok);
            if (!range_ok) return jinx_oracle_zero_value();
            bucket = jinx_zend_array_live_iter_at(array, (size_t)position);
        } else {
            do {
                uint32_t position = jinx_oracle_mt_range32((uint32_t)(array->count - 1u), &range_ok);
                if (!range_ok) return jinx_oracle_zero_value();
                if (position < array->count &&
                    !jinx_zend_bucket_is_tombstone(&array->buckets[position])) {
                    bucket = &array->buckets[position];
                    break;
                }
            } while (bucket == 0);
        }

        return bucket != 0
            ? jinx_oracle_zend_bucket_key_value(bucket)
            : jinx_oracle_zero_value();
    }

    size_t select_count = (size_t)requested;
    int complement = 0;

    if (select_count > (live >> 1u)) {
        complement = 1;
        select_count = live - select_count;
    }

    unsigned char *selected = (unsigned char *)calloc(live, 1u);
    if (selected == 0) return jinx_oracle_zero_value();

    size_t remaining = select_count;
    uint32_t failures = 0u;

    while (remaining != 0u) {
        int range_ok = 0;
        uint32_t position = jinx_oracle_mt_range32((uint32_t)(live - 1u), &range_ok);
        if (!range_ok) {
            free(selected);
            return jinx_oracle_zero_value();
        }

        if (selected[position]) {
            if (++failures > 50u) {
                free(selected);
                return jinx_oracle_zero_value();
            }
            continue;
        }

        selected[position] = 1u;
        remaining--;
        failures = 0u;
    }

    JinxZendArray *result = jinx_zend_array_new_packed((size_t)requested);
    if (result == 0) {
        free(selected);
        return jinx_oracle_zero_value();
    }

    for (size_t i = 0u; i < live; i++) {
        int include = (selected[i] != 0u) ^ complement;
        if (!include) continue;

        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0) {
            free(selected);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }

        JinxZendValue key = bucket->key != 0
            ? jinx_zend_string_value(bucket->key)
            : jinx_zend_long((int64_t)bucket->h);

        if (!jinx_zend_array_append(result, key)) {
            free(selected);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    free(selected);
    return jinx_oracle_zend_array_value_owned(result);
}


static inline int jinx_oracle_zend_add_assoc_cstring(
    JinxZendArray *array,
    const char *key,
    const char *value
) {
    if (array == 0 || key == 0 || value == 0) return 0;
    JinxZendString *string = jinx_zend_string_new(value, strlen(value));
    if (string == 0) return 0;
    int ok = jinx_zend_array_add_assoc(
        array,
        key,
        strlen(key),
        jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static inline int jinx_oracle_zend_add_index_cstring(
    JinxZendArray *array,
    size_t index,
    const char *value
) {
    if (array == 0 || value == 0) return 0;
    JinxZendString *string = jinx_zend_string_new(value, strlen(value));
    if (string == 0) return 0;
    int ok = jinx_zend_array_add_index(
        array,
        index,
        jinx_zend_string_value(string)
    );
    jinx_zend_string_release(string);
    return ok;
}

static inline void jinx_oracle_calendar_from_jd_fields(
    int64_t jd,
    int cal,
    int *year,
    int *month,
    int *day
) {
    *year = 0;
    *month = 0;
    *day = 0;

    if (cal == 0) {
        jinx_oracle_sdn_to_gregorian(jd, year, month, day);
    } else if (cal == 1) {
        jinx_oracle_sdn_to_julian(jd, year, month, day);
    } else if (cal == 2) {
        jinx_oracle_sdn_to_jewish(jd, year, month, day);
    } else if (cal == 3) {
        jinx_oracle_sdn_to_french(jd, year, month, day);
    }
}

static inline const char *jinx_oracle_calendar_month_short(
    int cal,
    int year,
    int month
) {
    if (cal == 2) return jinx_oracle_jewish_month_name(year, month);
    if (cal == 3) {
        return month >= 0 && month <= 13
            ? jinx_oracle_french_month_name[month]
            : "";
    }
    return month >= 0 && month <= 12
        ? jinx_oracle_month_name_short[month]
        : "";
}

static inline const char *jinx_oracle_calendar_month_long(
    int cal,
    int year,
    int month
) {
    if (cal == 2) return jinx_oracle_jewish_month_name(year, month);
    if (cal == 3) {
        return month >= 0 && month <= 13
            ? jinx_oracle_french_month_name[month]
            : "";
    }
    return month >= 0 && month <= 12
        ? jinx_oracle_month_name_long[month]
        : "";
}

static inline JinxValue jinx_oracle_zend_cal_from_jd_special(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 2u) return jinx_oracle_zero_value();

    int64_t jd = jinx_oracle_intish(args[0]);
    int64_t cal64 = jinx_oracle_intish(args[1]);
    if (cal64 < 0 || cal64 > 3) return jinx_oracle_zero_value();
    int cal = (int)cal64;

    int year = 0;
    int month = 0;
    int day = 0;
    jinx_oracle_calendar_from_jd_fields(jd, cal, &year, &month, &day);

    JinxZendArray *result = jinx_zend_array_new_packed(10u);
    if (result == 0) return jinx_oracle_zero_value();

    char date[64];
    snprintf(date, sizeof(date), "%d/%d/%d", month, day, year);

    if (!jinx_oracle_zend_add_assoc_cstring(result, "date", date) ||
        !jinx_zend_array_add_assoc(result, "month", 5u, jinx_zend_long(month)) ||
        !jinx_zend_array_add_assoc(result, "day", 3u, jinx_zend_long(day)) ||
        !jinx_zend_array_add_assoc(result, "year", 4u, jinx_zend_long(year))) {
        jinx_zend_array_release(result);
        return jinx_oracle_zero_value();
    }

    if (cal == 2 && year <= 0) {
        if (!jinx_zend_array_add_assoc(result, "dow", 3u, jinx_zend_null()) ||
            !jinx_oracle_zend_add_assoc_cstring(result, "abbrevdayname", "") ||
            !jinx_oracle_zend_add_assoc_cstring(result, "dayname", "")) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    } else {
        int dow = jinx_oracle_day_of_week(jd);
        if (!jinx_zend_array_add_assoc(result, "dow", 3u, jinx_zend_long(dow)) ||
            !jinx_oracle_zend_add_assoc_cstring(
                result,
                "abbrevdayname",
                jinx_oracle_day_name_short[dow]
            ) ||
            !jinx_oracle_zend_add_assoc_cstring(
                result,
                "dayname",
                jinx_oracle_day_name_long[dow]
            )) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    if (!jinx_oracle_zend_add_assoc_cstring(
            result,
            "abbrevmonth",
            jinx_oracle_calendar_month_short(cal, year, month)
        ) ||
        !jinx_oracle_zend_add_assoc_cstring(
            result,
            "monthname",
            jinx_oracle_calendar_month_long(cal, year, month)
        )) {
        jinx_zend_array_release(result);
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxZendArray *jinx_oracle_zend_cal_info_one(int cal) {
    static const char * const cal_names[4] = {
        "Gregorian", "Julian", "Jewish", "French"
    };
    static const char * const cal_symbols[4] = {
        "CAL_GREGORIAN", "CAL_JULIAN", "CAL_JEWISH", "CAL_FRENCH"
    };
    static const int month_counts[4] = {12, 12, 13, 13};
    static const int max_days[4] = {31, 31, 30, 30};

    if (cal < 0 || cal > 3) return 0;

    JinxZendArray *result = jinx_zend_array_new_packed(6u);
    JinxZendArray *months = jinx_zend_array_new_packed((size_t)month_counts[cal] + 1u);
    JinxZendArray *short_months = jinx_zend_array_new_packed((size_t)month_counts[cal] + 1u);

    if (result == 0 || months == 0 || short_months == 0) {
        jinx_zend_array_release(result);
        jinx_zend_array_release(months);
        jinx_zend_array_release(short_months);
        return 0;
    }

    for (int month = 1; month <= month_counts[cal]; month++) {
        const char *long_name;
        const char *short_name;

        if (cal == 0 || cal == 1) {
            long_name = jinx_oracle_month_name_long[month];
            short_name = jinx_oracle_month_name_short[month];
        } else if (cal == 2) {
            long_name = jinx_oracle_jewish_month_name_leap[month];
            short_name = jinx_oracle_jewish_month_name_leap[month];
        } else {
            long_name = jinx_oracle_french_month_name[month];
            short_name = jinx_oracle_french_month_name[month];
        }

        if (!jinx_oracle_zend_add_index_cstring(months, (size_t)month, long_name) ||
            !jinx_oracle_zend_add_index_cstring(short_months, (size_t)month, short_name)) {
            jinx_zend_array_release(result);
            jinx_zend_array_release(months);
            jinx_zend_array_release(short_months);
            return 0;
        }
    }

    if (!jinx_zend_array_add_assoc(
            result,
            "months",
            6u,
            jinx_zend_array_value(months)
        ) ||
        !jinx_zend_array_add_assoc(
            result,
            "abbrevmonths",
            12u,
            jinx_zend_array_value(short_months)
        ) ||
        !jinx_zend_array_add_assoc(
            result,
            "maxdaysinmonth",
            14u,
            jinx_zend_long(max_days[cal])
        ) ||
        !jinx_oracle_zend_add_assoc_cstring(result, "calname", cal_names[cal]) ||
        !jinx_oracle_zend_add_assoc_cstring(result, "calsymbol", cal_symbols[cal])) {
        jinx_zend_array_release(result);
        jinx_zend_array_release(months);
        jinx_zend_array_release(short_months);
        return 0;
    }

    jinx_zend_array_release(months);
    jinx_zend_array_release(short_months);
    return result;
}

static inline JinxValue jinx_oracle_zend_cal_info_special(
    JinxValue *args,
    size_t argc
) {
    int64_t cal64 = argc >= 1u ? jinx_oracle_intish(args[0]) : -1;

    if (cal64 == -1) {
        JinxZendArray *all = jinx_zend_array_new_packed(4u);
        if (all == 0) return jinx_oracle_zero_value();

        for (int cal = 0; cal < 4; cal++) {
            JinxZendArray *info = jinx_oracle_zend_cal_info_one(cal);
            if (info == 0 ||
                !jinx_zend_array_add_index(all, (size_t)cal, jinx_zend_array_value(info))) {
                jinx_zend_array_release(info);
                jinx_zend_array_release(all);
                return jinx_oracle_zero_value();
            }
            jinx_zend_array_release(info);
        }

        return jinx_oracle_zend_array_value_owned(all);
    }

    if (cal64 < 0 || cal64 > 3) return jinx_oracle_zero_value();

    return jinx_oracle_zend_array_value_owned(
        jinx_oracle_zend_cal_info_one((int)cal64)
    );
}


static inline size_t jinx_oracle_zend_cursor_valid_pos(
    const JinxZendArray *array,
    size_t position
) {
    if (array == 0 || array->buckets == 0 || position >= array->count) {
        return array != 0 ? array->count : 0u;
    }

    for (size_t i = position; i < array->count; i++) {
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[i])) {
            return i;
        }
    }

    return array->count;
}

static inline const JinxZendBucket *jinx_oracle_zend_cursor_bucket(
    const JinxZendArray *array
) {
    if (array == 0) return 0;

    size_t position = jinx_oracle_zend_cursor_valid_pos(
        array,
        array->internal_pointer
    );
    return position < array->count ? &array->buckets[position] : 0;
}

static inline JinxValue jinx_oracle_zend_cursor_current(
    JinxZendArray *array
) {
    const JinxZendBucket *bucket = jinx_oracle_zend_cursor_bucket(array);
    return bucket != 0
        ? jinx_oracle_zend_value_return_copy(bucket->value)
        : jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_zend_cursor_key(
    JinxZendArray *array
) {
    const JinxZendBucket *bucket = jinx_oracle_zend_cursor_bucket(array);
    return bucket != 0
        ? jinx_oracle_zend_bucket_key_value(bucket)
        : jinx_oracle_zero_value();
}

static inline JinxValue jinx_oracle_zend_cursor_reset(
    JinxZendArray *array
) {
    if (array == 0 || jinx_zend_array_live_count(array) == 0u) {
        if (array != 0) array->internal_pointer = 0u;
        return jinx_oracle_bool_value(0);
    }

    array->internal_pointer = jinx_oracle_zend_cursor_valid_pos(array, 0u);
    return jinx_oracle_zend_cursor_current(array);
}

static inline JinxValue jinx_oracle_zend_cursor_end(
    JinxZendArray *array
) {
    if (array == 0 || jinx_zend_array_live_count(array) == 0u) {
        if (array != 0) array->internal_pointer = 0u;
        return jinx_oracle_bool_value(0);
    }

    for (size_t i = array->count; i > 0u; i--) {
        size_t position = i - 1u;
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[position])) {
            array->internal_pointer = position;
            return jinx_oracle_zend_cursor_current(array);
        }
    }

    array->internal_pointer = array->count;
    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_zend_cursor_next(
    JinxZendArray *array
) {
    if (array == 0 || jinx_zend_array_live_count(array) == 0u) {
        return jinx_oracle_bool_value(0);
    }

    size_t position = jinx_oracle_zend_cursor_valid_pos(
        array,
        array->internal_pointer
    );

    if (position >= array->count) {
        return jinx_oracle_bool_value(0);
    }

    for (size_t i = position + 1u; i < array->count; i++) {
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[i])) {
            array->internal_pointer = i;
            return jinx_oracle_zend_cursor_current(array);
        }
    }

    array->internal_pointer = array->count;
    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_zend_cursor_prev(
    JinxZendArray *array
) {
    if (array == 0 || jinx_zend_array_live_count(array) == 0u) {
        return jinx_oracle_bool_value(0);
    }

    size_t position = array->internal_pointer;
    if (position >= array->count) {
        return jinx_oracle_bool_value(0);
    }

    while (position > 0u) {
        position--;
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[position])) {
            array->internal_pointer = position;
            return jinx_oracle_zend_cursor_current(array);
        }
    }

    array->internal_pointer = array->count;
    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_zend_array_pointer_special(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    if (name == 0 || args == 0 || argc < 1u) {
        return jinx_oracle_zero_value();
    }

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    if (strcmp(name, "current") == 0 || strcmp(name, "pos") == 0) {
        return jinx_oracle_zend_cursor_current(array);
    }
    if (strcmp(name, "key") == 0) {
        return jinx_oracle_zend_cursor_key(array);
    }
    if (strcmp(name, "next") == 0) {
        return jinx_oracle_zend_cursor_next(array);
    }
    if (strcmp(name, "prev") == 0) {
        return jinx_oracle_zend_cursor_prev(array);
    }
    if (strcmp(name, "reset") == 0) {
        return jinx_oracle_zend_cursor_reset(array);
    }
    if (strcmp(name, "end") == 0) {
        return jinx_oracle_zend_cursor_end(array);
    }

    return jinx_oracle_zero_value();
}


static inline JinxValue jinx_oracle_zend_shuffle_special(
    JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 1u) return jinx_oracle_zero_value();

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    size_t live = jinx_zend_array_live_count(array);
    if (live < 1u) {
        array->internal_pointer = 0u;
        return jinx_oracle_bool_value(1);
    }

    JinxZendArray *result = jinx_zend_array_new_packed(live);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < live; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_live_iter_at(array, i);
        if (bucket == 0 || !jinx_zend_array_append(result, bucket->value)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    size_t n_left = live;
    while (--n_left) {
        int ok = 0;
        int64_t random_index = jinx_oracle_mt_range_i64(
            0,
            (int64_t)n_left,
            &ok
        );
        if (!ok || random_index < 0 || (uint64_t)random_index > n_left) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }

        size_t rnd = (size_t)random_index;
        if (rnd != n_left) {
            JinxZendValue temp = result->buckets[n_left].value;
            result->buckets[n_left].value = result->buckets[rnd].value;
            result->buckets[rnd].value = temp;
        }
    }

    result->internal_pointer = 0u;

    if (!jinx_oracle_replace_array_contents(array, result)) {
        return jinx_oracle_zero_value();
    }

    return jinx_oracle_bool_value(1);
}


typedef struct JinxOracleJsonDecoder {
    const unsigned char *bytes;
    uint32_t len;
    uint32_t pos;
    int max_depth;
    int associative;
    int bigint_as_string;
    int error;
} JinxOracleJsonDecoder;

static inline void jinx_oracle_json_decode_skip_ws(JinxOracleJsonDecoder *p) {
    while (p->pos < p->len) {
        unsigned char c = p->bytes[p->pos];
        if (c != ' ' && c != '\t' && c != '\n' && c != '\r') break;
        p->pos++;
    }
}

static inline int jinx_oracle_json_append_utf8(char *out, uint32_t *used, uint32_t codepoint) {
    if (codepoint <= 0x7Fu) {
        out[(*used)++] = (char)codepoint;
        return 1;
    }
    if (codepoint <= 0x7FFu) {
        out[(*used)++] = (char)(0xC0u | (codepoint >> 6u));
        out[(*used)++] = (char)(0x80u | (codepoint & 0x3Fu));
        return 1;
    }
    if (codepoint <= 0xFFFFu) {
        if (codepoint >= 0xD800u && codepoint <= 0xDFFFu) return 0;
        out[(*used)++] = (char)(0xE0u | (codepoint >> 12u));
        out[(*used)++] = (char)(0x80u | ((codepoint >> 6u) & 0x3Fu));
        out[(*used)++] = (char)(0x80u | (codepoint & 0x3Fu));
        return 1;
    }
    if (codepoint <= 0x10FFFFu) {
        out[(*used)++] = (char)(0xF0u | (codepoint >> 18u));
        out[(*used)++] = (char)(0x80u | ((codepoint >> 12u) & 0x3Fu));
        out[(*used)++] = (char)(0x80u | ((codepoint >> 6u) & 0x3Fu));
        out[(*used)++] = (char)(0x80u | (codepoint & 0x3Fu));
        return 1;
    }
    return 0;
}

static inline JinxZendString *jinx_oracle_json_decode_string(JinxOracleJsonDecoder *p) {
    if (p->pos >= p->len || p->bytes[p->pos] != '"') return 0;
    p->pos++;

    uint32_t capacity = p->len - p->pos + 1u;
    char *buffer = (char *)malloc(capacity);
    if (buffer == 0) {
        p->error = JINX_JSON_ERROR_SYNTAX;
        return 0;
    }

    uint32_t used = 0u;

    while (p->pos < p->len) {
        unsigned char ch = p->bytes[p->pos++];

        if (ch == '"') {
            JinxZendString *result = jinx_zend_string_new(buffer, used);
            free(buffer);
            return result;
        }

        if (ch < 0x20u) {
            free(buffer);
            p->error = JINX_JSON_ERROR_CTRL_CHAR;
            return 0;
        }

        if (ch == '\\') {
            if (p->pos >= p->len) {
                free(buffer);
                p->error = JINX_JSON_ERROR_SYNTAX;
                return 0;
            }

            unsigned char esc = p->bytes[p->pos++];
            switch (esc) {
                case '"': buffer[used++] = '"'; continue;
                case '\\': buffer[used++] = '\\'; continue;
                case '/': buffer[used++] = '/'; continue;
                case 'b': buffer[used++] = '\b'; continue;
                case 'f': buffer[used++] = '\f'; continue;
                case 'n': buffer[used++] = '\n'; continue;
                case 'r': buffer[used++] = '\r'; continue;
                case 't': buffer[used++] = '\t'; continue;
                case 'u': break;
                default:
                    free(buffer);
                    p->error = JINX_JSON_ERROR_SYNTAX;
                    return 0;
            }

            uint32_t code = 0u;
            if (!jinx_oracle_json_hex4(p->bytes, p->len, p->pos, &code)) {
                free(buffer);
                p->error = JINX_JSON_ERROR_SYNTAX;
                return 0;
            }
            p->pos += 4u;

            if (code >= 0xD800u && code <= 0xDBFFu) {
                if (p->pos + 6u > p->len ||
                    p->bytes[p->pos] != '\\' ||
                    p->bytes[p->pos + 1u] != 'u') {
                    free(buffer);
                    p->error = JINX_JSON_ERROR_UTF16;
                    return 0;
                }

                uint32_t low = 0u;
                if (!jinx_oracle_json_hex4(p->bytes, p->len, p->pos + 2u, &low) ||
                    low < 0xDC00u || low > 0xDFFFu) {
                    free(buffer);
                    p->error = JINX_JSON_ERROR_UTF16;
                    return 0;
                }
                p->pos += 6u;
                code = 0x10000u + (((code - 0xD800u) << 10u) | (low - 0xDC00u));
            } else if (code >= 0xDC00u && code <= 0xDFFFu) {
                free(buffer);
                p->error = JINX_JSON_ERROR_UTF16;
                return 0;
            }

            if (!jinx_oracle_json_append_utf8(buffer, &used, code)) {
                free(buffer);
                p->error = JINX_JSON_ERROR_UTF16;
                return 0;
            }
            continue;
        }

        if (ch >= 0x80u) {
            uint32_t start = p->pos - 1u;
            uint32_t seq = jinx_oracle_json_utf8_sequence_len(p->bytes, p->len, start);
            if (seq == 0u) {
                free(buffer);
                p->error = JINX_JSON_ERROR_UTF8;
                return 0;
            }
            memcpy(buffer + used, p->bytes + start, seq);
            used += seq;
            p->pos = start + seq;
            continue;
        }

        buffer[used++] = (char)ch;
    }

    free(buffer);
    p->error = JINX_JSON_ERROR_SYNTAX;
    return 0;
}

static inline int jinx_oracle_json_decode_value(
    JinxOracleJsonDecoder *p,
    int depth,
    JinxZendValue *out
);

static inline int jinx_oracle_json_decode_number(
    JinxOracleJsonDecoder *p,
    JinxZendValue *out
) {
    uint32_t start = p->pos;
    JinxOracleJsonValidator validator;
    validator.bytes = p->bytes;
    validator.len = p->len;
    validator.pos = p->pos;
    validator.max_depth = p->max_depth;
    validator.ignore_invalid_utf8 = 0;
    validator.error = JINX_JSON_ERROR_NONE;

    if (!jinx_oracle_json_parse_number(&validator)) {
        p->error = validator.error;
        return 0;
    }

    p->pos = validator.pos;
    uint32_t n = p->pos - start;
    char *text = (char *)malloc((size_t)n + 1u);
    if (text == 0) return 0;
    memcpy(text, p->bytes + start, n);
    text[n] = '\0';

    int integral = 1;
    for (uint32_t i = 0u; i < n; i++) {
        if (text[i] == '.' || text[i] == 'e' || text[i] == 'E') {
            integral = 0;
            break;
        }
    }

    if (integral) {
        errno = 0;
        char *end = 0;
        long long parsed = strtoll(text, &end, 10);
        if (errno != ERANGE && end == text + n) {
            *out = jinx_zend_long((int64_t)parsed);
            free(text);
            return 1;
        }

        if (p->bigint_as_string) {
            JinxZendString *string = jinx_zend_string_new(text, n);
            free(text);
            if (string == 0) return 0;
            *out = jinx_zend_string_value(string);
            return 1;
        }
    }

    errno = 0;
    char *end = 0;
    double parsed = strtod(text, &end);
    free(text);
    if (end == 0 || errno == ERANGE) {
        p->error = JINX_JSON_ERROR_SYNTAX;
        return 0;
    }

    *out = jinx_zend_double(parsed);
    return 1;
}

static inline int jinx_oracle_json_decode_array(
    JinxOracleJsonDecoder *p,
    int depth,
    JinxZendValue *out
) {
    if (depth >= p->max_depth) {
        p->error = JINX_JSON_ERROR_DEPTH;
        return 0;
    }

    p->pos++;
    jinx_oracle_json_decode_skip_ws(p);

    JinxZendArray *array = jinx_zend_array_new_packed(4u);
    if (array == 0) return 0;

    if (p->pos < p->len && p->bytes[p->pos] == ']') {
        p->pos++;
        *out = jinx_zend_array_value(array);
        return 1;
    }

    for (;;) {
        JinxZendValue value;
        if (!jinx_oracle_json_decode_value(p, depth + 1, &value)) {
            jinx_zend_array_release(array);
            return 0;
        }

        int added = jinx_zend_array_append(array, value);
        jinx_zend_value_release(value);
        if (!added) {
            jinx_zend_array_release(array);
            return 0;
        }

        jinx_oracle_json_decode_skip_ws(p);
        if (p->pos < p->len && p->bytes[p->pos] == ']') {
            p->pos++;
            *out = jinx_zend_array_value(array);
            return 1;
        }
        if (p->pos >= p->len || p->bytes[p->pos] != ',') {
            jinx_zend_array_release(array);
            p->error = JINX_JSON_ERROR_SYNTAX;
            return 0;
        }

        p->pos++;
        jinx_oracle_json_decode_skip_ws(p);
    }
}

static inline int jinx_oracle_json_decode_object(
    JinxOracleJsonDecoder *p,
    int depth,
    JinxZendValue *out
) {
    if (!p->associative) {
        p->error = JINX_JSON_ERROR_UNSUPPORTED_TYPE;
        return 0;
    }
    if (depth >= p->max_depth) {
        p->error = JINX_JSON_ERROR_DEPTH;
        return 0;
    }

    p->pos++;
    jinx_oracle_json_decode_skip_ws(p);

    JinxZendArray *array = jinx_zend_array_new_packed(4u);
    if (array == 0) return 0;

    if (p->pos < p->len && p->bytes[p->pos] == '}') {
        p->pos++;
        *out = jinx_zend_array_value(array);
        return 1;
    }

    for (;;) {
        JinxZendString *key = jinx_oracle_json_decode_string(p);
        if (key == 0) {
            jinx_zend_array_release(array);
            return 0;
        }

        jinx_oracle_json_decode_skip_ws(p);
        if (p->pos >= p->len || p->bytes[p->pos] != ':') {
            jinx_zend_string_release(key);
            jinx_zend_array_release(array);
            p->error = JINX_JSON_ERROR_SYNTAX;
            return 0;
        }

        p->pos++;
        jinx_oracle_json_decode_skip_ws(p);

        JinxZendValue value;
        if (!jinx_oracle_json_decode_value(p, depth + 1, &value)) {
            jinx_zend_string_release(key);
            jinx_zend_array_release(array);
            return 0;
        }

        int added = jinx_zend_array_add_symtable(
            array,
            key->bytes,
            key->len,
            value
        );
        jinx_zend_value_release(value);
        jinx_zend_string_release(key);

        if (!added) {
            jinx_zend_array_release(array);
            return 0;
        }

        jinx_oracle_json_decode_skip_ws(p);
        if (p->pos < p->len && p->bytes[p->pos] == '}') {
            p->pos++;
            *out = jinx_zend_array_value(array);
            return 1;
        }
        if (p->pos >= p->len || p->bytes[p->pos] != ',') {
            jinx_zend_array_release(array);
            p->error = JINX_JSON_ERROR_SYNTAX;
            return 0;
        }

        p->pos++;
        jinx_oracle_json_decode_skip_ws(p);
    }
}

static inline int jinx_oracle_json_decode_value(
    JinxOracleJsonDecoder *p,
    int depth,
    JinxZendValue *out
) {
    jinx_oracle_json_decode_skip_ws(p);
    if (p->pos >= p->len) {
        p->error = JINX_JSON_ERROR_SYNTAX;
        return 0;
    }

    unsigned char ch = p->bytes[p->pos];

    if (ch == '"') {
        JinxZendString *string = jinx_oracle_json_decode_string(p);
        if (string == 0) return 0;
        *out = jinx_zend_string_value(string);
        return 1;
    }
    if (ch == '[') return jinx_oracle_json_decode_array(p, depth, out);
    if (ch == '{') return jinx_oracle_json_decode_object(p, depth, out);

    if (ch == 't' && p->pos + 4u <= p->len &&
        memcmp(p->bytes + p->pos, "true", 4u) == 0) {
        p->pos += 4u;
        *out = jinx_zend_bool(1);
        return 1;
    }
    if (ch == 'f' && p->pos + 5u <= p->len &&
        memcmp(p->bytes + p->pos, "false", 5u) == 0) {
        p->pos += 5u;
        *out = jinx_zend_bool(0);
        return 1;
    }
    if (ch == 'n' && p->pos + 4u <= p->len &&
        memcmp(p->bytes + p->pos, "null", 4u) == 0) {
        p->pos += 4u;
        *out = jinx_zend_null();
        return 1;
    }
    if (ch == '-' || (ch >= '0' && ch <= '9')) {
        return jinx_oracle_json_decode_number(p, out);
    }

    p->error = JINX_JSON_ERROR_SYNTAX;
    return 0;
}

static inline JinxValue jinx_oracle_zend_json_decode_special(
    const JinxValue *args,
    size_t argc
) {
    if (args == 0 || argc < 1u || args[0].type != 3u) {
        return jinx_oracle_zero_value();
    }

    int associative = 0;
    if (argc >= 2u && args[1].type != 0u) {
        associative = jinx_oracle_boolish(args[1]);
    }

    int64_t depth = argc >= 3u ? jinx_oracle_intish(args[2]) : 512;
    int64_t options = argc >= 4u ? jinx_oracle_intish(args[3]) : 0;

    if (depth <= 0 || depth > INT_MAX) return jinx_oracle_zero_value();
    if ((options & ~3LL) != 0) return jinx_oracle_zero_value();
    if ((options & 1LL) != 0) associative = 1;

    JinxOracleJsonDecoder parser;
    parser.bytes = jinx_oracle_string_bytes(args[0]);
    parser.len = jinx_oracle_string_len(args[0]);
    parser.pos = 0u;
    parser.max_depth = (int)depth;
    parser.associative = associative;
    parser.bigint_as_string = (options & 2LL) != 0;
    parser.error = JINX_JSON_ERROR_NONE;

    if (parser.len == 0u) {
        jinx_oracle_json_error_code = JINX_JSON_ERROR_SYNTAX;
        return jinx_oracle_zero_value();
    }

    JinxZendValue decoded;
    if (!jinx_oracle_json_decode_value(&parser, 0, &decoded)) {
        jinx_oracle_json_error_code = parser.error == JINX_JSON_ERROR_NONE
            ? JINX_JSON_ERROR_SYNTAX
            : parser.error;
        return jinx_oracle_zero_value();
    }

    jinx_oracle_json_decode_skip_ws(&parser);
    if (parser.pos != parser.len) {
        jinx_zend_value_release(decoded);
        jinx_oracle_json_error_code = JINX_JSON_ERROR_SYNTAX;
        return jinx_oracle_zero_value();
    }

    jinx_oracle_json_error_code = JINX_JSON_ERROR_NONE;
    JinxValue result = jinx_oracle_zend_value_return_copy(decoded);
    jinx_zend_value_release(decoded);
    return result;
}

static inline JinxValue jinx_oracle_zend_array_dispatch_builtin(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    if (name == 0) return jinx_oracle_zero_value();

    if (strcmp(name, "localeconv") == 0) {
        return jinx_oracle_zend_localeconv_special();
    }

    if (strcmp(name, "cal_info") == 0) {
        return jinx_oracle_zend_cal_info_special(args, argc);
    }

    if (args == 0 || argc == 0u) return jinx_oracle_zero_value();

    if (strcmp(name, "array_key_exists") == 0 || strcmp(name, "key_exists") == 0) {
        if (argc < 2u) return jinx_oracle_zero_value();
        JinxZendArray *key_array = jinx_oracle_zend_array_ptr(args[1]);
        if (key_array == 0) return jinx_oracle_zero_value();

        JinxValue key = args[0];
        if (key.type == 3u) {
            return jinx_oracle_bool_value(jinx_zend_array_live_key_exists_string(
                key_array,
                (const char *)key.as.ptr,
                (size_t)key.flags
            ));
        }

        return jinx_oracle_bool_value(jinx_zend_array_live_key_exists_index(
            key_array,
            (size_t)jinx_oracle_intish(key)
        ));
    }

    if (strcmp(name, "cal_from_jd") == 0) {
        return jinx_oracle_zend_cal_from_jd_special(args, argc);
    }

    if (strcmp(name, "array_rand") == 0) {
        return jinx_oracle_zend_array_rand_special(args, argc);
    }

    if (strcmp(name, "array_walk") == 0 ||
        strcmp(name, "array_walk_recursive") == 0) {
        return jinx_oracle_zend_array_walk_named(name, args, argc);
    }

    if (strcmp(name, "array_diff_uassoc") == 0 ||
        strcmp(name, "array_diff_ukey") == 0 ||
        strcmp(name, "array_udiff") == 0 ||
        strcmp(name, "array_udiff_assoc") == 0 ||
        strcmp(name, "array_udiff_uassoc") == 0 ||
        strcmp(name, "array_intersect_uassoc") == 0 ||
        strcmp(name, "array_intersect_ukey") == 0 ||
        strcmp(name, "array_uintersect") == 0 ||
        strcmp(name, "array_uintersect_assoc") == 0 ||
        strcmp(name, "array_uintersect_uassoc") == 0) {
        return jinx_oracle_zend_user_diff_intersect_special(name, args, argc);
    }

    if (strcmp(name, "array_map") == 0) {
        return jinx_oracle_zend_array_map_named(args, argc);
    }
    if (strcmp(name, "array_reduce") == 0) {
        return jinx_oracle_zend_array_reduce_named(args, argc);
    }
    if (strcmp(name, "array_filter") == 0) {
        return jinx_oracle_zend_array_filter_named(args, argc);
    }
    if (strcmp(name, "array_find") == 0 || strcmp(name, "array_find_key") == 0 ||
        strcmp(name, "array_any") == 0 || strcmp(name, "array_all") == 0) {
        return jinx_oracle_zend_array_predicate_named(name, args, argc);
    }

    if (strcmp(name, "array_multisort") == 0) {
        return jinx_oracle_zend_array_multisort_special(args, argc);
    }

    if (strcmp(name, "shuffle") == 0) {
        return jinx_oracle_zend_shuffle_special(args, argc);
    }

    if (strcmp(name, "usort") == 0 || strcmp(name, "uasort") == 0 ||
        strcmp(name, "uksort") == 0) {
        return jinx_oracle_zend_user_sort_special(name, args, argc);
    }

    if (strcmp(name, "sort") == 0 || strcmp(name, "rsort") == 0 ||
        strcmp(name, "asort") == 0 || strcmp(name, "arsort") == 0 ||
        strcmp(name, "ksort") == 0 || strcmp(name, "krsort") == 0 ||
        strcmp(name, "natsort") == 0 || strcmp(name, "natcasesort") == 0) {
        return jinx_oracle_zend_sort_special(name, args, argc);
    }

    if (strcmp(name, "pathinfo") == 0) {
        return jinx_oracle_zend_pathinfo_special(args, argc);
    }

    if (strcmp(name, "parse_str") == 0) {
        return jinx_oracle_zend_parse_str_special(args, argc);
    }

    if (strcmp(name, "http_build_query") == 0) {
        return jinx_oracle_zend_http_build_query_special(args, argc);
    }

    if (strcmp(name, "parse_url") == 0) {
        return jinx_oracle_zend_parse_url_special(args, argc);
    }

    if (strcmp(name, "str_getcsv") == 0) {
        return jinx_oracle_zend_str_getcsv_special(args, argc);
    }
    if (strcmp(name, "strip_tags") == 0) {
        return jinx_oracle_zend_strip_tags_special(args, argc);
    }

    if (strcmp(name, "in_array") == 0 || strcmp(name, "array_search") == 0) {
        return jinx_oracle_zend_in_array_search_special(name, args, argc);
    }

    if (strcmp(name, "count_chars") == 0) return jinx_oracle_zend_count_chars_special(args, argc);
    if (strcmp(name, "str_word_count") == 0) return jinx_oracle_zend_str_word_count_special(args, argc);
    if (strcmp(name, "explode") == 0) return jinx_oracle_zend_explode_special(args, argc);
    if (strcmp(name, "str_split") == 0) return jinx_oracle_zend_str_split_special(args, argc);
    if (strcmp(name, "vsprintf") == 0) return jinx_oracle_zend_vsprintf_special(args, argc);
    if (strcmp(name, "vprintf") == 0) return jinx_oracle_zend_vprintf_special(args, argc);
    if (strcmp(name, "implode") == 0 || strcmp(name, "join") == 0) return jinx_oracle_zend_implode_special(args, argc);
    if (strcmp(name, "range") == 0) return jinx_oracle_zend_range_special(args, argc);
    if (strcmp(name, "array_fill") == 0) return jinx_oracle_zend_array_fill_special(args, argc);

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    if (strcmp(name, "current") == 0 || strcmp(name, "pos") == 0 ||
        strcmp(name, "key") == 0 || strcmp(name, "next") == 0 ||
        strcmp(name, "prev") == 0 || strcmp(name, "reset") == 0 ||
        strcmp(name, "end") == 0) {
        return jinx_oracle_zend_array_pointer_special(name, args, argc);
    }

    if (strcmp(name, "min") == 0 || strcmp(name, "max") == 0) {
        return jinx_oracle_zend_numeric_extreme_array(
            array,
            strcmp(name, "max") == 0
        );
    }

    if (strcmp(name, "count") == 0 || strcmp(name, "sizeof") == 0) {
        return jinx_oracle_zend_count_value(args, argc);
    }

    if (strcmp(name, "array_is_list") == 0) {
        return jinx_oracle_bool_value(jinx_zend_array_live_is_list(array));
    }

    if (strcmp(name, "array_key_first") == 0 || strcmp(name, "array_key_last") == 0) {
        size_t live = jinx_zend_array_live_count(array);
        if (live == 0u) return jinx_oracle_zero_value();
        return jinx_oracle_zend_bucket_key_value(jinx_zend_array_live_iter_at(
            array, strcmp(name, "array_key_first") == 0 ? 0u : live - 1u
        ));
    }

    if (strcmp(name, "array_sum") == 0) return jinx_oracle_zend_array_sum_product(array, 0);
    if (strcmp(name, "array_product") == 0) return jinx_oracle_zend_array_sum_product(array, 1);

    if (strcmp(name, "array_values") == 0) {
        return jinx_oracle_zend_array_value_owned(jinx_zend_array_live_values(array));
    }
    if (strcmp(name, "array_keys") == 0) {
        return jinx_oracle_zend_array_keys_value(args, argc);
    }
    if (strcmp(name, "array_reverse") == 0) {
        int preserve = argc >= 2u && jinx_oracle_boolish(args[1]);
        return jinx_oracle_zend_array_value_owned(jinx_oracle_zend_array_reverse_core(array, preserve));
    }
    if (strcmp(name, "array_slice") == 0) {
        int64_t offset = argc >= 2u ? jinx_oracle_intish(args[1]) : 0;
        int has_length = argc >= 3u && args[2].type != 0u;
        int64_t length = has_length ? jinx_oracle_intish(args[2]) : 0;
        int preserve = argc >= 4u && jinx_oracle_boolish(args[3]);
        return jinx_oracle_zend_array_value_owned(
            jinx_oracle_zend_array_slice_core(array, offset, has_length, length, preserve)
        );
    }
    if (strcmp(name, "array_merge") == 0) {
        return jinx_oracle_zend_array_value_owned(jinx_oracle_zend_array_merge_core(args, argc, 0));
    }
    if (strcmp(name, "array_merge_recursive") == 0) {
        return jinx_oracle_zend_array_merge_recursive_special(args, argc);
    }
    if (strcmp(name, "array_replace") == 0) {
        return jinx_oracle_zend_array_value_owned(jinx_oracle_zend_array_merge_core(args, argc, 1));
    }
    if (strcmp(name, "array_replace_recursive") == 0) {
        return jinx_oracle_zend_array_replace_recursive_special(args, argc);
    }
    if (strcmp(name, "array_flip") == 0) {
        return jinx_oracle_zend_array_value_owned(jinx_oracle_zend_array_flip_core(array));
    }
    if (strcmp(name, "array_change_key_case") == 0) {
        int upper = argc >= 2u && jinx_oracle_intish(args[1]) == 1;
        return jinx_oracle_zend_array_value_owned(jinx_oracle_zend_array_change_key_case_core(array, upper));
    }
    if (strcmp(name, "array_column") == 0) return jinx_oracle_zend_array_column_special(args, argc);
    if (strcmp(name, "array_fill_keys") == 0) return jinx_oracle_zend_array_fill_keys_special(args, argc);
    if (strcmp(name, "array_combine") == 0) return jinx_oracle_zend_array_combine_special(args, argc);
    if (strcmp(name, "array_count_values") == 0) return jinx_oracle_zend_array_count_values_special(args, argc);
    if (strcmp(name, "array_chunk") == 0) return jinx_oracle_zend_array_chunk_special(args, argc);
    if (strcmp(name, "array_pad") == 0) return jinx_oracle_zend_array_pad_special(args, argc);
    if (strcmp(name, "array_unique") == 0) return jinx_oracle_zend_array_unique_default(args, argc);
    if (strcmp(name, "array_push") == 0) return jinx_oracle_zend_array_push_special(args, argc);
    if (strcmp(name, "array_pop") == 0) return jinx_oracle_zend_array_pop_special(args, argc);
    if (strcmp(name, "array_shift") == 0) return jinx_oracle_zend_array_shift_special(args, argc);
    if (strcmp(name, "array_unshift") == 0) return jinx_oracle_zend_array_unshift_special(args, argc);
    if (strcmp(name, "array_splice") == 0) return jinx_oracle_zend_array_splice_special(args, argc);
    if (strcmp(name, "array_diff") == 0 || strcmp(name, "array_diff_assoc") == 0 ||
        strcmp(name, "array_diff_key") == 0 || strcmp(name, "array_intersect") == 0 ||
        strcmp(name, "array_intersect_assoc") == 0 || strcmp(name, "array_intersect_key") == 0) {
        return jinx_oracle_zend_array_diff_intersect_special(name, args, argc);
    }

    return jinx_oracle_zero_value();
}

#endif /* JINX_ORACLE_ZEND_ARRAY_BUILTINS_H */
