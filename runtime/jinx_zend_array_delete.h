#ifndef JINX_ZEND_ARRAY_DELETE_H
#define JINX_ZEND_ARRAY_DELETE_H

#include "jinx_zend_engine.h"

#include <stdint.h>
#include <string.h>

/*
 * Tombstone/delete helpers for the JINX-owned Zend-shaped HashTable layer.
 *
 * This layer gives unset/delete semantics to the native HashTable model. Live
 * helpers intentionally skip tombstones so PHP array builtins and foreach can
 * preserve insertion order without exposing deleted buckets.
 */

#define JINX_ZEND_BUCKET_TOMBSTONE_HASH UINT64_MAX
#define JINX_ZEND_BUCKET_TOMBSTONE_TYPE JINX_ZEND_RESOURCE
#define JINX_ZEND_BUCKET_TOMBSTONE_FLAG (1u << 31)

static inline int jinx_zend_bucket_is_tombstone(const JinxZendBucket *bucket) {
    return bucket != 0 &&
        bucket->h == JINX_ZEND_BUCKET_TOMBSTONE_HASH &&
        bucket->key == 0 &&
        bucket->value.type == JINX_ZEND_BUCKET_TOMBSTONE_TYPE &&
        (bucket->value.flags & JINX_ZEND_BUCKET_TOMBSTONE_FLAG) != 0;
}

static inline void jinx_zend_bucket_mark_tombstone(JinxZendBucket *bucket) {
    if (bucket == 0 || jinx_zend_bucket_is_tombstone(bucket)) {
        return;
    }

    jinx_zend_string_release(bucket->key);
    jinx_zend_value_release(bucket->value);
    bucket->h = JINX_ZEND_BUCKET_TOMBSTONE_HASH;
    bucket->key = 0;
    bucket->value = jinx_zend_null();
    bucket->value.type = JINX_ZEND_BUCKET_TOMBSTONE_TYPE;
    bucket->value.flags = JINX_ZEND_BUCKET_TOMBSTONE_FLAG;
}

static inline size_t jinx_zend_array_live_count(const JinxZendArray *array) {
    size_t live = 0;

    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[i])) {
            live++;
        }
    }

    return live;
}

static inline int jinx_zend_array_delete_index(JinxZendArray *array, size_t index) {
    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[i]) &&
            array->buckets[i].key == 0 &&
            array->buckets[i].h == index) {
            jinx_zend_bucket_mark_tombstone(&array->buckets[i]);
            return 1;
        }
    }

    return 0;
}

static inline int jinx_zend_array_delete_string(JinxZendArray *array, const char *key, size_t key_len) {
    uint64_t hash;

    if (array == 0 || key == 0 || array->buckets == 0) {
        return 0;
    }

    hash = jinx_zend_string_hash_bytes(key, key_len);
    for (size_t i = 0; i < array->count; i++) {
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[i]) &&
            array->buckets[i].key != 0 &&
            array->buckets[i].h == hash &&
            jinx_zend_string_equals_bytes(array->buckets[i].key, key, key_len)) {
            jinx_zend_bucket_mark_tombstone(&array->buckets[i]);
            return 1;
        }
    }

    return 0;
}

static inline const JinxZendBucket *jinx_zend_array_live_iter_at(const JinxZendArray *array, size_t live_position) {
    size_t seen = 0;

    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (jinx_zend_bucket_is_tombstone(&array->buckets[i])) {
            continue;
        }
        if (seen == live_position) {
            return &array->buckets[i];
        }
        seen++;
    }

    return 0;
}

static inline int jinx_zend_array_live_key_exists_index(const JinxZendArray *array, size_t index) {
    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[i]) &&
            array->buckets[i].key == 0 &&
            array->buckets[i].h == index) {
            return 1;
        }
    }

    return 0;
}

static inline int jinx_zend_array_live_key_exists_string(const JinxZendArray *array, const char *key, size_t key_len) {
    uint64_t hash;

    if (array == 0 || key == 0 || array->buckets == 0) {
        return 0;
    }

    hash = jinx_zend_string_hash_bytes(key, key_len);
    for (size_t i = 0; i < array->count; i++) {
        if (!jinx_zend_bucket_is_tombstone(&array->buckets[i]) &&
            array->buckets[i].key != 0 &&
            array->buckets[i].h == hash &&
            jinx_zend_string_equals_bytes(array->buckets[i].key, key, key_len)) {
            return 1;
        }
    }

    return 0;
}

static inline int jinx_zend_array_live_is_list(const JinxZendArray *array) {
    size_t expected = 0;

    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (jinx_zend_bucket_is_tombstone(&array->buckets[i])) {
            continue;
        }
        if (array->buckets[i].key != 0 || array->buckets[i].h != expected) {
            return 0;
        }
        expected++;
    }

    return 1;
}

static inline JinxZendArray *jinx_zend_array_live_values(const JinxZendArray *array) {
    JinxZendArray *values;
    size_t live;

    if (array == 0) {
        return 0;
    }

    live = jinx_zend_array_live_count(array);
    values = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (values == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (jinx_zend_bucket_is_tombstone(&array->buckets[i])) {
            continue;
        }
        if (!jinx_zend_array_append(values, array->buckets[i].value)) {
            jinx_zend_array_release(values);
            return 0;
        }
    }

    return values;
}

static inline JinxZendArray *jinx_zend_array_live_keys(const JinxZendArray *array) {
    JinxZendArray *keys;
    size_t live;

    if (array == 0) {
        return 0;
    }

    live = jinx_zend_array_live_count(array);
    keys = jinx_zend_array_new_packed(live == 0u ? 1u : live);
    if (keys == 0) {
        return 0;
    }

    for (size_t i = 0; i < array->count; i++) {
        if (jinx_zend_bucket_is_tombstone(&array->buckets[i])) {
            continue;
        }
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

static inline int jinx_zend_array_compact(JinxZendArray *array) {
    size_t write = 0;

    if (array == 0 || array->buckets == 0) {
        return 0;
    }

    for (size_t read = 0; read < array->count; read++) {
        if (jinx_zend_bucket_is_tombstone(&array->buckets[read])) {
            continue;
        }
        if (write != read) {
            array->buckets[write] = array->buckets[read];
            memset(&array->buckets[read], 0, sizeof(JinxZendBucket));
        }
        write++;
    }

    array->count = write;
    return 1;
}

#endif /* JINX_ZEND_ARRAY_DELETE_H */
