#ifndef JINX_ZEND_ENGINE_H
#define JINX_ZEND_ENGINE_H

#include <stddef.h>
#include <stdint.h>

/*
 * Native Zend-shaped runtime skeleton for JINX.
 *
 * This is the first source layer for rewriting Zend/ into Oracle SM / Oracle ASM / PASM.
 * It deliberately models Zend concepts without depending on php-src headers:
 * zval-like values, strings, arrays/hash tables, objects, references, call frames,
 * diagnostics, and request state.
 */

typedef enum JinxZendType {
    JINX_ZEND_NULL = 0,
    JINX_ZEND_FALSE = 1,
    JINX_ZEND_TRUE = 2,
    JINX_ZEND_LONG = 3,
    JINX_ZEND_DOUBLE = 4,
    JINX_ZEND_STRING = 5,
    JINX_ZEND_ARRAY = 6,
    JINX_ZEND_OBJECT = 7,
    JINX_ZEND_REFERENCE = 8,
    JINX_ZEND_RESOURCE = 9
} JinxZendType;

typedef struct JinxZendString {
    uint32_t refcount;
    uint32_t flags;
    size_t len;
    const char *bytes;
} JinxZendString;

typedef struct JinxZendArray {
    uint32_t refcount;
    uint32_t flags;
    size_t count;
    size_t capacity;
    void *buckets;
} JinxZendArray;

typedef struct JinxZendObject {
    uint32_t refcount;
    uint32_t flags;
    const char *class_name;
    JinxZendArray *properties;
} JinxZendObject;

typedef struct JinxZendReference JinxZendReference;

typedef struct JinxZendValue {
    JinxZendType type;
    uint32_t flags;
    union {
        int64_t lval;
        double dval;
        JinxZendString *str;
        JinxZendArray *array;
        JinxZendObject *object;
        JinxZendReference *ref;
        void *ptr;
    } value;
} JinxZendValue;

struct JinxZendReference {
    uint32_t refcount;
    uint32_t flags;
    JinxZendValue value;
};

typedef struct JinxZendCallFrame {
    const char *function_name;
    const char *scope_name;
    JinxZendValue *args;
    size_t argc;
    JinxZendValue return_value;
    struct JinxZendCallFrame *previous;
} JinxZendCallFrame;

typedef struct JinxZendExecutor {
    JinxZendCallFrame *current_frame;
    const char *last_error;
    uint32_t error_level;
    uint64_t executed_ops;
} JinxZendExecutor;

typedef struct JinxZendModuleFamily {
    const char *family;
    const char *php_src_area;
    const char *oracle_sm_target;
    const char *oracle_asm_target;
    const char *pasm_target;
    const char *state;
    const char *notes;
} JinxZendModuleFamily;

JinxZendValue jinx_zend_null(void);
JinxZendValue jinx_zend_bool(int value);
JinxZendValue jinx_zend_long(int64_t value);
JinxZendValue jinx_zend_double(double value);
JinxZendString jinx_zend_string_view(const char *bytes, size_t len);
JinxZendValue jinx_zend_string_value(JinxZendString *string);
JinxZendArray jinx_zend_array_count_view(size_t count);
JinxZendValue jinx_zend_array_value(JinxZendArray *array);
void jinx_zend_executor_init(JinxZendExecutor *executor);
void jinx_zend_frame_enter(JinxZendExecutor *executor, JinxZendCallFrame *frame, const char *function_name, JinxZendValue *args, size_t argc);
JinxZendValue jinx_zend_frame_leave(JinxZendExecutor *executor, JinxZendValue return_value);
const JinxZendModuleFamily *jinx_zend_module_families(size_t *count);
int jinx_zend_smoke(void);

#endif /* JINX_ZEND_ENGINE_H */
