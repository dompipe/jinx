#include "../runtime/jinx_zend_opcode_vm.h"

#include <stdio.h>

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendVmState state;
    JinxZendVmOp ops[16];
    JinxZendVmResult result;

    jinx_zend_executor_init(&executor);
    jinx_zend_vm_state_init(&state, 0);

    for (size_t i = 0; i < sizeof(ops) / sizeof(ops[0]); i++) {
        ops[i].op = JINX_ZEND_VM_HALT;
        ops[i].dst = -1;
        ops[i].src = -1;
        ops[i].arg_start = -1;
        ops[i].key_dst = -1;
        ops[i].value_dst = -1;
        ops[i].argc = 0u;
        ops[i].target = 0u;
        ops[i].name = 0;
        ops[i].value = jinx_zend_null();
    }

    ops[0].op = JINX_ZEND_VM_LOAD_CONST;
    ops[0].dst = 0;
    ops[0].value = jinx_zend_long(7);

    ops[1].op = JINX_ZEND_VM_LOAD_CONST;
    ops[1].dst = 1;
    ops[1].value = jinx_zend_long(5);

    ops[2].op = JINX_ZEND_VM_ADD;
    ops[2].dst = 2;
    ops[2].src = 0;
    ops[2].arg_start = 1;

    ops[3].op = JINX_ZEND_VM_SUB;
    ops[3].dst = 3;
    ops[3].src = 2;
    ops[3].arg_start = 1;

    ops[4].op = JINX_ZEND_VM_EQ;
    ops[4].dst = 4;
    ops[4].src = 3;
    ops[4].arg_start = 0;

    ops[5].op = JINX_ZEND_VM_LT;
    ops[5].dst = 5;
    ops[5].src = 1;
    ops[5].arg_start = 0;

    ops[6].op = JINX_ZEND_VM_JMP_IF_TRUE;
    ops[6].src = 5;
    ops[6].target = 8u;

    ops[7].op = JINX_ZEND_VM_LOAD_CONST;
    ops[7].dst = 6;
    ops[7].value = jinx_zend_long(999);

    ops[8].op = JINX_ZEND_VM_LOAD_CONST;
    ops[8].dst = 6;
    ops[8].value = jinx_zend_long(123);

    ops[9].op = JINX_ZEND_VM_JMP_IF_FALSE;
    ops[9].src = 4;
    ops[9].target = 11u;

    ops[10].op = JINX_ZEND_VM_HALT;

    ops[11].op = JINX_ZEND_VM_LOAD_CONST;
    ops[11].dst = 6;
    ops[11].value = jinx_zend_long(777);

    result = jinx_zend_vm_run(&executor, &state, ops, 12u);
    if (result != JINX_ZEND_VM_HALTED) {
        return fail("scalar VM did not halt");
    }

    if (state.registers[2].type != JINX_ZEND_LONG || state.registers[2].value.lval != 12) {
        return fail("ADD did not produce 12");
    }
    if (state.registers[3].type != JINX_ZEND_LONG || state.registers[3].value.lval != 7) {
        return fail("SUB did not produce 7");
    }
    if (state.registers[4].type != JINX_ZEND_TRUE) {
        return fail("EQ did not produce true");
    }
    if (state.registers[5].type != JINX_ZEND_TRUE) {
        return fail("LT did not produce true");
    }
    if (state.registers[6].type != JINX_ZEND_LONG || state.registers[6].value.lval != 123) {
        return fail("boolean jump did not take true path");
    }

    printf("PASS: Zend scalar VM smoke passed\n");
    printf("PASS: ADD/SUB/EQ/LT and boolean jumps execute in the combined VM\n");
    return 0;
}
