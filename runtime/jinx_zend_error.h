#ifndef JINX_ZEND_ERROR_H
#define JINX_ZEND_ERROR_H

#include "jinx_zend_object.h"

#include <stdint.h>
#include <string.h>

/*
 * Native error / warning / throwable helpers for the JINX-owned Zend-shaped runtime.
 *
 * This layer is intentionally additive. It records executor diagnostics and
 * represents thrown exceptions/errors as ordinary JinxZendObject instances
 * carrying PHP-style properties: message, code, file, and line.
 */

typedef enum JinxZendErrorLevel {
    JINX_ZEND_E_NONE = 0,
    JINX_ZEND_E_NOTICE = 1,
    JINX_ZEND_E_WARNING = 2,
    JINX_ZEND_E_RECOVERABLE_ERROR = 4,
    JINX_ZEND_E_ERROR = 8,
    JINX_ZEND_E_EXCEPTION = 16
} JinxZendErrorLevel;

typedef struct JinxZendThrowable {
    JinxZendObject *object;
    const char *class_name;
    const char *message;
    int64_t code;
    const char *file;
    uint32_t line;
} JinxZendThrowable;

typedef struct JinxZendErrorState {
    JinxZendErrorLevel level;
    const char *message;
    const char *file;
    uint32_t line;
    JinxZendObject *throwable;
} JinxZendErrorState;

static inline void jinx_zend_error_state_init(JinxZendErrorState *state) {
    if (state == 0) {
        return;
    }
    state->level = JINX_ZEND_E_NONE;
    state->message = 0;
    state->file = 0;
    state->line = 0u;
    state->throwable = 0;
}

static inline int jinx_zend_error_state_has_error(const JinxZendErrorState *state) {
    return state != 0 && state->level != JINX_ZEND_E_NONE;
}

static inline int jinx_zend_error_state_has_throwable(const JinxZendErrorState *state) {
    return state != 0 && state->throwable != 0;
}

static inline void jinx_zend_executor_clear_error(JinxZendExecutor *executor) {
    if (executor == 0) {
        return;
    }
    executor->last_error = 0;
    executor->error_level = 0u;
}

static inline void jinx_zend_executor_raise(
    JinxZendExecutor *executor,
    JinxZendErrorLevel level,
    const char *message
) {
    if (executor == 0) {
        return;
    }
    executor->last_error = message;
    executor->error_level = (uint32_t)level;
}

static inline void jinx_zend_error_state_raise(
    JinxZendErrorState *state,
    JinxZendExecutor *executor,
    JinxZendErrorLevel level,
    const char *message,
    const char *file,
    uint32_t line
) {
    if (state != 0) {
        state->level = level;
        state->message = message;
        state->file = file;
        state->line = line;
        state->throwable = 0;
    }
    jinx_zend_executor_raise(executor, level, message);
}

static inline const char *jinx_zend_error_level_name(JinxZendErrorLevel level) {
    switch (level) {
        case JINX_ZEND_E_NOTICE:
            return "notice";
        case JINX_ZEND_E_WARNING:
            return "warning";
        case JINX_ZEND_E_RECOVERABLE_ERROR:
            return "recoverable_error";
        case JINX_ZEND_E_ERROR:
            return "error";
        case JINX_ZEND_E_EXCEPTION:
            return "exception";
        default:
            return "none";
    }
}

static inline JinxZendClassEntry jinx_zend_throwable_class_entry(const char *class_name) {
    JinxZendClassEntry ce;
    ce.name = class_name;
    ce.methods = 0;
    ce.method_count = 0u;
    return ce;
}

static inline JinxZendObject *jinx_zend_throwable_new(
    const JinxZendClassEntry *ce,
    const char *message,
    int64_t code,
    const char *file,
    uint32_t line
) {
    JinxZendObject *object;

    if (ce == 0 || ce->name == 0) {
        return 0;
    }

    object = jinx_zend_object_new(ce);
    if (object == 0) {
        return 0;
    }

    if (message != 0) {
        jinx_zend_object_set_property(object, "message", jinx_zend_string_value(jinx_zend_string_new(message, strlen(message))));
    }
    jinx_zend_object_set_property(object, "code", jinx_zend_long(code));
    if (file != 0) {
        jinx_zend_object_set_property(object, "file", jinx_zend_string_value(jinx_zend_string_new(file, strlen(file))));
    }
    jinx_zend_object_set_property(object, "line", jinx_zend_long((int64_t)line));

    return object;
}

static inline JinxZendThrowable jinx_zend_throwable_describe(JinxZendObject *object) {
    JinxZendThrowable throwable;
    JinxZendValue *value;

    throwable.object = object;
    throwable.class_name = object != 0 ? object->class_name : 0;
    throwable.message = 0;
    throwable.code = 0;
    throwable.file = 0;
    throwable.line = 0u;

    if (object == 0) {
        return throwable;
    }

    value = jinx_zend_object_get_property(object, "message");
    if (value != 0 && value->type == JINX_ZEND_STRING && value->value.str != 0) {
        throwable.message = value->value.str->bytes;
    }

    value = jinx_zend_object_get_property(object, "code");
    if (value != 0 && value->type == JINX_ZEND_LONG) {
        throwable.code = value->value.lval;
    }

    value = jinx_zend_object_get_property(object, "file");
    if (value != 0 && value->type == JINX_ZEND_STRING && value->value.str != 0) {
        throwable.file = value->value.str->bytes;
    }

    value = jinx_zend_object_get_property(object, "line");
    if (value != 0 && value->type == JINX_ZEND_LONG && value->value.lval >= 0) {
        throwable.line = (uint32_t)value->value.lval;
    }

    return throwable;
}

static inline void jinx_zend_error_state_throw(
    JinxZendErrorState *state,
    JinxZendExecutor *executor,
    JinxZendObject *throwable,
    const char *file,
    uint32_t line
) {
    JinxZendThrowable described = jinx_zend_throwable_describe(throwable);
    const char *message = described.message != 0 ? described.message : "uncaught throwable";

    if (state != 0) {
        state->level = JINX_ZEND_E_EXCEPTION;
        state->message = message;
        state->file = file != 0 ? file : described.file;
        state->line = line != 0u ? line : described.line;
        state->throwable = throwable;
    }
    jinx_zend_executor_raise(executor, JINX_ZEND_E_EXCEPTION, message);
}

#endif /* JINX_ZEND_ERROR_H */
