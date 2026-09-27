#include "jinx_zend_engine.h"

#include <stdlib.h>
#include <string.h>

JinxZendValue jinx_zend_null(void) {
    JinxZendValue value;
    value.type = JINX_ZEND_NULL;
    value.flags = 0;
    value.value.ptr = 0;
    return value;
}

JinxZendValue jinx_zend_bool(int v) {
    JinxZendValue value = jinx_zend_null();
    value.type = v ? JINX_ZEND_TRUE : JINX_ZEND_FALSE;
    value.value.lval = v ? 1 : 0;
    return value;
}

JinxZendValue jinx_zend_long(int64_t v) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_LONG;
    value.value.lval = v;
    return value;
}

JinxZendValue jinx_zend_double(double v) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_DOUBLE;
    value.value.dval = v;
    return value;
}

JinxZendValue jinx_zend_value_copy(JinxZendValue value) {
    if (value.type == JINX_ZEND_STRING) {
        value.value.str = jinx_zend_string_retain(value.value.str);
    } else if (value.type == JINX_ZEND_ARRAY) {
        value.value.array = jinx_zend_array_retain(value.value.array);
    } else if (value.type == JINX_ZEND_OBJECT) {
        value.value.object = jinx_zend_object_retain(value.value.object);
    }

    return value;
}

void jinx_zend_value_release(JinxZendValue value) {
    if (value.type == JINX_ZEND_STRING) {
        jinx_zend_string_release(value.value.str);
    } else if (value.type == JINX_ZEND_ARRAY) {
        jinx_zend_array_release(value.value.array);
    } else if (value.type == JINX_ZEND_OBJECT) {
        jinx_zend_object_release(value.value.object);
    }
}

JinxZendString jinx_zend_string_view(const char *bytes, size_t len) {
    JinxZendString string;
    string.refcount = 1;
    string.flags = JINX_ZEND_STRING_INTERNED;
    string.len = len;
    string.capacity = len;
    string.bytes = (char *)bytes;
    return string;
}

JinxZendString *jinx_zend_string_new(const char *bytes, size_t len) {
    JinxZendString *string = (JinxZendString *)calloc(1, sizeof(JinxZendString));
    if (string == 0) {
        return 0;
    }

    string->bytes = (char *)calloc(len + 1u, sizeof(char));
    if (string->bytes == 0) {
        free(string);
        return 0;
    }

    if (bytes != 0 && len != 0) {
        memcpy(string->bytes, bytes, len);
    }

    string->refcount = 1;
    string->flags = JINX_ZEND_STRING_OWNED;
    string->len = len;
    string->capacity = len;
    string->bytes[len] = '\0';
    return string;
}

JinxZendString *jinx_zend_string_retain(JinxZendString *string) {
    if (string != 0 && (string->flags & JINX_ZEND_STRING_INTERNED) == 0) {
        string->refcount++;
    }

    return string;
}

void jinx_zend_string_release(JinxZendString *string) {
    if (string == 0 || (string->flags & JINX_ZEND_STRING_INTERNED) != 0) {
        return;
    }

    if (string->refcount > 1u) {
        string->refcount--;
        return;
    }

    if ((string->flags & JINX_ZEND_STRING_OWNED) != 0) {
        free(string->bytes);
    }

    free(string);
}

JinxZendString *jinx_zend_string_separate(JinxZendString **slot) {
    JinxZendString *string;
    JinxZendString *copy;

    if (slot == 0 || *slot == 0) {
        return 0;
    }

    string = *slot;
    if ((string->flags & JINX_ZEND_STRING_INTERNED) == 0 && string->refcount == 1u &&
        (string->flags & JINX_ZEND_STRING_OWNED) != 0) {
        return string;
    }

    copy = jinx_zend_string_new(string->bytes, string->len);
    if (copy == 0) {
        return 0;
    }

    jinx_zend_string_release(string);
    *slot = copy;
    return copy;
}

int jinx_zend_string_set_byte(JinxZendString **slot, size_t offset, char byte) {
    JinxZendString *string = jinx_zend_string_separate(slot);
    if (string == 0 || offset >= string->len || string->bytes == 0) {
        return 0;
    }

    string->bytes[offset] = byte;
    return 1;
}

