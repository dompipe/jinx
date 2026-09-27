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

static inline JinxValue jinx_oracle_zend_array_dispatch_builtin(
    const char *name,
    const JinxValue *args,
    size_t argc
) {
    if (name == 0 || args == 0 || argc == 0u) return jinx_oracle_zero_value();

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

    return jinx_oracle_zero_value();
}

#endif /* JINX_ORACLE_ZEND_ARRAY_BUILTINS_H */
