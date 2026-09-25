#ifndef JINX_ORACLE_ZEND_ARRAY_BUILTINS_H
#define JINX_ORACLE_ZEND_ARRAY_BUILTINS_H

#include "jinx_oracle_zend_array_carrier.h"
#include "jinx_zend_array_delete.h"

#include <string.h>

/*
 * Oracle/PASM-facing dispatch bridge for PHP array builtins whose first
 * argument is carried as a native JinxZendArray * inside JinxValue.
 *
 * This bridge keeps generated Oracle ASM wrappers replaceable: generated code
 * can call this stable function before falling back to count-only stand-ins or
 * other generic dispatch behavior.
 */

static inline JinxValue jinx_oracle_zend_array_to_count_value(JinxZendArray *array) {
    return jinx_oracle_array_count_value((uint32_t)jinx_zend_array_live_count(array));
}

static inline JinxValue jinx_oracle_zend_array_dispatch_builtin(
    const char *name,
    JinxValue array_value,
    JinxValue key_value
) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(array_value);
    JinxZendArray *result_array;

    if (name == 0 || array == 0) {
        return jinx_oracle_zero_value();
    }

    if (strcmp(name, "count") == 0) {
        return jinx_oracle_int_value((int64_t)jinx_zend_array_live_count(array));
    }

    if (strcmp(name, "array_key_exists") == 0) {
        if (key_value.type == 3u) {
            return jinx_oracle_bool_value(jinx_zend_array_live_key_exists_string(
                array,
                (const char *)key_value.as.ptr,
                (size_t)key_value.flags
            ));
        }
        return jinx_oracle_bool_value(jinx_zend_array_live_key_exists_index(
            array,
            (size_t)jinx_oracle_intish(key_value)
        ));
    }

    if (strcmp(name, "array_is_list") == 0) {
        return jinx_oracle_bool_value(jinx_zend_array_live_is_list(array));
    }

    if (strcmp(name, "array_values") == 0) {
        result_array = jinx_zend_array_live_values(array);
        return jinx_oracle_zend_array_value_retained(result_array);
    }

    if (strcmp(name, "array_keys") == 0) {
        result_array = jinx_zend_array_live_keys(array);
        return jinx_oracle_zend_array_value_retained(result_array);
    }

    return jinx_oracle_zero_value();
}

#endif /* JINX_ORACLE_ZEND_ARRAY_BUILTINS_H */