uint64_t jinx_zend_string_hash_bytes(const char *bytes, size_t len) {
    uint64_t hash = 1469598103934665603ull;
    if (bytes == 0) {
        return hash;
    }

    for (size_t i = 0; i < len; i++) {
        hash ^= (unsigned char)bytes[i];
        hash *= 1099511628211ull;
    }

    return hash == 0u ? 1u : hash;
}

uint64_t jinx_zend_string_hash(const JinxZendString *string) {
    if (string == 0) {
        return jinx_zend_string_hash_bytes(0, 0);
    }

    return jinx_zend_string_hash_bytes(string->bytes, string->len);
}

int jinx_zend_string_equals_bytes(const JinxZendString *string, const char *bytes, size_t len) {
    if (string == 0 || bytes == 0 || string->bytes == 0 || string->len != len) {
        return 0;
    }

    return memcmp(string->bytes, bytes, len) == 0;
}

JinxZendValue jinx_zend_string_value(JinxZendString *string) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_STRING;
    value.value.str = string;
    return value;
}

JinxZendArray jinx_zend_array_count_view(size_t count) {
    JinxZendArray array;
    array.refcount = 1;
    array.flags = JINX_ZEND_ARRAY_PACKED;
    array.count = count;
    array.capacity = count;
    array.next_index = count;
    array.internal_pointer = 0u;
    array.buckets = 0;
    return array;
}

JinxZendArray *jinx_zend_array_new_packed(size_t capacity) {
    JinxZendArray *array;

    if (capacity == 0u) {
        capacity = 4u;
    }

    array = (JinxZendArray *)calloc(1, sizeof(JinxZendArray));
    if (array == 0) {
        return 0;
    }

    array->buckets = (JinxZendBucket *)calloc(capacity, sizeof(JinxZendBucket));
    if (array->buckets == 0) {
        free(array);
        return 0;
    }

    array->refcount = 1;
    array->flags = JINX_ZEND_ARRAY_PACKED;
    array->count = 0;
    array->capacity = capacity;
    array->next_index = 0;
    array->internal_pointer = 0u;
    return array;
}

JinxZendArray *jinx_zend_array_retain(JinxZendArray *array) {
    if (array != 0) {
        array->refcount++;
    }

    return array;
}

void jinx_zend_array_release(JinxZendArray *array) {
    if (array == 0) {
        return;
    }

    if (array->refcount > 1u) {
        array->refcount--;
        return;
    }

    if (array->buckets != 0) {
        for (size_t i = 0; i < array->count; i++) {
            jinx_zend_string_release(array->buckets[i].key);
            jinx_zend_value_release(array->buckets[i].value);
        }
        free(array->buckets);
    }

    free(array);
}

JinxZendObject *jinx_zend_object_new(const char *class_name) {
    const char *name = class_name != 0 ? class_name : "stdClass";
    size_t name_len = strlen(name);

    JinxZendObject *object = (JinxZendObject *)calloc(1u, sizeof(JinxZendObject));
    if (object == 0) return 0;

    char *owned_name = (char *)calloc(name_len + 1u, sizeof(char));
    if (owned_name == 0) {
        free(object);
        return 0;
    }
    if (name_len != 0u) memcpy(owned_name, name, name_len);
    owned_name[name_len] = '\0';

    object->properties = jinx_zend_array_new_packed(4u);
    if (object->properties == 0) {
        free(owned_name);
        free(object);
        return 0;
    }

    object->refcount = 1u;
    object->flags = 0u;
    object->class_name = owned_name;
    return object;
}

JinxZendObject *jinx_zend_object_retain(JinxZendObject *object) {
    if (object != 0) object->refcount++;
    return object;
}

void jinx_zend_object_release(JinxZendObject *object) {
    if (object == 0) return;

    if (object->refcount > 1u) {
        object->refcount--;
        return;
    }

    jinx_zend_array_release(object->properties);
    free((void *)object->class_name);
    free(object);
}

JinxZendValue jinx_zend_object_value(JinxZendObject *object) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_OBJECT;
    value.value.object = object;
    return value;
}

static int jinx_zend_array_reserve(JinxZendArray *array, size_t needed) {
    size_t new_capacity;
    JinxZendBucket *new_buckets;

    if (array == 0) {
        return 0;
    }

    if (needed <= array->capacity) {
        return 1;
    }

    new_capacity = array->capacity == 0u ? 4u : array->capacity;
    while (new_capacity < needed) {
        new_capacity *= 2u;
    }

    new_buckets = (JinxZendBucket *)realloc(array->buckets, new_capacity * sizeof(JinxZendBucket));
    if (new_buckets == 0) {
        return 0;
    }

    memset(new_buckets + array->capacity, 0, (new_capacity - array->capacity) * sizeof(JinxZendBucket));
    array->buckets = new_buckets;
    array->capacity = new_capacity;
    return 1;
}

