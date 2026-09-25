#ifndef JINX_ZEND_IR_FIXTURE_H
#define JINX_ZEND_IR_FIXTURE_H

#include "jinx_zend_lowering_fixture.h"

#include <string.h>

/*
 * Tiny text IR fixture parser for PHP-shaped VM lowering.
 *
 * This is not PHP syntax yet. It is a stable, line-oriented bridge between a
 * future parser/AST pass and the already-proven JinxZendVmOp stream emitters.
 * Supported forms:
 *   foreach_method <method>
 *   throw_catch <catch-class> <throw-file>
 *
 * Runtime values still come from the caller, so the parser only owns the
 * control-flow/method/catch shape. That keeps data construction separate from
 * syntax lowering.
 */

typedef enum JinxZendIrFixtureKind {
    JINX_ZEND_IR_FIXTURE_INVALID = 0,
    JINX_ZEND_IR_FIXTURE_FOREACH_METHOD = 1,
    JINX_ZEND_IR_FIXTURE_THROW_CATCH = 2
} JinxZendIrFixtureKind;

typedef struct JinxZendIrFixture {
    JinxZendIrFixtureKind kind;
    const char *first;
    size_t first_len;
    const char *second;
    size_t second_len;
} JinxZendIrFixture;

static inline void jinx_zend_ir_fixture_init(JinxZendIrFixture *fixture) {
    if (fixture == 0) {
        return;
    }
    fixture->kind = JINX_ZEND_IR_FIXTURE_INVALID;
    fixture->first = 0;
    fixture->first_len = 0u;
    fixture->second = 0;
    fixture->second_len = 0u;
}

static inline const char *jinx_zend_ir_skip_spaces(const char *text) {
    while (text != 0 && (*text == ' ' || *text == '\t' || *text == '\r' || *text == '\n')) {
        text++;
    }
    return text;
}

static inline size_t jinx_zend_ir_token_len(const char *text) {
    size_t len = 0u;
    if (text == 0) {
        return 0u;
    }
    while (text[len] != '\0' && text[len] != ' ' && text[len] != '\t' && text[len] != '\r' && text[len] != '\n') {
        len++;
    }
    return len;
}

static inline int jinx_zend_ir_token_is(const char *token, size_t token_len, const char *expected) {
    size_t expected_len = expected != 0 ? strlen(expected) : 0u;
    return token != 0 && expected != 0 && token_len == expected_len && strncmp(token, expected, token_len) == 0;
}

static inline int jinx_zend_ir_fixture_parse(const char *text, JinxZendIrFixture *fixture) {
    const char *cursor;
    const char *op;
    size_t op_len;

    if (fixture == 0) {
        return 0;
    }
    jinx_zend_ir_fixture_init(fixture);

    cursor = jinx_zend_ir_skip_spaces(text);
    op = cursor;
    op_len = jinx_zend_ir_token_len(op);
    if (op_len == 0u) {
        return 0;
    }

    cursor = jinx_zend_ir_skip_spaces(op + op_len);
    fixture->first = cursor;
    fixture->first_len = jinx_zend_ir_token_len(cursor);
    cursor = jinx_zend_ir_skip_spaces(cursor + fixture->first_len);
    fixture->second = cursor;
    fixture->second_len = jinx_zend_ir_token_len(cursor);

    if (jinx_zend_ir_token_is(op, op_len, "foreach_method")) {
        if (fixture->first_len == 0u) {
            return 0;
        }
        fixture->kind = JINX_ZEND_IR_FIXTURE_FOREACH_METHOD;
        return 1;
    }

    if (jinx_zend_ir_token_is(op, op_len, "throw_catch")) {
        if (fixture->first_len == 0u || fixture->second_len == 0u) {
            return 0;
        }
        fixture->kind = JINX_ZEND_IR_FIXTURE_THROW_CATCH;
        return 1;
    }

    return 0;
}

static inline size_t jinx_zend_ir_copy_token(char *dst, size_t capacity, const char *src, size_t src_len) {
    size_t copied;
    if (dst == 0 || capacity == 0u || src == 0) {
        return 0u;
    }
    copied = src_len < capacity - 1u ? src_len : capacity - 1u;
    memcpy(dst, src, copied);
    dst[copied] = '\0';
    return copied;
}

static inline size_t jinx_zend_ir_fixture_lower(
    const JinxZendIrFixture *fixture,
    JinxZendVmOp *ops,
    size_t capacity,
    JinxZendValue first_value,
    JinxZendValue second_value,
    char *first_buffer,
    size_t first_buffer_capacity,
    char *second_buffer,
    size_t second_buffer_capacity
) {
    if (fixture == 0 || ops == 0) {
        return 0u;
    }

    if (fixture->kind == JINX_ZEND_IR_FIXTURE_FOREACH_METHOD) {
        jinx_zend_ir_copy_token(first_buffer, first_buffer_capacity, fixture->first, fixture->first_len);
        return jinx_zend_lower_foreach_method_fixture(ops, capacity, first_value, second_value, first_buffer);
    }

    if (fixture->kind == JINX_ZEND_IR_FIXTURE_THROW_CATCH) {
        jinx_zend_ir_copy_token(first_buffer, first_buffer_capacity, fixture->first, fixture->first_len);
        jinx_zend_ir_copy_token(second_buffer, second_buffer_capacity, fixture->second, fixture->second_len);
        return jinx_zend_lower_throw_catch_fixture(ops, capacity, first_value, first_buffer, second_buffer);
    }

    return 0u;
}

#endif /* JINX_ZEND_IR_FIXTURE_H */
