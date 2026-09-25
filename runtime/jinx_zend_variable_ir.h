#ifndef JINX_ZEND_VARIABLE_IR_H
#define JINX_ZEND_VARIABLE_IR_H

#include "jinx_zend_statement_ir.h"

/*
 * Variable-slot IR lowering for the combined Zend opcode VM.
 *
 * This layer moves beyond raw register numbers. It maps variable names onto VM
 * registers, then lowers assignment-like and scalar expression forms into VM
 * instructions:
 *
 *   var <name> <reg>
 *   load <name> <value-slot>
 *   set <dst-name> <src-name>
 *   addv|subv|eqv|ltv <dst-name> <left-name> <right-name>
 *   callv <dst-name> <object-name> <method> [arg-name]
 *   throwv <name> [file]
 *   catchv <name> <class-or-*> <miss-label>
 *   jump_if_truev|jump_if_falsev <name> <label>
 *   clear
 *   jump <label>
 *   jump_if_exception <label>
 *   halt
 */

#define JINX_ZEND_VARIABLE_IR_MAX_VARS 32u
#define JINX_ZEND_VARIABLE_IR_MAX_NAME 64u

typedef enum JinxZendVariableIrResult {
    JINX_ZEND_VARIABLE_IR_OK = 0,
    JINX_ZEND_VARIABLE_IR_PARSE_ERROR = 1,
    JINX_ZEND_VARIABLE_IR_TOO_MANY_VARS = 2,
    JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR = 3,
    JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR = 4
} JinxZendVariableIrResult;

typedef struct JinxZendVariableIrSlot {
    char name[JINX_ZEND_VARIABLE_IR_MAX_NAME];
    int reg;
} JinxZendVariableIrSlot;

typedef struct JinxZendVariableIrProgram {
    JinxZendStatementIrProgram statement;
    JinxZendVariableIrSlot vars[JINX_ZEND_VARIABLE_IR_MAX_VARS];
    size_t var_count;
} JinxZendVariableIrProgram;

static inline void jinx_zend_variable_ir_program_init(
    JinxZendVariableIrProgram *program,
    JinxZendVmOp *ops,
    size_t op_capacity
) {
    if (program == 0) {
        return;
    }
    jinx_zend_statement_ir_program_init(&program->statement, ops, op_capacity);
    program->var_count = 0u;
}

static inline int jinx_zend_variable_ir_name_copy(char *dst, const char *src, size_t len) {
    if (dst == 0 || src == 0 || len == 0u || len >= JINX_ZEND_VARIABLE_IR_MAX_NAME) {
        return 0;
    }
    memcpy(dst, src, len);
    dst[len] = '\0';
    return 1;
}

static inline int jinx_zend_variable_ir_define(
    JinxZendVariableIrProgram *program,
    const char *name,
    size_t name_len,
    int reg
) {
    if (program == 0 || name == 0 || reg < 0 || !jinx_zend_vm_reg_ok(reg)) {
        return 0;
    }

    for (size_t i = 0; i < program->var_count; i++) {
        if (strlen(program->vars[i].name) == name_len && strncmp(program->vars[i].name, name, name_len) == 0) {
            program->vars[i].reg = reg;
            return 1;
        }
    }

    if (program->var_count >= JINX_ZEND_VARIABLE_IR_MAX_VARS) {
        return 0;
    }
    if (!jinx_zend_variable_ir_name_copy(program->vars[program->var_count].name, name, name_len)) {
        return 0;
    }
    program->vars[program->var_count].reg = reg;
    program->var_count++;
    return 1;
}

static inline int jinx_zend_variable_ir_find(
    const JinxZendVariableIrProgram *program,
    const char *name,
    size_t name_len,
    int *reg_out
) {
    if (program == 0 || name == 0 || reg_out == 0) {
        return 0;
    }

    for (size_t i = 0; i < program->var_count; i++) {
        if (strlen(program->vars[i].name) == name_len && strncmp(program->vars[i].name, name, name_len) == 0) {
            *reg_out = program->vars[i].reg;
            return 1;
        }
    }

    return 0;
}

