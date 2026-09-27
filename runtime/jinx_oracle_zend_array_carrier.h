#ifndef JINX_ORACLE_ZEND_ARRAY_CARRIER_H
#define JINX_ORACLE_ZEND_ARRAY_CARRIER_H

#include "jinx_zend_engine.h"
#include "../build/oracle-asm/jinx_oracle_asm_runtime.h"

/*
 * JinxValue carriers for native Zend-shaped arrays and objects.
 *
 * The generated Oracle/PASM runtime already has a generic pointer slot on
 * JinxValue. This bridge assigns stable JINX-owned type tags for carrying
 * JinxZendArray * and JinxZendObject * through Oracle builtin dispatch without
 * reducing containers to scalar stand-ins.
 *
 * Ownership is explicit: carriers are borrowed by default. Callers that need
 * retained lifetime should retain/release the corresponding Zend container.
 */

#define JINX_ORACLE_VALUE_ZEND_ARRAY 6u
#define JINX_ORACLE_VALUE_ZEND_OBJECT 7u
#define JINX_ORACLE_ZEND_ARRAY_BORROWED 0u
#define JINX_ORACLE_ZEND_ARRAY_RETAINED 1u
#define JINX_ORACLE_ZEND_OBJECT_BORROWED 0u
#define JINX_ORACLE_ZEND_OBJECT_RETAINED 1u

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

static inline JinxValue jinx_oracle_zend_array_value_owned(JinxZendArray *array) {
    JinxValue value = jinx_oracle_zero_value();
    value.type = JINX_ORACLE_VALUE_ZEND_ARRAY;
    value.flags = JINX_ORACLE_ZEND_ARRAY_RETAINED;
    value.as.ptr = array;
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

static inline JinxValue jinx_oracle_zend_object_value_borrowed(JinxZendObject *object) {
    JinxValue value = jinx_oracle_zero_value();
    value.type = JINX_ORACLE_VALUE_ZEND_OBJECT;
    value.flags = JINX_ORACLE_ZEND_OBJECT_BORROWED;
    value.as.ptr = object;
    return value;
}

static inline JinxValue jinx_oracle_zend_object_value_retained(JinxZendObject *object) {
    JinxValue value = jinx_oracle_zero_value();
    value.type = JINX_ORACLE_VALUE_ZEND_OBJECT;
    value.flags = JINX_ORACLE_ZEND_OBJECT_RETAINED;
    value.as.ptr = jinx_zend_object_retain(object);
    return value;
}

static inline JinxValue jinx_oracle_zend_object_value_owned(JinxZendObject *object) {
    JinxValue value = jinx_oracle_zero_value();
    value.type = JINX_ORACLE_VALUE_ZEND_OBJECT;
    value.flags = JINX_ORACLE_ZEND_OBJECT_RETAINED;
    value.as.ptr = object;
    return value;
}

static inline int jinx_oracle_value_is_zend_object(JinxValue value) {
    return value.type == JINX_ORACLE_VALUE_ZEND_OBJECT && value.as.ptr != 0;
}

static inline JinxZendObject *jinx_oracle_zend_object_ptr(JinxValue value) {
    return jinx_oracle_value_is_zend_object(value) ? (JinxZendObject *)value.as.ptr : 0;
}

static inline void jinx_oracle_zend_object_value_release(JinxValue value) {
    if (jinx_oracle_value_is_zend_object(value) && value.flags == JINX_ORACLE_ZEND_OBJECT_RETAINED) {
        jinx_zend_object_release((JinxZendObject *)value.as.ptr);
    }
}

static inline void jinx_oracle_zend_container_value_release(JinxValue value) {
    jinx_oracle_zend_array_value_release(value);
    jinx_oracle_zend_object_value_release(value);
}

#endif /* JINX_ORACLE_ZEND_ARRAY_CARRIER_H */
