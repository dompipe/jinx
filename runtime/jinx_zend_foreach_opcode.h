#ifndef JINX_ZEND_FOREACH_OPCODE_H
#define JINX_ZEND_FOREACH_OPCODE_H

#include "jinx_zend_foreach.h"

/*
 * Minimal foreach opcode/IR primitives.
 *
 * These model the Zend VM shape without requiring the full compiler/opcode
 * pipeline yet:
 *   FE_RESET prepares an iterator over a Zend array value.
 *   FE_FETCH advances to the next live bucket and exposes key/value slots.
 *
 * The state intentionally sits above JinxZendForeachIterator so later opcode
 * dispatch can map PHP foreach directly to these primitives.
 */

typedef enum JinxZendForeachOpcodeResult {
    JINX_ZEND_FE_OK = 0,
    JINX_ZEND_FE_DONE = 1,
    JINX_ZEND_FE_TYPE_ERROR = 2
} JinxZendForeachOpcodeResult;

typedef struct JinxZendForeachFrame {
    JinxZendForeachIterator iterator;
    JinxZendForeachEntry current;
    JinxZendValue source;
    JinxZendValue key_slot;
    JinxZendValue value_slot;
    int initialized;
    int done;
} JinxZendForeachFrame;

static inline void jinx_zend_fe_frame_init(JinxZendForeachFrame *frame) {
    if (frame == 0) {
        return;
    }

    frame->source = jinx_zend_null();
    frame->key_slot = jinx_zend_null();
    frame->value_slot = jinx_zend_null();
    frame->current.valid = 0;
    frame->current.live_position = 0u;
    frame->current.bucket = 0;
    frame->current.key = jinx_zend_null();
    frame->current.value = jinx_zend_null();
    frame->iterator.array = 0;
    frame->iterator.bucket_position = 0u;
    frame->iterator.live_position = 0u;
    frame->initialized = 0;
    frame->done = 0;
}

static inline JinxZendForeachOpcodeResult jinx_zend_fe_reset(
    JinxZendForeachFrame *frame,
    JinxZendValue source
) {
    if (frame == 0) {
        return JINX_ZEND_FE_TYPE_ERROR;
    }

    jinx_zend_fe_frame_init(frame);
    frame->source = source;

    if (source.type != JINX_ZEND_ARRAY || source.value.array == 0) {
        frame->done = 1;
        return JINX_ZEND_FE_TYPE_ERROR;
    }

    jinx_zend_foreach_init(&frame->iterator, source.value.array);
    frame->initialized = 1;
    return JINX_ZEND_FE_OK;
}

static inline JinxZendForeachOpcodeResult jinx_zend_fe_fetch(
    JinxZendForeachFrame *frame,
    JinxZendValue *key_out,
    JinxZendValue *value_out
) {
    JinxZendForeachEntry entry;

    if (frame == 0 || !frame->initialized || frame->done) {
        return JINX_ZEND_FE_DONE;
    }

    entry = jinx_zend_foreach_next(&frame->iterator);
    if (!entry.valid) {
        frame->done = 1;
        frame->current = entry;
        return JINX_ZEND_FE_DONE;
    }

    frame->current = entry;
    frame->key_slot = entry.key;
    frame->value_slot = entry.value;

    if (key_out != 0) {
        *key_out = frame->key_slot;
    }
    if (value_out != 0) {
        *value_out = frame->value_slot;
    }

    return JINX_ZEND_FE_OK;
}

static inline size_t jinx_zend_fe_run_all(
    JinxZendExecutor *executor,
    JinxZendForeachFrame *frame,
    JinxZendForeachBody body,
    void *user_data
) {
    size_t executed = 0u;

    if (executor == 0 || frame == 0 || body == 0) {
        return 0u;
    }

    for (;;) {
        JinxZendValue key;
        JinxZendValue value;
        JinxZendForeachOpcodeResult result = jinx_zend_fe_fetch(frame, &key, &value);
        if (result != JINX_ZEND_FE_OK) {
            break;
        }

        executor->executed_ops++;
        executed++;
        if (!body(executor, &frame->current, user_data)) {
            break;
        }
    }

    return executed;
}

#endif /* JINX_ZEND_FOREACH_OPCODE_H */
