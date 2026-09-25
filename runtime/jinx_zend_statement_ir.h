#ifndef JINX_ZEND_STATEMENT_IR_H
#define JINX_ZEND_STATEMENT_IR_H

#include "jinx_zend_ir_fixture.h"

#include <ctype.h>
#include <stdlib.h>
#include <string.h>

/*
 * Tiny statement/expression IR lowering for the combined Zend opcode VM.
 *
 * This is still intentionally small. It accepts line-oriented IR that a future
 * PHP parser/AST pass can emit, then lowers each statement into JinxZendVmOp.
 * Labels are resolved after parsing so control flow can be expressed without
 * hard-coded instruction offsets.
 *
 * Supported forms:
 *   label <name>
 *   const <dst-reg> <value-slot>
 *   call <dst-reg> <object-reg> <method> [arg-start] [argc]
 *   throw <src-reg> [file]
 *   catch <dst-reg> <class-or-*> <miss-label>
 *   clear
 *   jump <label>
 *   jump_if_exception <label>
 *   halt
 *
 * Value slots are supplied by the caller in order, so `const 0 1` loads
 * values[1] into register 0. This keeps runtime value construction separate
 * from syntax lowering.
 */

#define JINX_ZEND_STATEMENT_IR_MAX_LABELS 32u
#define JINX_ZEND_STATEMENT_IR_MAX_NAME 64u

typedef enum JinxZendStatementIrResult {
    JINX_ZEND_STATEMENT_IR_OK = 0,
    JINX_ZEND_STATEMENT_IR_PARSE_ERROR = 1,
    JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS = 2,
    JINX_ZEND_STATEMENT_IR_TOO_MANY_LABELS = 3,
    JINX_ZEND_STATEMENT_IR_UNKNOWN_LABEL = 4,
    JINX_ZEND_STATEMENT_IR_BAD_VALUE_SLOT = 5
} JinxZendStatementIrResult;

typedef struct JinxZendStatementIrLabel {
    char name[JINX_ZEND_STATEMENT_IR_MAX_NAME];
    size_t op_index;
} JinxZendStatementIrLabel;

typedef struct JinxZendStatementIrPatch {
    size_t op_index;
    char label[JINX_ZEND_STATEMENT_IR_MAX_NAME];
} JinxZendStatementIrPatch;

typedef struct JinxZendStatementIrProgram {
    JinxZendVmOp *ops;
    size_t op_count;
    size_t op_capacity;
    JinxZendStatementIrLabel labels[JINX_ZEND_STATEMENT_IR_MAX_LABELS];
    size_t label_count;
    JinxZendStatementIrPatch patches[JINX_ZEND_STATEMENT_IR_MAX_LABELS];
    size_t patch_count;
    char names[JINX_ZEND_STATEMENT_IR_MAX_LABELS][JINX_ZEND_STATEMENT_IR_MAX_NAME];
    size_t name_count;
} JinxZendStatementIrProgram;

static inline void jinx_zend_statement_ir_program_init(
    JinxZendStatementIrProgram *program,
    JinxZendVmOp *ops,
    size_t op_capacity
) {
    if (program == 0) {
        return;
    }
    program->ops = ops;
    program->op_count = 0u;
    program->op_capacity = op_capacity;
    program->label_count = 0u;
    program->patch_count = 0u;
    program->name_count = 0u;
}

static inline const char *jinx_zend_statement_ir_skip_ws(const char *text) {
    while (text != 0 && (*text == ' ' || *text == '\t' || *text == '\r')) {
        text++;
    }
    return text;
}

static inline size_t jinx_zend_statement_ir_token_len(const char *text) {
    size_t len = 0u;
    if (text == 0) {
        return 0u;
    }
    while (text[len] != '\0' && text[len] != ' ' && text[len] != '\t' && text[len] != '\r' && text[len] != '\n') {
        len++;
    }
    return len;
}

static inline int jinx_zend_statement_ir_token_is(const char *token, size_t len, const char *expected) {
    size_t expected_len = expected != 0 ? strlen(expected) : 0u;
    return token != 0 && expected != 0 && len == expected_len && strncmp(token, expected, len) == 0;
}

static inline int jinx_zend_statement_ir_copy_name(char *dst, const char *src, size_t len) {
    if (dst == 0 || src == 0 || len == 0u || len >= JINX_ZEND_STATEMENT_IR_MAX_NAME) {
        return 0;
    }
    memcpy(dst, src, len);
    dst[len] = '\0';
    return 1;
}

static inline const char *jinx_zend_statement_ir_store_name(
    JinxZendStatementIrProgram *program,
    const char *src,
    size_t len
) {
    if (program == 0 || program->name_count >= JINX_ZEND_STATEMENT_IR_MAX_LABELS) {
        return 0;
    }
    if (!jinx_zend_statement_ir_copy_name(program->names[program->name_count], src, len)) {
        return 0;
    }
    return program->names[program->name_count++];
}