static inline JinxZendVariableIrResult jinx_zend_variable_ir_emit_copy(
    JinxZendVariableIrProgram *program,
    int dst,
    int src
) {
    JinxZendVmOp *op = jinx_zend_statement_ir_emit(&program->statement);
    if (op == 0) {
        return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
    }
    op->op = JINX_ZEND_VM_COPY;
    op->dst = dst;
    op->src = src;
    return JINX_ZEND_VARIABLE_IR_OK;
}

static inline JinxZendVariableIrResult jinx_zend_variable_ir_emit_binary(
    JinxZendVariableIrProgram *program,
    JinxZendVmOpcode opcode,
    int dst,
    int left,
    int right
) {
    JinxZendVmOp *op = jinx_zend_statement_ir_emit(&program->statement);
    if (op == 0) {
        return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
    }
    op->op = opcode;
    op->dst = dst;
    op->src = left;
    op->arg_start = right;
    return JINX_ZEND_VARIABLE_IR_OK;
}

static inline JinxZendVariableIrResult jinx_zend_variable_ir_lower_binary_line(
    JinxZendVariableIrProgram *program,
    const char *cursor,
    JinxZendVmOpcode opcode
) {
    const char *dst_name;
    const char *left_name;
    const char *right_name;
    size_t dst_len;
    size_t left_len;
    size_t right_len;
    int dst;
    int left;
    int right;

    if (!jinx_zend_statement_ir_next_token(&cursor, &dst_name, &dst_len) ||
        !jinx_zend_statement_ir_next_token(&cursor, &left_name, &left_len) ||
        !jinx_zend_statement_ir_next_token(&cursor, &right_name, &right_len) ||
        !jinx_zend_variable_ir_find(program, dst_name, dst_len, &dst) ||
        !jinx_zend_variable_ir_find(program, left_name, left_len, &left) ||
        !jinx_zend_variable_ir_find(program, right_name, right_len, &right)) {
        return JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR;
    }

    return jinx_zend_variable_ir_emit_binary(program, opcode, dst, left, right);
}

static inline JinxZendVariableIrResult jinx_zend_variable_ir_lower_bool_jump(
    JinxZendVariableIrProgram *program,
    const char *cursor,
    JinxZendVmOpcode opcode
) {
    const char *name;
    const char *label;
    size_t name_len;
    size_t label_len;
    int src;
    JinxZendVmOp *vmop;

    if (!jinx_zend_statement_ir_next_token(&cursor, &name, &name_len) ||
        !jinx_zend_statement_ir_next_token(&cursor, &label, &label_len) ||
        !jinx_zend_variable_ir_find(program, name, name_len, &src)) {
        return JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR;
    }

    vmop = jinx_zend_statement_ir_emit(&program->statement);
    if (vmop == 0) {
        return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
    }
    vmop->op = opcode;
    vmop->src = src;
    if (!jinx_zend_statement_ir_add_patch(&program->statement, program->statement.op_count - 1u, label, label_len)) {
        return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
    }
    return JINX_ZEND_VARIABLE_IR_OK;
}

