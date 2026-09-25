#include "../runtime/jinx_zend_opcode_vm.h"

#include <stdio.h>

static int expect(int condition, const char *message) {
    if (!condition) {
        fprintf(stderr, "FAIL: %s\n", message);
        return 0;
    }
    return 1;
}

static JinxZendValue add_one_method(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
) {
    (void)executor;
    (void)object;

    if (argc == 0u || args == 0 || args[0].type != JINX_ZEND_LONG) {
        return jinx_zend_null();
    }

    return jinx_zend_long(args[0].value.lval + 1);
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendClassTable table;
    JinxZendMethodEntry methods[] = {
        { "addOne", add_one_method }
    };
    JinxZendClassEntry calculator_ce = { "Calculator", methods, 1u };
    JinxZendClassEntry exception_ce = jinx_zend_throwable_class_entry("Exception");
    JinxZendObject *calculator;
    JinxZendObject *exception;
    JinxZendArray *array;
    JinxZendVmState state;
    JinxZendVmResult result;

    jinx_zend_executor_init(&executor);
    jinx_zend_class_table_init(&table);

    if (!expect(jinx_zend_class_table_register(&table, &calculator_ce), "register Calculator class")) {
        return 1;
    }
    if (!expect(jinx_zend_class_table_register(&table, &exception_ce), "register Exception class")) {
        return 1;
    }

    calculator = jinx_zend_object_new(&calculator_ce);
    exception = jinx_zend_throwable_new(&exception_ce, "vm boom", 500, "vm.php", 9u);
    array = jinx_zend_array_new_packed(4u);
    if (!expect(calculator != 0 && exception != 0 && array != 0, "allocate VM fixtures")) {
        return 1;
    }

    jinx_zend_array_append(array, jinx_zend_long(10));
    jinx_zend_array_append(array, jinx_zend_long(20));
    jinx_zend_array_add_assoc(array, "keep", 4u, jinx_zend_long(30));
    jinx_zend_array_delete_index(array, 1u);

    jinx_zend_vm_state_init(&state, &table);

    const JinxZendVmOp program[] = {
        { .op = JINX_ZEND_VM_LOAD_CONST, .dst = 0, .value = jinx_zend_array_value(array) },
        { .op = JINX_ZEND_VM_LOAD_CONST, .dst = 1, .value = jinx_zend_object_value(calculator) },
        { .op = JINX_ZEND_VM_LOAD_CONST, .dst = 2, .value = jinx_zend_object_value(exception) },
        { .op = JINX_ZEND_VM_FE_RESET, .src = 0 },
        { .op = JINX_ZEND_VM_FE_FETCH, .key_dst = 3, .value_dst = 4, .target = 8u },
        { .op = JINX_ZEND_VM_METHOD_CALL, .dst = 5, .src = 1, .arg_start = 4, .argc = 1u, .name = "addOne" },
        { .op = JINX_ZEND_VM_JMP, .target = 4u },
        { .op = JINX_ZEND_VM_HALT },
        { .op = JINX_ZEND_VM_THROW, .src = 2, .name = "vm.php" },
        { .op = JINX_ZEND_VM_JMP_IF_EXCEPTION, .target = 11u },
        { .op = JINX_ZEND_VM_HALT },
        { .op = JINX_ZEND_VM_CATCH, .dst = 6, .name = "Exception", .target = 14u },
        { .op = JINX_ZEND_VM_CLEAR_EXCEPTION },
        { .op = JINX_ZEND_VM_HALT },
        { .op = JINX_ZEND_VM_HALT }
    };

    result = jinx_zend_vm_run(&executor, &state, program, sizeof(program) / sizeof(program[0]));

    if (!expect(result == JINX_ZEND_VM_HALTED, "VM halts cleanly")) {
        return 1;
    }
    if (!expect(state.registers[3].type == JINX_ZEND_STRING, "last foreach key is string key")) {
        return 1;
    }
    if (!expect(state.registers[4].type == JINX_ZEND_LONG && state.registers[4].value.lval == 30, "FE_FETCH skipped tombstone and ended on live value 30")) {
        return 1;
    }
    if (!expect(state.registers[5].type == JINX_ZEND_LONG && state.registers[5].value.lval == 31, "METHOD_CALL returned addOne(last value)")) {
        return 1;
    }
    if (!expect(state.registers[6].type == JINX_ZEND_OBJECT && state.registers[6].value.object == exception, "CATCH stored caught exception object")) {
        return 1;
    }
    if (!expect(!jinx_zend_error_state_has_throwable(&state.error_state), "CLEAR_EXCEPTION removed active throwable")) {
        return 1;
    }
    if (!expect(executor.last_error == 0 && executor.error_level == 0u, "executor exception state cleared")) {
        return 1;
    }
    if (!expect(state.steps > 0u && executor.executed_ops >= state.steps, "VM advanced executor ops")) {
        return 1;
    }

    jinx_zend_array_release(array);
    jinx_zend_object_release(calculator);
    jinx_zend_object_release(exception);
    jinx_zend_class_table_release(&table);

    printf("PASS: Zend combined opcode VM smoke passed\n");
    printf("PASS: VM sequences foreach, method call, throw/catch, clear-exception, jumps, and halt\n");
    return 0;
}