JinxZendArray *jinx_zend_array_clone(const JinxZendArray *array) {
    JinxZendArray *copy;

    if (array == 0) {
        return 0;
    }

    copy = jinx_zend_array_new_packed(array->capacity == 0u ? array->count : array->capacity);
    if (copy == 0) {
        return 0;
    }

    copy->flags = array->flags;
    copy->count = array->count;
    copy->next_index = array->next_index;
    copy->internal_pointer = array->internal_pointer;

    for (size_t i = 0; i < array->count; i++) {
        copy->buckets[i].h = array->buckets[i].h;
        copy->buckets[i].key = jinx_zend_string_retain(array->buckets[i].key);
        copy->buckets[i].value = jinx_zend_value_copy(array->buckets[i].value);
    }

    return copy;
}

JinxZendArray *jinx_zend_array_separate(JinxZendArray **slot) {
    JinxZendArray *array;
    JinxZendArray *copy;

    if (slot == 0 || *slot == 0) {
        return 0;
    }

    array = *slot;
    if (array->refcount == 1u) {
        return array;
    }

    copy = jinx_zend_array_clone(array);
    if (copy == 0) {
        return 0;
    }

    jinx_zend_array_release(array);
    *slot = copy;
    return copy;
}

int jinx_zend_array_append(JinxZendArray *array, JinxZendValue value) {
    JinxZendBucket *bucket;

    if (array == 0 || !jinx_zend_array_reserve(array, array->count + 1u)) {
        return 0;
    }

    bucket = &array->buckets[array->count];
    bucket->h = array->next_index;
    bucket->key = 0;
    bucket->value = jinx_zend_value_copy(value);
    array->count++;
    array->next_index++;
    if ((array->flags & JINX_ZEND_ARRAY_MIXED) == 0) {
        array->flags = (array->flags & ~JINX_ZEND_ARRAY_MIXED) | JINX_ZEND_ARRAY_PACKED;
    }
    return 1;
}

int jinx_zend_array_append_separate(JinxZendArray **slot, JinxZendValue value) {
    JinxZendArray *array = jinx_zend_array_separate(slot);
    if (array == 0) {
        return 0;
    }

    return jinx_zend_array_append(array, value);
}

int jinx_zend_array_add_index(JinxZendArray *array, size_t index, JinxZendValue value) {
    JinxZendBucket *bucket;

    if (array == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        bucket = &array->buckets[i];
        if (bucket->key == 0 && bucket->h == index) {
            jinx_zend_value_release(bucket->value);
            bucket->value = jinx_zend_value_copy(value);
            return 1;
        }
    }

    if (!jinx_zend_array_reserve(array, array->count + 1u)) {
        return 0;
    }

    bucket = &array->buckets[array->count];
    bucket->h = index;
    bucket->key = 0;
    bucket->value = jinx_zend_value_copy(value);
    array->count++;

    if (index >= array->next_index) {
        array->next_index = index + 1u;
    }

    if (index != array->count - 1u) {
        array->flags = (array->flags & ~JINX_ZEND_ARRAY_PACKED) | JINX_ZEND_ARRAY_MIXED;
    }

    return 1;
}

int jinx_zend_array_add_index_separate(JinxZendArray **slot, size_t index, JinxZendValue value) {
    JinxZendArray *array = jinx_zend_array_separate(slot);
    if (array == 0) {
        return 0;
    }

    return jinx_zend_array_add_index(array, index, value);
}

