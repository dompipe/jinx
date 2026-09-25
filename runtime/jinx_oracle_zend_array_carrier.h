#ifndef JINX_ORACLE_ZEND_ARRAY_CARRIER_H
#define JINX_ORACLE_ZEND_ARRAY_CARRIER_H

#include "jinx_zend_engine.h"
#include "../build/oracle-asm/jinx_oracle_asm_runtime.h"

/*
 * JinxValue carrier for native Zend-shaped arrays.
 *
 * The generated Oracle/PASM runtime already has a generic pointer slot on
 * JinxValue. This bridge assigns a stable JINX-owned type tag for carrying
 * JinxZendArray * through Oracle builtin dispatch without reducing arrays to
 * count-only stand-ins.
 *
 * Ownership is explicit: the carrier is borrowed by default. Callers that need
 * retained lifetime should retain/release the JinxZendArray themselves.
 */

#define JINX_ORACLE_VALUE_ZEND_ARRAY 6u
#define JINX_ORACLE_ZEND_ARRAY_BORROWED 0u
#define JINX_ORACLE_ZEND_ARRAY_RETAINED 1u

static inline JinxValue jinx_oracle_zend_array_value_borrowed(JinxZendArray *array) {
    JinxValue value = jinx_oracle_zero_value();
    value.type = JINX_ORACLE_VALUE_ZEND_ARRAY;
    value.flags = JINX_ORACLE_ZEND_ARRAY_BORROWED;
    value.as.ptr = array;
    return value;
}

static inline JinxValue jinx_oracle_zend_array_value_retained(JinxZendArray *array) {
    JinxValue value = jinx_oracle_zero_value();
    value.type = JINX_ORACLE_VALUE_ZEND_ARRAY;
    value.flags = JINX_ORACLE_ZEND_ARRAY_RETAINED;
    value.as.ptr = jinx_zend_array_retain(array);
    return value;
}

static inline int jinx_oracle_value_is_zend_array(JinxValue value) {
    return value.type == JINX_ORACLE_VALUE_ZEND_ARRAY && value.as.ptr != 0;
}

static inline JinxZendArray *jinx_oracle_zend_array_ptr(JinxValue value) {
    return jinx_oracle_value_is_zend_array(value) ? (JinxZendArray *)value.as.ptr : 0;
}

static inline void jinx_oracle_zend_array_value_release(JinxValue value) {
    if (jinx_oracle_value_is_zend_array(value) && value.flags == JINX_ORACLE_ZEND_ARRAY_RETAINED) {
        jinx_zend_array_release((JinxZendArray *)value.as.ptr);
    }
}

#endif /* JINX_ORACLE_ZEND_ARRAY_CARRIER_H */
