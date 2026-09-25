#ifndef JINX_ZEND_METHOD_OPCODE_H
#define JINX_ZEND_METHOD_OPCODE_H

#include "jinx_zend_object.h"

/*
 * Minimal method-call opcode/IR primitives.
 *
 * This layer maps an object zval + method name to the native class table and
 * method handler dispatch. It keeps method-call lowering separate from the
 * class table so later opcode/IR runners can sequence INIT_METHOD_CALL,
 * SEND_VAL, and DO_FCALL-style behavior on top of the same call primitive.
 */

typedef enum JinxZendMethodOpcodeResult {
    JINX_ZEND_METHOD_OK = 0,
    JINX_ZEND_METHOD_NOT_OBJECT = 1,
    JINX_ZEND_METHOD_NOT_FOUND = 2,
    JINX_ZEND_METHOD_INVALID = 3
} JinxZendMethodOpcodeResult;

typedef struct JinxZendMethodCallFrame {
    JinxZendValue object_value;
    const char *method_name;
    JinxZendValue *args;
    size_t argc;
    JinxZendValue return_value;
    JinxZendMethodOpcodeResult result;
    int initialized;
} JinxZendMethodCallFrame;

static inline void jinx_zend_method_frame_init(JinxZendMethodCallFrame *frame) {
    if (frame == 0) {
        return;
    }

    frame->object_value = jinx_zend_null();
    frame->method_name = 0;
    frame->args = 0;
    frame->argc = 0u;
    frame->return_value = jinx_zend_null();
    frame->result = JINX_ZEND_METHOD_INVALID;
    frame->initialized = 0;
}

static inline JinxZendMethodOpcodeResult jinx_zend_init_method_call(
    JinxZendMethodCallFrame *frame,
    JinxZendValue object_value,
    const char *method_name
) {
    if (frame == 0 || method_name == 0) {
        return JINX_ZEND_METHOD_INVALID;
    }

    jinx_zend_method_frame_init(frame);
    frame->object_value = object_value;
    frame->method_name = method_name;

    if (object_value.type != JINX_ZEND_OBJECT || object_value.value.object == 0) {
        frame->result = JINX_ZEND_METHOD_NOT_OBJECT;
        return frame->result;
    }

    frame->initialized = 1;
    frame->result = JINX_ZEND_METHOD_OK;
    return frame->result;
}

static inline JinxZendMethodOpcodeResult jinx_zend_send_method_args(
    JinxZendMethodCallFrame *frame,
    JinxZendValue *args,
    size_t argc
) {
    if (frame == 0 || !frame->initialized) {
        return JINX_ZEND_METHOD_INVALID;
    }

    frame->args = args;
    frame->argc = argc;
    frame->result = JINX_ZEND_METHOD_OK;
    return frame->result;
}

static inline JinxZendMethodOpcodeResult jinx_zend_do_method_call(
    JinxZendExecutor *executor,
    const JinxZendClassTable *table,
    JinxZendMethodCallFrame *frame,
    JinxZendValue *return_out
) {
    uint64_t before_ops;

    if (executor == 0 || table == 0 || frame == 0 || !frame->initialized) {
        if (executor != 0) {
            executor->last_error = "invalid method opcode call";
            executor->error_level = 1u;
        }
        return JINX_ZEND_METHOD_INVALID;
    }

    before_ops = executor->executed_ops;
    frame->return_value = jinx_zend_call_method(
        executor,
        table,
        frame->object_value.value.object,
        frame->method_name,
        frame->args,
        frame->argc
    );

    if (executor->executed_ops == before_ops) {
        frame->result = JINX_ZEND_METHOD_NOT_FOUND;
        if (return_out != 0) {
            *return_out = frame->return_value;
        }
        return frame->result;
    }

    frame->result = JINX_ZEND_METHOD_OK;
    if (return_out != 0) {
        *return_out = frame->return_value;
    }
    return frame->result;
}

#endif /* JINX_ZEND_METHOD_OPCODE_H */