int jinx_zend_array_add_assoc(JinxZendArray *array, const char *key, size_t key_len, JinxZendValue value) {
    uint64_t hash;
    JinxZendBucket *bucket;
    JinxZendString *owned_key;

    if (array == 0 || key == 0 || !jinx_zend_array_reserve(array, array->count + 1u)) {
        return 0;
    }

    hash = jinx_zend_string_hash_bytes(key, key_len);
    for (size_t i = 0; i < array->count; i++) {
        bucket = &array->buckets[i];
        if (bucket->key != 0 && bucket->h == hash && jinx_zend_string_equals_bytes(bucket->key, key, key_len)) {
            jinx_zend_value_release(bucket->value);
            bucket->value = jinx_zend_value_copy(value);
            return 1;
        }
    }

    owned_key = jinx_zend_string_new(key, key_len);
    if (owned_key == 0) {
        return 0;
    }

    bucket = &array->buckets[array->count];
    bucket->h = hash;
    bucket->key = owned_key;
    bucket->value = jinx_zend_value_copy(value);
    array->count++;
    array->flags = (array->flags & ~JINX_ZEND_ARRAY_PACKED) | JINX_ZEND_ARRAY_MIXED;
    return 1;
}

int jinx_zend_array_numeric_string_key(const char *key, size_t key_len, int64_t *index) {
    const unsigned char *bytes = (const unsigned char *)key;
    size_t pos = 0u;
    int negative = 0;
    uint64_t value = 0u;
    uint64_t limit;

    if (key == 0 || key_len == 0u || index == 0) {
        return 0;
    }

    if (bytes[0] == (unsigned char)'-') {
        negative = 1;
        pos = 1u;
        if (pos == key_len) {
            return 0;
        }
    } else if (bytes[0] == (unsigned char)'+') {
        return 0;
    }

    if (bytes[pos] < (unsigned char)'0' || bytes[pos] > (unsigned char)'9') {
        return 0;
    }

    /* Zend does not convert decimal strings with leading zeroes, including "-0". */
    if (bytes[pos] == (unsigned char)'0' && key_len > 1u) {
        return 0;
    }

    limit = negative ? ((uint64_t)INT64_MAX + 1u) : (uint64_t)INT64_MAX;

    for (; pos < key_len; pos++) {
        unsigned char ch = bytes[pos];
        uint64_t digit;

        if (ch < (unsigned char)'0' || ch > (unsigned char)'9') {
            return 0;
        }

        digit = (uint64_t)(ch - (unsigned char)'0');
        if (value > (limit - digit) / 10u) {
            return 0;
        }
        value = value * 10u + digit;
    }

    if (negative) {
        *index = value == (uint64_t)INT64_MAX + 1u ? INT64_MIN : -(int64_t)value;
    } else {
        *index = (int64_t)value;
    }

    return 1;
}

int jinx_zend_array_add_symtable(JinxZendArray *array, const char *key, size_t key_len, JinxZendValue value) {
    int64_t index;

    if (jinx_zend_array_numeric_string_key(key, key_len, &index)) {
        return jinx_zend_array_add_index(array, (size_t)index, value);
    }

    return jinx_zend_array_add_assoc(array, key, key_len, value);
}

int jinx_zend_array_add_assoc_separate(JinxZendArray **slot, const char *key, size_t key_len, JinxZendValue value) {
    JinxZendArray *array = jinx_zend_array_separate(slot);
    if (array == 0) {
        return 0;
    }

    return jinx_zend_array_add_assoc(array, key, key_len, value);
}

JinxZendValue *jinx_zend_array_index(JinxZendArray *array, size_t index) {
    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (array->buckets[i].key == 0 && array->buckets[i].h == index) {
            return &array->buckets[i].value;
        }
    }

    return 0;
}

JinxZendValue *jinx_zend_array_find(JinxZendArray *array, const char *key, size_t key_len) {
    uint64_t hash;

    if (array == 0 || key == 0 || array->buckets == 0) {
        return 0;
    }

    hash = jinx_zend_string_hash_bytes(key, key_len);
    for (size_t i = 0; i < array->count; i++) {
        if (array->buckets[i].key != 0 && array->buckets[i].h == hash &&
            jinx_zend_string_equals_bytes(array->buckets[i].key, key, key_len)) {
            return &array->buckets[i].value;
        }
    }

    return 0;
}

const JinxZendBucket *jinx_zend_array_iter_at(const JinxZendArray *array, size_t position) {
    if (array == 0 || position >= array->count || array->buckets == 0) {
        return 0;
    }

    return &array->buckets[position];
}

JinxZendValue jinx_zend_array_value(JinxZendArray *array) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_ARRAY;
    value.value.array = array;
    return value;
}

size_t jinx_zend_array_count_builtin(const JinxZendArray *array) {
    return array == 0 ? 0u : array->count;
}

