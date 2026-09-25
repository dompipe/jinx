#include "../runtime/jinx_zend_statement_ir.h"

#include <stdio.h>
#include <string.h>

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
    JinxZendArray *array;
    JinxZendValue values[4];
    JinxZendVmOp ops[32];
    JinxZendStatementIrProgram lowered;
    JinxZendVmState state;
    JinxZendVmResult vm_result;
    JinxZendValue *total;
    const char *program =
        "const 0 0\n"
        "const 1 1\n"
        "const 2 2\n"
        "call 3 1 record 0 1\n"
        "throw 2 smoke.ir\n"
        "jump_if_exception handle\n"
        "halt\n"
        "label handle\n"
        "catch 4 Exception miss\n"
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
    throwable = jinx_zend_throwable_new(&exception_class, "statement boom", 77, "source.php", 12u);
    array = jinx_zend_array_new_packed(2u);
    if (collector == 0 || throwable == 0 || array == 0) {
        return fail("allocation failed");
    }

    jinx_zend_array_append(array, jinx_zend_long(9));
    jinx_zend_array_append(array, jinx_zend_long(12));

    values[0] = jinx_zend_long(33);
    values[1] = jinx_zend_object_value(collector);
    values[2] = jinx_zend_object_value(throwable);
    values[3] = jinx_zend_array_value(array);

    jinx_zend_statement_ir_program_init(&lowered, ops, sizeof(ops) / sizeof(ops[0]));
    if (jinx_zend_statement_ir_lower(program, &lowered, values, 4u) != JINX_ZEND_STATEMENT_IR_OK) {
        return fail("statement IR lowering failed");
    }
    if (lowered.op_count != 10u) {
        return fail("statement IR lowered unexpected op count");
    }

    jinx_zend_vm_state_init(&state, &table);
    vm_result = jinx_zend_vm_run(&executor, &state, lowered.ops, lowered.op_count);
    if (vm_result != JINX_ZEND_VM_HALTED) {
        return fail("statement IR VM did not halt");
    }

    total = jinx_zend_object_get_property(collector, "total");
    if (total == 0 || total->type != JINX_ZEND_LONG || total->value.lval != 33) {
        return fail("method call from statement IR did not update object property");
    }
    if (state.error_state.level != JINX_ZEND_E_NONE || executor.last_error != 0) {
        return fail("catch/clear from statement IR did not clear exception state");
    }
    if (state.registers[4].type != JINX_ZEND_OBJECT || state.registers[4].value.object != throwable) {
        return fail("catch did not store throwable object register");
    }

    {
        JinxZendStatementIrProgram bad;
        JinxZendVmOp bad_ops[4];
        jinx_zend_statement_ir_program_init(&bad, bad_ops, 4u);
        if (jinx_zend_statement_ir_lower("jump missing\n", &bad, values, 4u) != JINX_ZEND_STATEMENT_IR_UNKNOWN_LABEL) {
            return fail("unknown label did not fail closed");
        }
    }

    jinx_zend_array_release(array);
    jinx_zend_object_release(throwable);
    jinx_zend_object_release(collector);
    jinx_zend_class_table_release(&table);

    printf("PASS: Zend statement IR smoke passed\n");
    printf("PASS: statement IR lowers const/call/throw/catch/clear/jump/halt with labels into VM ops\n");
    return 0;
}
