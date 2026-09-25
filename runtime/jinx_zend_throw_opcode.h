#ifndef JINX_ZEND_THROW_OPCODE_H
#define JINX_ZEND_THROW_OPCODE_H

#include "jinx_zend_error.h"

/*
 * Minimal throw/catch opcode lowering.
 *
 * This layer maps PHP-style throw/catch control flow onto JinxZendErrorState:
 *   THROW stores a throwable object in the active error state.
 *   CATCH tests whether the active throwable matches a requested class name.
 *   CLEAR_EXCEPTION clears the active exception after a catch body consumes it.
 *
 * Throwable lifetime is non-owning here, matching JinxZendErrorState. The
 * caller that allocated the object still owns/release it.
 */

typedef enum JinxZendThrowOpcodeResult {
    JINX_ZEND_THROW_OK = 0,
    JINX_ZEND_THROW_TYPE_ERROR = 1,
    JINX_ZEND_CATCH_MATCH = 2,
    JINX_ZEND_CATCH_MISS = 3,
    JINX_ZEND_CATCH_EMPTY = 4
} JinxZendThrowOpcodeResult;

typedef struct JinxZendCatchFrame {
    const char *catch_class_name;
    JinxZendObject *caught;
    int matched;
} JinxZendCatchFrame;

static inline void jinx_zend_catch_frame_init(JinxZendCatchFrame *frame, const char *catch_class_name) {
    if (frame == 0) {
        return;
    }
    frame->catch_class_name = catch_class_name;
    frame->caught = 0;
    frame->matched = 0;
}

static inline JinxZendThrowOpcodeResult jinx_zend_throw_value(
    JinxZendErrorState *state,
    JinxZendExecutor *executor,
    JinxZendValue throwable_value,
    const char *file,
    uint32_t line
) {
    if (throwable_value.type != JINX_ZEND_OBJECT || throwable_value.value.object == 0) {
        jinx_zend_error_state_raise(
            state,
            executor,
            JINX_ZEND_E_ERROR,
            "throw expects object",
            file,
            line
        );
        return JINX_ZEND_THROW_TYPE_ERROR;
    }

    jinx_zend_error_state_throw(state, executor, throwable_value.value.object, file, line);
    if (executor != 0) {
        executor->executed_ops++;
    }
    return JINX_ZEND_THROW_OK;
}

static inline JinxZendThrowOpcodeResult jinx_zend_catch_exception(
    JinxZendErrorState *state,
    JinxZendCatchFrame *frame
) {
    JinxZendObject *throwable;

    if (state == 0 || frame == 0 || !jinx_zend_error_state_has_throwable(state)) {
        return JINX_ZEND_CATCH_EMPTY;
    }

    throwable = state->throwable;
    if (frame->catch_class_name == 0 ||
        (throwable != 0 && throwable->class_name != 0 && strcmp(throwable->class_name, frame->catch_class_name) == 0)) {
        frame->caught = throwable;
        frame->matched = 1;
        return JINX_ZEND_CATCH_MATCH;
    }

    frame->caught = 0;
    frame->matched = 0;
    return JINX_ZEND_CATCH_MISS;
}

static inline void jinx_zend_clear_exception(
    JinxZendErrorState *state,
    JinxZendExecutor *executor
) {
    if (state != 0) {
        state->level = JINX_ZEND_E_NONE;
        state->message = 0;
        state->file = 0;
        state->line = 0u;
        state->throwable = 0;
    }
    jinx_zend_executor_clear_error(executor);
}

#endif /* JINX_ZEND_THROW_OPCODE_H */