int jinx_zend_array_key_exists_index(const JinxZendArray *array, size_t index) {
    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (array->buckets[i].key == 0 && array->buckets[i].h == index) {
            return 1;
        }
    }

    return 0;
}

int jinx_zend_array_key_exists_string(const JinxZendArray *array, const char *key, size_t key_len) {
    uint64_t hash;

    if (array == 0 || key == 0 || array->buckets == 0) {
        return 0;
    }

    hash = jinx_zend_string_hash_bytes(key, key_len);
    for (size_t i = 0; i < array->count; i++) {
        if (array->buckets[i].key != 0 && array->buckets[i].h == hash &&
            jinx_zend_string_equals_bytes(array->buckets[i].key, key, key_len)) {
            return 1;
        }
    }

    return 0;
}

int jinx_zend_array_is_list_builtin(const JinxZendArray *array) {
    if (array == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (array->buckets == 0 || array->buckets[i].key != 0 || array->buckets[i].h != i) {
            return 0;
        }
    }

    return 1;
}

JinxZendArray *jinx_zend_array_values_builtin(const JinxZendArray *array) {
    JinxZendArray *values;

    if (array == 0) {
        return 0;
    }

    values = jinx_zend_array_new_packed(array->count);
    if (values == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (!jinx_zend_array_append(values, array->buckets[i].value)) {
            jinx_zend_array_release(values);
            return 0;
        }
    }

    return values;
}

JinxZendArray *jinx_zend_array_keys_builtin(const JinxZendArray *array) {
    JinxZendArray *keys;

    if (array == 0) {
        return 0;
    }

    keys = jinx_zend_array_new_packed(array->count);
    if (keys == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (array->buckets[i].key != 0) {
            if (!jinx_zend_array_append(keys, jinx_zend_string_value(array->buckets[i].key))) {
                jinx_zend_array_release(keys);
                return 0;
            }
        } else if (!jinx_zend_array_append(keys, jinx_zend_long((int64_t)array->buckets[i].h))) {
            jinx_zend_array_release(keys);
            return 0;
        }
    }

    return keys;
}

void jinx_zend_executor_init(JinxZendExecutor *executor) {
    if (executor == 0) {
        return;
    }

    executor->current_frame = 0;
    executor->last_error = 0;
    executor->error_level = 0;
    executor->executed_ops = 0;
}

void jinx_zend_frame_enter(
    JinxZendExecutor *executor,
    JinxZendCallFrame *frame,
    const char *function_name,
    JinxZendValue *args,
    size_t argc
) {
    if (executor == 0 || frame == 0) {
        return;
    }

    frame->function_name = function_name;
    frame->scope_name = 0;
    frame->args = args;
    frame->argc = argc;
    frame->return_value = jinx_zend_null();
    frame->previous = executor->current_frame;
    executor->current_frame = frame;
    executor->executed_ops++;
}

JinxZendValue jinx_zend_frame_leave(JinxZendExecutor *executor, JinxZendValue return_value) {
    if (executor != 0 && executor->current_frame != 0) {
        executor->current_frame->return_value = return_value;
        executor->current_frame = executor->current_frame->previous;
        executor->executed_ops++;
    }

    return return_value;
}