static inline JinxZendVariableIrResult jinx_zend_variable_ir_lower_line(
    JinxZendVariableIrProgram *program,
    const char *line,
    const JinxZendValue *values,
    size_t value_count
) {
    const char *cursor = line;
    const char *op_token;
    size_t op_len;
    const char *tok;
    size_t tok_len;
    long number;
    int dst;
    int src;

    if (!jinx_zend_statement_ir_next_token(&cursor, &op_token, &op_len)) {
        return JINX_ZEND_VARIABLE_IR_OK;
    }
    if (op_token[0] == '#') {
        return JINX_ZEND_VARIABLE_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "var")) {
        const char *name;
        size_t name_len;
        if (!jinx_zend_statement_ir_next_token(&cursor, &name, &name_len) ||
            !jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) ||
            !jinx_zend_statement_ir_parse_long(tok, tok_len, &number)) {
            return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
        }
        return jinx_zend_variable_ir_define(program, name, name_len, (int)number)
            ? JINX_ZEND_VARIABLE_IR_OK
            : JINX_ZEND_VARIABLE_IR_TOO_MANY_VARS;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "load")) {
        const char *name;
        size_t name_len;
        JinxZendVmOp *vmop;
        if (!jinx_zend_statement_ir_next_token(&cursor, &name, &name_len) ||
            !jinx_zend_variable_ir_find(program, name, name_len, &dst) ||
            !jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len) ||
            !jinx_zend_statement_ir_parse_long(tok, tok_len, &number)) {
            return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
        }
        if (number < 0 || (size_t)number >= value_count) {
            return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
        }
        vmop = jinx_zend_statement_ir_emit(&program->statement);
        if (vmop == 0) {
            return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
        }
        vmop->op = JINX_ZEND_VM_LOAD_CONST;
        vmop->dst = dst;
        vmop->value = values[number];
        return JINX_ZEND_VARIABLE_IR_OK;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "set")) {
        const char *dst_name;
        size_t dst_len;
        const char *src_name;
        size_t src_len;
        if (!jinx_zend_statement_ir_next_token(&cursor, &dst_name, &dst_len) ||
            !jinx_zend_statement_ir_next_token(&cursor, &src_name, &src_len) ||
            !jinx_zend_variable_ir_find(program, dst_name, dst_len, &dst) ||
            !jinx_zend_variable_ir_find(program, src_name, src_len, &src)) {
            return JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR;
        }
        return jinx_zend_variable_ir_emit_copy(program, dst, src);
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "addv")) {
        return jinx_zend_variable_ir_lower_binary_line(program, cursor, JINX_ZEND_VM_ADD);
    }
    if (jinx_zend_statement_ir_token_is(op_token, op_len, "subv")) {
        return jinx_zend_variable_ir_lower_binary_line(program, cursor, JINX_ZEND_VM_SUB);
    }
    if (jinx_zend_statement_ir_token_is(op_token, op_len, "eqv")) {
        return jinx_zend_variable_ir_lower_binary_line(program, cursor, JINX_ZEND_VM_EQ);
    }
    if (jinx_zend_statement_ir_token_is(op_token, op_len, "ltv")) {
        return jinx_zend_variable_ir_lower_binary_line(program, cursor, JINX_ZEND_VM_LT);
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "jump_if_truev")) {
        return jinx_zend_variable_ir_lower_bool_jump(program, cursor, JINX_ZEND_VM_JMP_IF_TRUE);
    }
    if (jinx_zend_statement_ir_token_is(op_token, op_len, "jump_if_falsev")) {
        return jinx_zend_variable_ir_lower_bool_jump(program, cursor, JINX_ZEND_VM_JMP_IF_FALSE);
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "callv")) {
        const char *dst_name;
        size_t dst_len;
        const char *obj_name;
        size_t obj_len;
        const char *method;
        size_t method_len;
        int arg_reg = 0;
        size_t argc = 0u;
        JinxZendVmOp *vmop;

        if (!jinx_zend_statement_ir_next_token(&cursor, &dst_name, &dst_len) ||
            !jinx_zend_statement_ir_next_token(&cursor, &obj_name, &obj_len) ||
            !jinx_zend_statement_ir_next_token(&cursor, &method, &method_len) ||
            !jinx_zend_variable_ir_find(program, dst_name, dst_len, &dst) ||
            !jinx_zend_variable_ir_find(program, obj_name, obj_len, &src)) {
            return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
        }

        if (jinx_zend_statement_ir_next_token(&cursor, &tok, &tok_len)) {
            if (!jinx_zend_variable_ir_find(program, tok, tok_len, &arg_reg)) {
                return JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR;
            }
            argc = 1u;
        }

        vmop = jinx_zend_statement_ir_emit(&program->statement);
        if (vmop == 0) {
            return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
        }
        vmop->op = JINX_ZEND_VM_METHOD_CALL;
        vmop->dst = dst;
        vmop->src = src;
        vmop->arg_start = arg_reg;
        vmop->argc = argc;
        vmop->name = jinx_zend_statement_ir_store_name(&program->statement, method, method_len);
        return vmop->name != 0 ? JINX_ZEND_VARIABLE_IR_OK : JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "throwv")) {
        const char *name;
        size_t name_len;
        const char *file = 0;
        size_t file_len = 0u;
        JinxZendVmOp *vmop;
        if (!jinx_zend_statement_ir_next_token(&cursor, &name, &name_len) ||
            !jinx_zend_variable_ir_find(program, name, name_len, &src)) {
            return JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR;
        }
        if (jinx_zend_statement_ir_next_token(&cursor, &file, &file_len)) {
            /* optional */
        }
        vmop = jinx_zend_statement_ir_emit(&program->statement);
        if (vmop == 0) {
            return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
        }
        vmop->op = JINX_ZEND_VM_THROW;
        vmop->src = src;
        vmop->name = file_len != 0u ? jinx_zend_statement_ir_store_name(&program->statement, file, file_len) : 0;
        return (file_len == 0u || vmop->name != 0) ? JINX_ZEND_VARIABLE_IR_OK : JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
    }

    if (jinx_zend_statement_ir_token_is(op_token, op_len, "catchv")) {
        const char *name;
        size_t name_len;
        const char *klass;
        size_t klass_len;
        const char *miss;
        size_t miss_len;
        JinxZendVmOp *vmop;
        if (!jinx_zend_statement_ir_next_token(&cursor, &name, &name_len) ||
            !jinx_zend_variable_ir_find(program, name, name_len, &dst) ||
            !jinx_zend_statement_ir_next_token(&cursor, &klass, &klass_len) ||
            !jinx_zend_statement_ir_next_token(&cursor, &miss, &miss_len)) {
            return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
        }
        vmop = jinx_zend_statement_ir_emit(&program->statement);
        if (vmop == 0) {
            return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
        }
        vmop->op = JINX_ZEND_VM_CATCH;
        vmop->dst = dst;
        vmop->name = (klass_len == 1u && klass[0] == '*') ? 0 : jinx_zend_statement_ir_store_name(&program->statement, klass, klass_len);
        if (!(klass_len == 1u && klass[0] == '*') && vmop->name == 0) {
            return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
        }
        if (!jinx_zend_statement_ir_add_patch(&program->statement, program->statement.op_count - 1u, miss, miss_len)) {
            return JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
        }
        return JINX_ZEND_VARIABLE_IR_OK;
    }

    {
        JinxZendStatementIrResult result = jinx_zend_statement_ir_lower_line(&program->statement, line, values, value_count);
        return result == JINX_ZEND_STATEMENT_IR_OK ? JINX_ZEND_VARIABLE_IR_OK : JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
    }
}

