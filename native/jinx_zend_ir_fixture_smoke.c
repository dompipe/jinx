#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_ir_fixture.h"

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

typedef struct SeenValues {
    int calls;
    int64_t sum;
} SeenValues;

static JinxZendValue collect_method(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
) {
    JinxZendValue *seen_ptr;
    SeenValues *seen;
    (void)executor;

    if (object == 0 || argc != 1u || args == 0 || args[0].type != JINX_ZEND_LONG) {
        return jinx_zend_null();
    }

    seen_ptr = jinx_zend_object_get_property(object, "seen");
    if (seen_ptr == 0 || seen_ptr->type != JINX_ZEND_RESOURCE || seen_ptr->value.ptr == 0) {
        return jinx_zend_null();
    }

    seen = (SeenValues *)seen_ptr->value.ptr;
    seen->calls++;
    seen->sum += args[0].value.lval;
    return jinx_zend_long(seen->sum);
}

static JinxZendArray *make_deleted_array(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4u);
    if (array == 0) {
        return 0;
    }

    jinx_zend_array_append(array, jinx_zend_long(10));
    jinx_zend_array_append(array, jinx_zend_long(20));
    jinx_zend_array_add_assoc(array, "name", 4u, jinx_zend_long(30));
    jinx_zend_array_add_assoc(array, "keep", 4u, jinx_zend_long(40));
    jinx_zend_array_delete_index(array, 1u);
    jinx_zend_array_delete_string(array, "name", 4u);
    return array;
}

static int smoke_foreach_method_ir(void) {
    static const JinxZendMethodEntry methods[] = {
        { "collect", collect_method }
    };
    static const JinxZendClassEntry ce = {
        "Collector",
        methods,
        sizeof(methods) / sizeof(methods[0])
    };

    JinxZendClassTable table;
    JinxZendExecutor executor;
    JinxZendVmState state;
    JinxZendVmOp ops[16];
    JinxZendIrFixture fixture;
    char first[64];
    char second[64];
    JinxZendArray *array;
    JinxZendObject *object;
    SeenValues seen;
    JinxZendValue seen_value;
    size_t op_count;
    JinxZendVmResult result;

    jinx_zend_class_table_init(&table);
    jinx_zend_executor_init(&executor);
    if (!jinx_zend_class_table_register(&table, &ce)) {
        return fail("class registration failed");
    }

    array = make_deleted_array();
    object = jinx_zend_object_new(&ce);
    if (array == 0 || object == 0) {
        return fail("failed to allocate foreach IR fixtures");
    }

    seen.calls = 0;
    seen.sum = 0;
    seen_value = jinx_zend_null();
    seen_value.type = JINX_ZEND_RESOURCE;
    seen_value.value.ptr = &seen;
    if (!jinx_zend_object_set_property(object, "seen", seen_value)) {
        return fail("failed to attach seen property");
    }

    if (!jinx_zend_ir_fixture_parse("foreach_method collect", &fixture)) {
        return fail("failed to parse foreach_method IR fixture");
    }
    op_count = jinx_zend_ir_fixture_lower(
        &fixture,
        ops,
        sizeof(ops) / sizeof(ops[0]),
        jinx_zend_array_value(array),
        jinx_zend_object_value(object),
        first,
        sizeof(first),
        second,
        sizeof(second)
    );
    if (op_count != 7u || strcmp(first, "collect") != 0) {
        return fail("foreach_method IR did not lower to expected program");
    }

    jinx_zend_vm_state_init(&state, &table);
    result = jinx_zend_vm_run(&executor, &state, ops, op_count);
    if (result != JINX_ZEND_VM_HALTED) {
        return fail("foreach_method IR program did not halt cleanly");
    }
    if (seen.calls != 2 || seen.sum != 50) {
        return fail("foreach_method IR did not call method for live entries only");
    }
    if (state.registers[JINX_ZEND_LOWER_FOREACH_RETURN_REG].type != JINX_ZEND_LONG ||
        state.registers[JINX_ZEND_LOWER_FOREACH_RETURN_REG].value.lval != 50) {
        return fail("foreach_method IR did not preserve method return value");
    }

    jinx_zend_object_release(object);
    jinx_zend_array_release(array);
    jinx_zend_class_table_release(&table);
    return 0;
}

static int smoke_throw_catch_ir(void) {
    JinxZendExecutor executor;
    JinxZendVmState state;
    JinxZendVmOp ops[8];
    JinxZendIrFixture fixture;
    char first[64];
    char second[64];
    JinxZendClassEntry exception_ce;
    JinxZendObject *throwable;
    size_t op_count;
    JinxZendVmResult result;

    jinx_zend_executor_init(&executor);
    exception_ce = jinx_zend_throwable_class_entry("Exception");
    throwable = jinx_zend_throwable_new(&exception_ce, "ir boom", 7, "input.ir", 3u);
    if (throwable == 0) {
        return fail("failed to allocate throwable for IR smoke");
    }

    if (!jinx_zend_ir_fixture_parse("throw_catch Exception input.ir", &fixture)) {
        return fail("failed to parse throw_catch IR fixture");
    }
    op_count = jinx_zend_ir_fixture_lower(
        &fixture,
        ops,
        sizeof(ops) / sizeof(ops[0]),
        jinx_zend_object_value(throwable),
        jinx_zend_null(),
        first,
        sizeof(first),
        second,
        sizeof(second)
    );
    if (op_count != 5u || strcmp(first, "Exception") != 0 || strcmp(second, "input.ir") != 0) {
        return fail("throw_catch IR did not lower to expected program");
    }

    jinx_zend_vm_state_init(&state, 0);
    result = jinx_zend_vm_run(&executor, &state, ops, op_count);
    if (result != JINX_ZEND_VM_HALTED) {
        return fail("throw_catch IR program did not halt cleanly");
    }
    if (jinx_zend_error_state_has_throwable(&state.error_state) || executor.last_error != 0 || executor.error_level != 0u) {
        return fail("throw_catch IR did not clear exception state");
    }
    if (state.registers[JINX_ZEND_LOWER_THROW_CAUGHT_REG].type != JINX_ZEND_OBJECT ||
        state.registers[JINX_ZEND_LOWER_THROW_CAUGHT_REG].value.object != throwable) {
        return fail("throw_catch IR did not store caught throwable");
    }

    jinx_zend_object_release(throwable);
    return 0;
}

int main(void) {
    JinxZendIrFixture fixture;

    if (smoke_foreach_method_ir() != 0) {
        return 1;
    }
    if (smoke_throw_catch_ir() != 0) {
        return 1;
    }
    if (jinx_zend_ir_fixture_parse("unknown thing", &fixture)) {
        return fail("unknown IR fixture parsed unexpectedly");
    }
    if (jinx_zend_ir_fixture_parse("foreach_method", &fixture)) {
        return fail("incomplete foreach_method parsed unexpectedly");
    }

    printf("PASS: Zend tiny IR fixture smoke passed\n");
    printf("PASS: text IR parses and lowers foreach-method and throw-catch shapes into VM op streams\n");
    return 0;
}