static const JinxZendModuleFamily jinx_zend_families[] = {
    {
        "zval",
        "Zend/zend_types.h, Zend/zend.h",
        "oracle-sm/zend/zval.osm",
        "build/oracle-asm/zend/zval.oracle_asm.h",
        "runtime/pasm/zend/zval.pasm",
        "started",
        "Native JinxZendValue copy/destruct exists for strings and arrays. Next: object/reference/resource destructors."
    },
    {
        "zend_string",
        "Zend/zend_string.h, Zend/zend_string.c",
        "oracle-sm/zend/string.osm",
        "build/oracle-asm/zend/string.oracle_asm.h",
        "runtime/pasm/zend/string.pasm",
        "started",
        "Owned strings, borrowed views, retain/release, copy-on-write separation, and string hashing exist. Next: interned-string table and hash cache."
    },
    {
        "HashTable/zend_array",
        "Zend/zend_hash.h, Zend/zend_hash.c, Zend/zend_array.c",
        "oracle-sm/zend/hash.osm",
        "build/oracle-asm/zend/hash.oracle_asm.h",
        "runtime/pasm/zend/hash.pasm",
        "started",
        "Packed/mixed buckets, COW, count, key_exists, list checks, values, and keys now exist. Next: deletion tombstones."
    },
    {
        "executor/call-frame",
        "Zend/zend_execute.c, Zend/zend_vm_def.h, Zend/zend_vm_execute.h",
        "oracle-sm/zend/executor.osm",
        "build/oracle-asm/zend/executor.oracle_asm.h",
        "runtime/pasm/zend/executor.pasm",
        "started",
        "Call-frame skeleton exists; next step is opcode lowering and VM dispatch."
    },
    {
        "objects/classes",
        "Zend/zend_object_handlers.c, Zend/zend_objects_API.c, Zend/zend_compile.c",
        "oracle-sm/zend/object.osm",
        "build/oracle-asm/zend/object.oracle_asm.h",
        "runtime/pasm/zend/object.pasm",
        "planned",
        "Object shell exists; class tables, methods, properties, traits, and interfaces are next."
    },
    {
        "errors/exceptions",
        "Zend/zend_exceptions.c, Zend/zend_errors.h",
        "oracle-sm/zend/errors.osm",
        "build/oracle-asm/zend/errors.oracle_asm.h",
        "runtime/pasm/zend/errors.pasm",
        "planned",
        "Error slot exists on executor; warnings, TypeError, ValueError, and exception unwinding are next."
    },
    {
        "compiler/opcodes",
        "Zend/zend_language_parser.y, Zend/zend_compile.c, Zend/zend_vm_def.h",
        "oracle-sm/zend/opcodes.osm",
        "build/oracle-asm/zend/opcodes.oracle_asm.h",
        "runtime/pasm/zend/opcodes.pasm",
        "planned",
        "Required for arbitrary PHP source: parse, lower AST/opcodes, then execute via Oracle/PASM."
    }
};

const JinxZendModuleFamily *jinx_zend_module_families(size_t *count) {
    if (count != 0) {
        *count = sizeof(jinx_zend_families) / sizeof(jinx_zend_families[0]);
    }

    return jinx_zend_families;
}

