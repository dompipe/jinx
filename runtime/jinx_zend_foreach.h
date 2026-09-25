#ifndef JINX_ZEND_FOREACH_H
#define JINX_ZEND_FOREACH_H

#include "jinx_zend_array_delete.h"

/*
 * Foreach lowering helpers for the JINX-owned Zend-shaped array layer.
 *
 * These helpers iterate the physical HashTable bucket order while presenting
 * only live entries to the caller. Tombstones produced by unset/delete are
 * skipped without requiring compaction first.
 */

typedef struct JinxZendForeachIterator {
    const JinxZendArray *array;
    size_t bucket_position;
    size_t live_position;
} JinxZendForeachIterator;

typedef struct JinxZendForeachEntry {
    int valid;
    size_t live_position;
    const JinxZendBucket *bucket;
    JinxZendValue key;
    JinxZendValue value;
} JinxZendForeachEntry;

typedef int (*JinxZendForeachBody)(
    JinxZendExecutor *executor,
    const JinxZendForeachEntry *entry,
    void *user_data
);

static inline void jinx_zend_foreach_init(JinxZendForeachIterator *it, const JinxZendArray *array) {
    if (it == 0) {
        return;
    }

    it->array = array;
    it->bucket_position = 0u;
    it->live_position = 0u;
}

static inline JinxZendValue jinx_zend_foreach_bucket_key(const JinxZendBucket *bucket) {
    if (bucket == 0) {
        return jinx_zend_null();
    }

    if (bucket->key != 0) {
        return jinx_zend_string_value(bucket->key);
    }

    return jinx_zend_long((int64_t)bucket->h);
}

static inline JinxZendForeachEntry jinx_zend_foreach_next(JinxZendForeachIterator *it) {
    JinxZendForeachEntry entry;

    entry.valid = 0;
    entry.live_position = 0u;
    entry.bucket = 0;
    entry.key = jinx_zend_null();
    entry.value = jinx_zend_null();

    if (it == 0 || it->array == 0 || it->array->buckets == 0) {
        return entry;
    }

    while (it->bucket_position < it->array->count) {
        const JinxZendBucket *bucket = &it->array->buckets[it->bucket_position++];
        if (jinx_zend_bucket_is_tombstone(bucket)) {
            continue;
        }

        entry.valid = 1;
        entry.live_position = it->live_position++;
        entry.bucket = bucket;
        entry.key = jinx_zend_foreach_bucket_key(bucket);
        entry.value = bucket->value;
        return entry;
    }

    return entry;
}

static inline size_t jinx_zend_foreach_execute(
    JinxZendExecutor *executor,
    const JinxZendArray *array,
    JinxZendForeachBody body,
    void *user_data
) {
    JinxZendForeachIterator it;
    size_t executed = 0u;

    if (executor == 0 || body == 0) {
        return 0u;
    }

    jinx_zend_foreach_init(&it, array);
    for (;;) {
        JinxZendForeachEntry entry = jinx_zend_foreach_next(&it);
        if (!entry.valid) {
            break;
        }

        executor->executed_ops++;
        executed++;
        if (!body(executor, &entry, user_data)) {
            break;
        }
    }

    return executed;
}

#endif /* JINX_ZEND_FOREACH_H */
