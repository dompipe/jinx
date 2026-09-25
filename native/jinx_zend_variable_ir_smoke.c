#include "../runtime/jinx_zend_variable_ir.h"

#include <stdio.h>

static JinxZendValue record_value(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
) {
    JinxZendValue *total;
    (void)executor;

    if (object == 0 || argc != 1u || args == 0 || args[0].type != JINX_ZEND_LONG) {
        return jinx_zend_null();
    }

    total = jinx_zend_object_get_property(object, "total");
    if (total == 0 || total->type != JINX_ZEND_LONG) {
        jinx_zend_object_set_property(object, "total", jinx_zend_long(args[0].value.lval));
        return args[0];
    }

    total->value.lval += args[0].value.lval;
    return *total;
}

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendClassTable table;
    JinxZendMethodEntry methods[] = {
        {"record", record_value}
    };
    JinxZendClassEntry collector_class = {"Collector", methods, 1u};
    JinxZendClassEntry exception_class = jinx_zend_throwable_class_entry("Exception");
    JinxZendObject *collector;
    JinxZendObject *throwable;
    JinxZendValue values[3];
    JinxZendVmOp ops[32];
    JinxZendVariableIrProgram lowered;
    JinxZendVmState state;
    JinxZendVmResult vm_result;
    JinxZendValue *total;
    const char *program =
        "var value 0\n"
        "var tmp 1\n"
        "var object 2\n"
        "var result 3\n"
        "var throwable 4\n"
        "var caught 5\n"
        "load value 0\n"
        "set tmp value\n"
        "load object 1\n"
        "callv result object record tmp\n"
        "load throwable 2\n"
        "throwv throwable vars.ir\n"
        "jump_if_exception handle\n"
        "halt\n"
        "label handle\n"
        "catchv caught Exception miss\n"
        "clear\n"
        "halt\n"
        "label miss\n"
        "halt\n";

    jinx_zend_executor_init(&executor);
    jinx_zend_class_table_init(&table);
    if (!jinx_zend_class_table_register(&table, &collector_class)) {
        return fail("class registration failed");
    }

    collector = jinx_zend_object_new(&collector_class);
    throwable = jinx_zend_throwable_new(&exception_class, "variable boom", 88, "vars.php", 31u);
    if (collector == 0 || throwable == 0) {
        return fail("allocation failed");
    }

    values[0] = jinx_zend_long(44);
    values[1] = jinx_zend_object_value(collector);
    values[2] = jinx_zend_object_value(throwable);

    jinx_zend_variable_ir_program_init(&lowered, ops, sizeof(ops) / sizeof(ops[0]));
    if (jinx_zend_variable_ir_lower(program, &lowered, values, 3u) != JINX_ZEND_VARIABLE_IR_OK) {
        return fail("variable IR lowering failed");
    }
    if (lowered.statement.op_count != 10u) {
        fprintf(stderr, "FAIL: variable IR lowered unexpected op count actual=%zu expected=10\n", lowered.statement.op_count);
        return 1;
    }

    jinx_zend_vm_state_init(&state, &table);
    vm_result = jinx_zend_vm_run(&executor, &state, lowered.statement.ops, lowered.statement.op_count);
    if (vm_result != JINX_ZEND_VM_HALTED) {
        return fail("variable IR VM did not halt");
    }

    if (state.registers[0].type != JINX_ZEND_LONG || state.registers[1].type != JINX_ZEND_LONG ||
        state.registers[0].value.lval != 44 || state.registers[1].value.lval != 44) {
        return fail("set/copy did not mirror variable value");
    }

    total = jinx_zend_object_get_property(collector, "total");
    if (total == 0 || total->type != JINX_ZEND_LONG || total->value.lval != 44) {
        return fail("callv did not pass variable argument into method");
    }

    if (state.error_state.level != JINX_ZEND_E_NONE || executor.last_error != 0) {
        return fail("catchv/clear did not clear exception state");
    }
    if (state.registers[5].type != JINX_ZEND_OBJECT || state.registers[5].value.object != throwable) {
        return fail("catchv did not store caught throwable variable");
    }

    {
        JinxZendVariableIrProgram bad;
        JinxZendVmOp bad_ops[4];
        jinx_zend_variable_ir_program_init(&bad, bad_ops, 4u);
        if (jinx_zend_variable_ir_lower("set nope missing\n", &bad, values, 3u) != JINX_ZEND_VARIABLE_IR_UNKNOWN_VAR) {
            return fail("unknown variable did not fail closed");
        }
    }

    jinx_zend_object_release(throwable);
    jinx_zend_object_release(collector);
    jinx_zend_class_table_release(&table);

    printf("PASS: Zend variable IR smoke passed\n");
    printf("PASS: variable IR maps names to registers and lowers load/set/callv/throwv/catchv\n");
    return 0;
}