int jinx_zend_smoke(void) {
    JinxZendExecutor executor;
    JinxZendCallFrame frame;
    JinxZendValue args[2];
    JinxZendString view = jinx_zend_string_view("oracle", 6);
    JinxZendString *owned = jinx_zend_string_new("dompipe", 7);
    JinxZendString *alias;
    JinxZendArray *packed;
    JinxZendArray *detached;
    JinxZendArray *values;
    JinxZendArray *keys;
    JinxZendValue *slot;
    JinxZendValue *assoc;
    JinxZendValue *detached_assoc;
    JinxZendValue *detached_slot;
    JinxZendValue *values_slot;
    JinxZendValue *keys_numeric;
    JinxZendValue *keys_string;
    const JinxZendBucket *bucket0;
    const JinxZendBucket *bucket1;
    const JinxZendBucket *bucket2;
    JinxZendValue result;
    int ok;

    if (owned == 0) {
        return 0;
    }

    alias = jinx_zend_string_retain(owned);
    if (alias == 0 || owned->refcount != 2u) {
        jinx_zend_string_release(owned);
        return 0;
    }

    ok = jinx_zend_string_set_byte(&alias, 0, 'J');
    if (!ok || alias == owned || alias->refcount != 1u || owned->refcount != 1u ||
        alias->bytes[0] != 'J' || owned->bytes[0] != 'd') {
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    packed = jinx_zend_array_new_packed(2);
    if (packed == 0) {
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    if (!jinx_zend_array_append(packed, jinx_zend_long(7)) ||
        !jinx_zend_array_append(packed, jinx_zend_string_value(alias)) ||
        !jinx_zend_array_add_assoc(packed, "name", 4, jinx_zend_string_value(owned))) {
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    slot = jinx_zend_array_index(packed, 0);
    assoc = jinx_zend_array_find(packed, "name", 4);
    bucket0 = jinx_zend_array_iter_at(packed, 0);
    bucket1 = jinx_zend_array_iter_at(packed, 1);
    bucket2 = jinx_zend_array_iter_at(packed, 2);

    if (packed->count != 3u || packed->next_index != 2u ||
        (packed->flags & JINX_ZEND_ARRAY_MIXED) == 0 ||
        slot == 0 || slot->type != JINX_ZEND_LONG || slot->value.lval != 7 ||
        assoc == 0 || assoc->type != JINX_ZEND_STRING || assoc->value.str != owned ||
        bucket0 == 0 || bucket0->h != 0u || bucket0->key != 0 ||
        bucket1 == 0 || bucket1->h != 1u || bucket1->key != 0 ||
        bucket1->value.type != JINX_ZEND_STRING || bucket1->value.value.str != alias || alias->refcount != 2u ||
        bucket2 == 0 || bucket2->key == 0 || !jinx_zend_string_equals_bytes(bucket2->key, "name", 4) ||
        bucket2->value.type != JINX_ZEND_STRING || bucket2->value.value.str != owned || owned->refcount != 2u) {
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    if (!jinx_zend_array_add_assoc(packed, "name", 4, jinx_zend_long(99))) {
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    assoc = jinx_zend_array_find(packed, "name", 4);
    if (packed->count != 3u || assoc == 0 || assoc->type != JINX_ZEND_LONG || assoc->value.lval != 99 || owned->refcount != 1u) {
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    detached = jinx_zend_array_retain(packed);
    if (detached == 0 || packed->refcount != 2u ||
        !jinx_zend_array_add_assoc_separate(&detached, "name", 4, jinx_zend_long(123)) ||
        !jinx_zend_array_append_separate(&detached, jinx_zend_long(88))) {
        jinx_zend_array_release(detached);
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    assoc = jinx_zend_array_find(packed, "name", 4);
    detached_assoc = jinx_zend_array_find(detached, "name", 4);
    detached_slot = jinx_zend_array_index(detached, 2);

    if (detached == packed || packed->refcount != 1u || detached->refcount != 1u ||
        packed->count != 3u || detached->count != 4u || packed->next_index != 2u || detached->next_index != 3u ||
        assoc == 0 || assoc->type != JINX_ZEND_LONG || assoc->value.lval != 99 ||
        detached_assoc == 0 || detached_assoc->type != JINX_ZEND_LONG || detached_assoc->value.lval != 123 ||
        detached_slot == 0 || detached_slot->type != JINX_ZEND_LONG || detached_slot->value.lval != 88) {
        jinx_zend_array_release(detached);
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    values = jinx_zend_array_values_builtin(detached);
    keys = jinx_zend_array_keys_builtin(detached);
    if (values == 0 || keys == 0) {
        jinx_zend_array_release(values);
        jinx_zend_array_release(keys);
        jinx_zend_array_release(detached);
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    values_slot = jinx_zend_array_index(values, 2);
    keys_numeric = jinx_zend_array_index(keys, 0);
    keys_string = jinx_zend_array_index(keys, 2);
    if (jinx_zend_array_count_builtin(detached) != 4u ||
        !jinx_zend_array_key_exists_index(detached, 2u) ||
        !jinx_zend_array_key_exists_string(detached, "name", 4) ||
        jinx_zend_array_key_exists_string(detached, "missing", 7) ||
        jinx_zend_array_is_list_builtin(detached) ||
        !jinx_zend_array_is_list_builtin(values) ||
        values->count != 4u || keys->count != 4u ||
        values_slot == 0 || values_slot->type != JINX_ZEND_LONG || values_slot->value.lval != 123 ||
        keys_numeric == 0 || keys_numeric->type != JINX_ZEND_LONG || keys_numeric->value.lval != 0 ||
        keys_string == 0 || keys_string->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(keys_string->value.str, "name", 4)) {
        jinx_zend_array_release(values);
        jinx_zend_array_release(keys);
        jinx_zend_array_release(detached);
        jinx_zend_array_release(packed);
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    jinx_zend_executor_init(&executor);
    args[0] = jinx_zend_string_value(&view);
    args[1] = jinx_zend_array_value(detached);

    jinx_zend_frame_enter(&executor, &frame, "zend-smoke", args, 2);
    result = jinx_zend_frame_leave(&executor, jinx_zend_long((int64_t)(view.len + detached->count + values->count + keys->count + alias->len)));

    ok = executor.current_frame == 0 && executor.executed_ops == 2 &&
        result.type == JINX_ZEND_LONG && result.value.lval == 25;

    jinx_zend_array_release(values);
    jinx_zend_array_release(keys);
    jinx_zend_array_release(detached);
    jinx_zend_array_release(packed);
    jinx_zend_string_release(alias);
    jinx_zend_string_release(owned);
    return ok;
}
