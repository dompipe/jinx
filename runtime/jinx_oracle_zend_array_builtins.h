#ifndef JINX_ORACLE_ZEND_ARRAY_BUILTINS_H
#define JINX_ORACLE_ZEND_ARRAY_BUILTINS_H

#include "jinx_oracle_zend_array_carrier.h"
#include "jinx_zend_array_delete.h"

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

    if (mode < 0 || mode > 2) return jinx_oracle_bool_value(0);

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

static inline JinxValue jinx_oracle_zend_range_special(const JinxValue *args, size_t argc) {
    if (argc < 2u) return jinx_oracle_zero_value();

    int64_t start = jinx_oracle_intish(args[0]);
    int64_t end = jinx_oracle_intish(args[1]);
    int64_t step = argc >= 3u ? jinx_oracle_intish(args[2]) : 1;
    if (step <= 0) return jinx_oracle_bool_value(0);

    uint64_t distance = start <= end
        ? (uint64_t)(end - start)
        : (uint64_t)(start - end);
    uint64_t count = distance / (uint64_t)step + 1u;
    if (count > UINT32_MAX) return jinx_oracle_bool_value(0);

    JinxZendArray *result = jinx_zend_array_new_packed((size_t)count);
    if (result == 0) return jinx_oracle_zero_value();

    if (start <= end) {
        for (int64_t value = start; value <= end;) {
            if (!jinx_zend_array_append(result, jinx_zend_long(value))) {
                jinx_zend_array_release(result); return jinx_oracle_zero_value();
            }
            if (end - value < step) break;
            value += step;
        }
    } else {
        for (int64_t value = start; value >= end;) {
            if (!jinx_zend_array_append(result, jinx_zend_long(value))) {
                jinx_zend_array_release(result); return jinx_oracle_zero_value();
            }
            if (value - end < step) break;
            value -= step;
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_fill_special(const JinxValue *args, size_t argc) {
    if (argc < 3u) return jinx_oracle_zero_value();

    int64_t start = jinx_oracle_intish(args[0]);
    int64_t count = jinx_oracle_intish(args[1]);
    if (count <= 0 || count > UINT32_MAX) return jinx_oracle_bool_value(0);

    JinxZendValue fill;
    JinxZendString *owned_string = 0;
    if (!jinx_oracle_jinx_value_to_zend(args[2], &fill, &owned_string)) return jinx_oracle_bool_value(0);

    JinxZendArray *result = jinx_zend_array_new_packed((size_t)count);
    if (result == 0) { jinx_zend_string_release(owned_string); return jinx_oracle_zero_value(); }

    for (int64_t i = 0; i < count; i++) {
        if (!jinx_zend_array_add_index(result, (size_t)(start + i), fill)) {
            jinx_zend_string_release(owned_string);
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    jinx_zend_string_release(owned_string);
    return jinx_oracle_zend_array_value_owned(result);
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
        int ok = 0;
        if (key.type == JINX_ZEND_LONG) ok = jinx_zend_array_add_index(result, (size_t)key.value.lval, fill);
        else if (key.type == JINX_ZEND_STRING && key.value.str != 0) {
            ok = jinx_zend_array_add_assoc(result, key.value.str->bytes, key.value.str->len, fill);
        }
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
    if (count != jinx_zend_array_live_count(values)) return jinx_oracle_bool_value(0);

    JinxZendArray *result = jinx_zend_array_new_packed(count + 1u);
    if (result == 0) return jinx_oracle_zero_value();

    for (size_t i = 0u; i < count; i++) {
        const JinxZendBucket *kb = jinx_zend_array_live_iter_at(keys, i);
        const JinxZendBucket *vb = jinx_zend_array_live_iter_at(values, i);
        int ok = 0;
        if (kb->value.type == JINX_ZEND_LONG) ok = jinx_zend_array_add_index(result, (size_t)kb->value.value.lval, vb->value);
        else if (kb->value.type == JINX_ZEND_STRING && kb->value.value.str != 0) {
            ok = jinx_zend_array_add_assoc(result, kb->value.value.str->bytes, kb->value.value.str->len, vb->value);
        }
        if (!ok) { jinx_zend_array_release(result); return jinx_oracle_bool_value(0); }
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
            slot = jinx_zend_array_find(result, bucket->value.value.str->bytes, bucket->value.value.str->len);
            int64_t next = slot != 0 && slot->type == JINX_ZEND_LONG ? slot->value.lval + 1 : 1;
            if (!jinx_zend_array_add_assoc(
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
        int matched = 0;

        for (size_t a = 1u; a < argc && !matched; a++) {
            JinxZendArray *other = jinx_oracle_zend_array_ptr(args[a]);
            if (other == 0) {
                jinx_zend_array_release(result);
                return jinx_oracle_zero_value();
            }
            matched = key_only
                ? jinx_oracle_zend_array_contains_key(other, bucket)
                : (assoc
                    ? jinx_oracle_zend_array_contains_assoc(other, bucket)
                    : jinx_oracle_zend_array_contains_value_text(other, bucket->value));
        }

        if ((intersect && matched) || (!intersect && !matched)) {
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
    if (key.type == 3u) return jinx_zend_array_find(row, (const char *)key.as.ptr, (size_t)key.flags);
    return 0;
}

static inline int jinx_oracle_zend_array_column_insert(
    JinxZendArray *result,
    JinxZendValue value,
    JinxZendValue *index_value
) {
    if (index_value == 0) return jinx_zend_array_append(result, value);
    if (index_value->type == JINX_ZEND_LONG) {
        return jinx_zend_array_add_index(result, (size_t)index_value->value.lval, value);
    }
    if (index_value->type == JINX_ZEND_STRING && index_value->value.str != 0) {
        return jinx_zend_array_add_assoc(
            result,
            index_value->value.str->bytes,
            index_value->value.str->len,
            value
        );
    }
    return jinx_zend_array_append(result, value);
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
        JinxZendValue *column_value = whole_row ? &row_bucket->value : jinx_oracle_zend_row_value(row, args[1]);
        if (column_value == 0) continue;

        JinxZendValue *index_value = use_index ? jinx_oracle_zend_row_value(row, args[2]) : 0;
        if (!jinx_oracle_zend_array_column_insert(result, *column_value, index_value)) {
            jinx_zend_array_release(result);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(result);
}

static inline JinxValue jinx_oracle_zend_array_dispatch_builtin(
    const char *name,
    const JinxValue *args,
    size_t argc
) {
    if (name == 0 || args == 0 || argc == 0u) return jinx_oracle_zero_value();

    if (strcmp(name, "count_chars") == 0) return jinx_oracle_zend_count_chars_special(args, argc);
    if (strcmp(name, "explode") == 0) return jinx_oracle_zend_explode_special(args, argc);
    if (strcmp(name, "str_split") == 0) return jinx_oracle_zend_str_split_special(args, argc);
    if (strcmp(name, "vsprintf") == 0) return jinx_oracle_zend_vsprintf_special(args, argc);
    if (strcmp(name, "implode") == 0 || strcmp(name, "join") == 0) return jinx_oracle_zend_implode_special(args, argc);
    if (strcmp(name, "range") == 0) return jinx_oracle_zend_range_special(args, argc);
    if (strcmp(name, "array_fill") == 0) return jinx_oracle_zend_array_fill_special(args, argc);

    JinxZendArray *array = jinx_oracle_zend_array_ptr(args[0]);
    if (array == 0) return jinx_oracle_zero_value();

    if (strcmp(name, "count") == 0) {
        return jinx_oracle_int_value((int64_t)jinx_zend_array_live_count(array));
    }

    if (strcmp(name, "array_key_exists") == 0) {
        JinxValue key = argc >= 2u ? args[1] : jinx_oracle_zero_value();
        if (key.type == 3u) {
            return jinx_oracle_bool_value(jinx_zend_array_live_key_exists_string(
                array, (const char *)key.as.ptr, (size_t)key.flags
            ));
        }
        return jinx_oracle_bool_value(jinx_zend_array_live_key_exists_index(
            array, (size_t)jinx_oracle_intish(key)
        ));
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
        return jinx_oracle_zend_array_value_owned(jinx_zend_array_live_keys(array));
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
    if (strcmp(name, "array_replace") == 0) {
        return jinx_oracle_zend_array_value_owned(jinx_oracle_zend_array_merge_core(args, argc, 1));
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
    if (strcmp(name, "array_diff") == 0 || strcmp(name, "array_diff_assoc") == 0 ||
        strcmp(name, "array_diff_key") == 0 || strcmp(name, "array_intersect") == 0 ||
        strcmp(name, "array_intersect_assoc") == 0 || strcmp(name, "array_intersect_key") == 0) {
        return jinx_oracle_zend_array_diff_intersect_special(name, args, argc);
    }

    return jinx_oracle_zero_value();
}

#endif /* JINX_ORACLE_ZEND_ARRAY_BUILTINS_H */