static inline int jinx_zend_statement_ir_parse_long(const char *token, size_t len, long *out) {
    char buffer[32];
    char *endptr;
    if (token == 0 || len == 0u || len >= sizeof(buffer) || out == 0) {
        return 0;
    }
    memcpy(buffer, token, len);
    buffer[len] = '\0';
    endptr = 0;
    *out = strtol(buffer, &endptr, 10);
    return endptr != buffer && *endptr == '\0';
}

static inline int jinx_zend_statement_ir_next_token(
    const char **cursor,
    const char **token,
    size_t *len
) {
    const char *start;
    if (cursor == 0 || *cursor == 0 || token == 0 || len == 0) {
        return 0;
    }
    start = jinx_zend_statement_ir_skip_ws(*cursor);
    *token = start;
    *len = jinx_zend_statement_ir_token_len(start);
    *cursor = start + *len;
    return *len != 0u;
}

static inline JinxZendVmOp *jinx_zend_statement_ir_emit(JinxZendStatementIrProgram *program) {
    JinxZendVmOp *op;
    if (program == 0 || program->ops == 0 || program->op_count >= program->op_capacity) {
        return 0;
    }
    op = &program->ops[program->op_count++];
    memset(op, 0, sizeof(*op));
    op->dst = -1;
    op->src = -1;
    op->key_dst = -1;
    op->value_dst = -1;
    op->arg_start = -1;
    return op;
}

static inline int jinx_zend_statement_ir_add_label(
    JinxZendStatementIrProgram *program,
    const char *name,
    size_t name_len
) {
    if (program == 0 || program->label_count >= JINX_ZEND_STATEMENT_IR_MAX_LABELS) {
        return 0;
    }
    if (!jinx_zend_statement_ir_copy_name(program->labels[program->label_count].name, name, name_len)) {
        return 0;
    }
    program->labels[program->label_count].op_index = program->op_count;
    program->label_count++;
    return 1;
}

static inline int jinx_zend_statement_ir_add_patch(
    JinxZendStatementIrProgram *program,
    size_t op_index,
    const char *label,
    size_t label_len
) {
    if (program == 0 || program->patch_count >= JINX_ZEND_STATEMENT_IR_MAX_LABELS) {
        return 0;
    }
    program->patches[program->patch_count].op_index = op_index;
    if (!jinx_zend_statement_ir_copy_name(program->patches[program->patch_count].label, label, label_len)) {
        return 0;
    }
    program->patch_count++;
    return 1;
}

static inline int jinx_zend_statement_ir_find_label(
    const JinxZendStatementIrProgram *program,
    const char *name,
    size_t *target_out
) {
    if (program == 0 || name == 0 || target_out == 0) {
        return 0;
    }
    for (size_t i = 0; i < program->label_count; i++) {
        if (strcmp(program->labels[i].name, name) == 0) {
            *target_out = program->labels[i].op_index;
            return 1;
        }
    }
    return 0;
}

static inline JinxZendStatementIrResult jinx_zend_statement_ir_resolve_patches(JinxZendStatementIrProgram *program) {
    if (program == 0) {
        return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
    }
    for (size_t i = 0; i < program->patch_count; i++) {
        size_t target = 0u;
        if (!jinx_zend_statement_ir_find_label(program, program->patches[i].label, &target)) {
            return JINX_ZEND_STATEMENT_IR_UNKNOWN_LABEL;
        }
        program->ops[program->patches[i].op_index].target = target;
    }
    return JINX_ZEND_STATEMENT_IR_OK;
}