static inline JinxZendVariableIrResult jinx_zend_variable_ir_lower(
    const char *text,
    JinxZendVariableIrProgram *program,
    const JinxZendValue *values,
    size_t value_count
) {
    const char *cursor = text;
    const char *line_start;
    char line[256];

    if (text == 0 || program == 0) {
        return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
    }

    while (*cursor != '\0') {
        size_t len = 0u;
        line_start = cursor;
        while (cursor[len] != '\0' && cursor[len] != '\n') {
            len++;
        }
        if (len >= sizeof(line)) {
            return JINX_ZEND_VARIABLE_IR_PARSE_ERROR;
        }
        memcpy(line, line_start, len);
        line[len] = '\0';
        {
            JinxZendVariableIrResult result = jinx_zend_variable_ir_lower_line(program, line, values, value_count);
            if (result != JINX_ZEND_VARIABLE_IR_OK) {
                return result;
            }
        }
        cursor = line_start + len;
        if (*cursor == '\n') {
            cursor++;
        }
    }

    return jinx_zend_statement_ir_resolve_patches(&program->statement) == JINX_ZEND_STATEMENT_IR_OK
        ? JINX_ZEND_VARIABLE_IR_OK
        : JINX_ZEND_VARIABLE_IR_STATEMENT_ERROR;
}

#endif /* JINX_ZEND_VARIABLE_IR_H */