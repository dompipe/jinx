#include "../runtime/jinx_zend_variable_ir.h"

#include <stdio.h>

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendVmState state;
    JinxZendVmOp ops[32];
    JinxZendVariableIrProgram lowered;
    JinxZendValue values[3];
    JinxZendVmResult result;
    const char *program =
        "var a 0\n"
        "var b 1\n"
        "var sum 2\n"
        "var diff 3\n"
        "var is_equal 4\n"
        "var is_less 5\n"
        "var marker 6\n"
        "load a 0\n"
        "load b 1\n"
        "load marker 2\n"
        "addv sum a b\n"
        "subv diff sum b\n"
        "eqv is_equal diff a\n"
        "ltv is_less b sum\n"
        "jump_if_truev is_less good\n"
        "load marker 1\n"
        "halt\n"
        "label good\n"
        "jump_if_falsev is_equal miss\n"
        "halt\n"
        "label miss\n"
        "load marker 1\n"
        "halt\n";

    values[0] = jinx_zend_long(7);
    values[1] = jinx_zend_long(5);
    values[2] = jinx_zend_long(123);

    jinx_zend_executor_init(&executor);
    jinx_zend_vm_state_init(&state, 0);
    jinx_zend_variable_ir_program_init(&lowered, ops, sizeof(ops) / sizeof(ops[0]));

    if (jinx_zend_variable_ir_lower(program, &lowered, values, 3u) != JINX_ZEND_VARIABLE_IR_OK) {
        return fail("variable scalar IR lowering failed");
    }

    result = jinx_zend_vm_run(&executor, &state, lowered.statement.ops, lowered.statement.op_count);
    if (result != JINX_ZEND_VM_HALTED) {
        return fail("variable scalar IR VM did not halt");
    }

    if (state.registers[2].type != JINX_ZEND_LONG || state.registers[2].value.lval != 12) {
        return fail("addv did not produce 12");
    }
    if (state.registers[3].type != JINX_ZEND_LONG || state.registers[3].value.lval != 7) {
        return fail("subv did not produce 7");
    }
    if (state.registers[4].type != JINX_ZEND_TRUE) {
        return fail("eqv did not produce true");
    }
    if (state.registers[5].type != JINX_ZEND_TRUE) {
        return fail("ltv did not produce true");
    }
    if (state.registers[6].type != JINX_ZEND_LONG || state.registers[6].value.lval != 123) {
        return fail("named boolean jumps took the wrong path");
    }

    {
        JinxZendVariableIrProgram bad;
        JinxZendVmOp bad_ops[4];
        jinx_zend_variable_ir_program_init(&bad, bad_ops, 4u);
        if (jinx_zend_variable_ir_lower("var a 0\naddv a a missing\n", &bad, values, 3u) != JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR) {
            return fail("unknown variable in scalar op did not fail closed");
        }
    }

    printf("PASS: Zend variable scalar IR smoke passed\n");
    printf("PASS: addv/subv/eqv/ltv and named boolean jumps lower into scalar VM ops\n");
    return 0;
}