static inline JinxZendStatementIrResult jinx_zend_statement_ir_lower_line(
    JinxZendStatementIrProgram *program,
    const char *line,
    const JinxZendValue *values,
    size_t value_count
) {
    const char *cursor = line;
    const char *op_token;
    size_t op_len;
    JinxZendVmOp *op;
    long a = 0;
    long b = 0;
    long c = 0;
    const char *tok;
    size_t tok_len;

    if (!jinx_zend_statement_ir_next_token(&cursor, &op_token, &op_len)) {
        return JINX_ZEND_STATEMENT_IR_OK;
    }
    if (op_token[0] == '#') {
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "label")) {
        if (!jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) ||
            !jinx_zend_statement_ir_add_label(program, tok, tok_len)) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "const")) {
        if (!jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) || !jinx_zend_statement_ir_parse_long(tok, tok_len, &a) ||
            !jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) || !jinx_zend_statement_ir_parse_long(tok, tok_len, &b)) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        if (b < 0 || (size_t)b >= value_count) {
            return JINX_ZEND_STATEMENT_IR_BAD_VALUE_SLOT;
        }
        op = jinx_zend_statement_ir_emit(program);
        if (op == 0) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS;
        }
        op->op = JINX_ZEND_VM_LOAD_CONST;
        op->dst = (int)a;
        op->value = values[b];
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "call")) {
        const char *method;
        size_t method_len;
        if (!jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) || !jinx_zend_statement_ir_parse_long(tok, tok_len, &a) ||
            !jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) || !jinx_zend_statement_ir_parse_long(tok, tok_len, &b) ||
            !jinx_zend_statement_ir_next_token(&cursor, &method, &method_len)) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        c = 0;
        long argc = 0;
        if (jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len)) {
            if (!jinx_zend_statement_ir_parse_long(tok, tok_len, &c)) {
                return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
            }
            if (jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) && !jinx_zend_statement_ir_parse_long(tok, tok_len, &argc)) {
                return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
            }
        }
        op = jinx_zend_statement_ir_emit(program);
        if (op == 0) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS;
        }
        op->op = JINX_ZEND_VM_METHOD_CALL;
        op->dst = (int)a;
        op->src = (int)b;
        op->arg_start = (int)c;
        op->argc = (size_t)argc;
        op->name = jinx_zend_statement_ir_store_name(program, method, method_len);
        return op->name != 0 ? JINX_ZEND_STATEMENT_IR_OK : JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "throw")) {
        const char *file = 0;
        size_t file_len = 0u;
        if (!jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) || !jinx_zend_statement_ir_parse_long(tok, tok_len, &a)) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        if (jinx_zend_statement_ir_next_token(&cursor, &file, &file_len)) {
            /* optional */
        }
        op = jinx_zend_statement_ir_emit(program);
        if (op == 0) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS;
        }
        op->op = JINX_ZEND_VM_THROW;
        op->src = (int)a;
        op->name = file_len != 0u ? jinx_zend_statement_ir_store_name(program, file, file_len) : 0;
        if (file_len != 0u && op->name == 0) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "catch")) {
        const char *klass;
        size_t klass_len;
        const char *miss;
        size_t miss_len;
        if (!jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) || !jinx_zend_statement_ir_parse_long(tok, tok_len, &a) ||
            !jinx_zend_statement_ir_next_token(&cursor, &klass, &klass_len) ||
            !jinx_zend_statement_ir_next_token(&cursor, &miss, &miss_len)) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        op = jinx_zend_statement_ir_emit(program);
        if (op == 0) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS;
        }
        op->op = JINX_ZEND_VM_CATCH;
        op->dst = (int)a;
        op->name = (klass_len == 1u && klass[0] == '*') ? 0 : jinx_zend_statement_ir_store_name(program, klass, klass_len);
        if (!(klass_len == 1u && klass[0] == '*') && op->name == 0) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        if (!jinx_zend_statement_ir_add_patch(program, program->op_count - 1u, miss, miss_len)) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_LABELS;
        }
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "clear")) {
        op = jinx_zend_statement_ir_emit(program);
        if (op == 0) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS;
        }
        op->op = JINX_ZEND_VM_CLEAR_EXCEPTION;
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "jump") ||
        jinx_zend_statement_ir_token_is(op_token, op_len, "jump_if_exception")) {
        if (!jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len)) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        op = jinx_zend_statement_ir_emit(program);
        if (op == 0) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS;
        }
        op->op = jinx_zend_statement_ir_token_is(op_token, op_len, "jump") ? JINX_ZEND_VM_JMP : JINX_ZEND_VM_JMP_IF_EXCEPTION;
        if (!jinx_zend_statement_ir_add_patch(program, program->op_count - 1u, tok, tok_len)) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_LABELS;
        }
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "halt")) {
        op = jinx_zend_statement_ir_emit(program);
        if (op == 0) {
            return JINX_ZEND_STATEMENT_IR_TOO_MANY_OPS;
        }
        op->op = JINX_ZEND_VM_HALT;
        return JINX_ZEND_STATEMENT_IR_OK;
    }

    return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
}

static inline JinxZendStatementIrResult jinx_zend_statement_ir_lower(
    const char *text,
    JinxZendStatementIrProgram *program,
    const JinxZendValue *values,
    size_t value_count
) {
    const char *line;
    const char *cursor = text;
    char buffer[256];

    if (text == 0 || program == 0) {
        return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
    }

    while (*cursor != '\0') {
        size_t len = 0u;
        line = cursor;
        while (cursor[len] != '\0' && cursor[len] != '\n') {
            len++;
        }
        if (len >= sizeof(buffer)) {
            return JINX_ZEND_STATEMENT_IR_PARSE_ERROR;
        }
        memcpy(buffer, line, len);
        buffer[len] = '\0';
        {
            JinxZendStatementIrResult result = jinx_zend_statement_ir_lower_line(program, buffer, values, value_count);
            if (result != JINX_ZEND_STATEMENT_IR_OK) {
                return result;
            }
        }
        cursor += len;
        if (*cursor == '\n') {
            cursor++;
        }
    }

    return jinx_zend_statement_ir_resolve_patches(program);
}

#endif /* JINX_ZEND_STATEMENT_IR_H */
