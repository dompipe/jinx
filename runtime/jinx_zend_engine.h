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

typedef enum JinxZendFlags {
    JINX_ZEND_FLAG_NONE = 0u,
    JINX_ZEND_STRING_OWNED = 1u << 0,
    JINX_ZEND_STRING_INTERNED = 1u << 1,
    JINX_ZEND_STRING_PERSISTENT = 1u << 2,
    JINX_ZEND_ARRAY_PACKED = 1u << 8,
    JINX_ZEND_ARRAY_MIXED = 1u << 9
} JinxZendFlags;

typedef struct JinxZendString {
    uint32_t refcount;
    uint32_t flags;
    size_t len;
    size_t capacity;
    char *bytes;
} JinxZendString;

typedef struct JinxZendReference JinxZendReference;
typedef struct JinxZendArray JinxZendArray;
typedef struct JinxZendObject JinxZendObject;

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

typedef struct JinxZendBucket {
    uint64_t h;
    JinxZendString *key;
    JinxZendValue value;
} JinxZendBucket;

struct JinxZendArray {
    uint32_t refcount;
    uint32_t flags;
    size_t count;
    size_t capacity;
    size_t next_index;
    size_t internal_pointer;
    JinxZendBucket *buckets;
};

struct JinxZendObject {
    uint32_t refcount;
    uint32_t flags;
    const char *class_name;
    JinxZendArray *properties;
};

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
    JinxZendArray *locals;
    JinxZendValue return_value;
    struct JinxZendCallFrame *previous;
} JinxZendCallFrame;

typedef struct JinxZendExecutor {
    JinxZendCallFrame *current_frame;
    const char *last_error;
    const char *last_error_file;
    uint32_t last_error_line;
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
JinxZendValue jinx_zend_value_copy(JinxZendValue value);
void jinx_zend_value_release(JinxZendValue value);

JinxZendString jinx_zend_string_view(const char *bytes, size_t len);
JinxZendString *jinx_zend_string_new(const char *bytes, size_t len);
JinxZendString *jinx_zend_string_retain(JinxZendString *string);
void jinx_zend_string_release(JinxZendString *string);
JinxZendString *jinx_zend_string_separate(JinxZendString **string);
int jinx_zend_string_set_byte(JinxZendString **string, size_t offset, char byte);
uint64_t jinx_zend_string_hash_bytes(const char *bytes, size_t len);
uint64_t jinx_zend_string_hash(const JinxZendString *string);
int jinx_zend_string_equals_bytes(const JinxZendString *string, const char *bytes, size_t len);
JinxZendValue jinx_zend_string_value(JinxZendString *string);

JinxZendArray jinx_zend_array_count_view(size_t count);
JinxZendArray *jinx_zend_array_new_packed(size_t capacity);
JinxZendArray *jinx_zend_array_retain(JinxZendArray *array);
void jinx_zend_array_release(JinxZendArray *array);
JinxZendArray *jinx_zend_array_clone(const JinxZendArray *array);
JinxZendArray *jinx_zend_array_separate(JinxZendArray **array);
int jinx_zend_array_append(JinxZendArray *array, JinxZendValue value);
int jinx_zend_array_append_separate(JinxZendArray **array, JinxZendValue value);
int jinx_zend_array_add_index(JinxZendArray *array, size_t index, JinxZendValue value);
int jinx_zend_array_add_index_separate(JinxZendArray **array, size_t index, JinxZendValue value);
int jinx_zend_array_add_assoc(JinxZendArray *array, const char *key, size_t key_len, JinxZendValue value);
int jinx_zend_array_add_assoc_separate(JinxZendArray **array, const char *key, size_t key_len, JinxZendValue value);
int jinx_zend_array_numeric_string_key(const char *key, size_t key_len, int64_t *index);
int jinx_zend_array_add_symtable(JinxZendArray *array, const char *key, size_t key_len, JinxZendValue value);
JinxZendValue *jinx_zend_array_index(JinxZendArray *array, size_t index);
JinxZendValue *jinx_zend_array_find(JinxZendArray *array, const char *key, size_t key_len);
const JinxZendBucket *jinx_zend_array_iter_at(const JinxZendArray *array, size_t position);
JinxZendValue jinx_zend_array_value(JinxZendArray *array);

JinxZendObject *jinx_zend_object_new(const char *class_name);
JinxZendObject *jinx_zend_object_retain(JinxZendObject *object);
void jinx_zend_object_release(JinxZendObject *object);
JinxZendValue jinx_zend_object_value(JinxZendObject *object);

size_t jinx_zend_array_count_builtin(const JinxZendArray *array);
int jinx_zend_array_key_exists_index(const JinxZendArray *array, size_t index);
int jinx_zend_array_key_exists_string(const JinxZendArray *array, const char *key, size_t key_len);
int jinx_zend_array_is_list_builtin(const JinxZendArray *array);
JinxZendArray *jinx_zend_array_values_builtin(const JinxZendArray *array);
JinxZendArray *jinx_zend_array_keys_builtin(const JinxZendArray *array);

void jinx_zend_executor_init(JinxZendExecutor *executor);
void jinx_zend_frame_enter(JinxZendExecutor *executor, JinxZendCallFrame *frame, const char *function_name, JinxZendValue *args, size_t argc);
int jinx_zend_frame_set_local(JinxZendCallFrame *frame, const char *name, JinxZendValue value);
JinxZendValue *jinx_zend_frame_get_local(JinxZendCallFrame *frame, const char *name);
JinxZendValue jinx_zend_frame_leave(JinxZendExecutor *executor, JinxZendValue return_value);
const JinxZendModuleFamily *jinx_zend_module_families(size_t *count);
int jinx_zend_smoke(void);

#endif /* JINX_ZEND_ENGINE_H */
