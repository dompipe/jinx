#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_lowering_fixture.h"

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

typedef struct MethodStats {
    long sum;
    size_t calls;
} MethodStats;

static MethodStats g_stats;

static JinxZendValue collect_method(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
) {
    JinxZendValue *stored;
    (void)executor;

    g_stats.calls++;
    if (argc == 1u && args != 0 && args[0].type == JINX_ZEND_LONG) {
        g_stats.sum += (long)args[0].value.lval;
    }

    stored = jinx_zend_object_get_property(object, "seen");
    if (stored != 0 && stored->type == JINX_ZEND_LONG) {
        stored->value.lval++;
    }

    return jinx_zend_long((int64_t)g_stats.sum);
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

int main(void) {
    JinxZendExecutor executor;
    JinxZendClassTable table;
    JinxZendMethodEntry methods[] = {
        { "collect", collect_method }
    };
    JinxZendClassEntry collector_class = { "Collector", methods, 1u };
    JinxZendClassEntry exception_class;
    JinxZendObject *collector;
    JinxZendArray *array;
    JinxZendVmOp program[16];
    JinxZendVmState state;
    JinxZendVmResult result;
    size_t program_len;
    JinxZendValue *seen;
    JinxZendObject *throwable;

    jinx_zend_executor_init(&executor);
    jinx_zend_class_table_init(&table);
    if (!jinx_zend_class_table_register(&table, &collector_class)) {
        return fail("class registration failed");
    }

    collector = jinx_zend_object_new(&collector_class);
    array = make_deleted_array();
    if (collector == 0 || array == 0) {
        return fail("fixture allocation failed");
    }
    jinx_zend_object_set_property(collector, "seen", jinx_zend_long(0));

    g_stats.sum = 0;
    g_stats.calls = 0u;
    memset(program, 0, sizeof(program));
    program_len = jinx_zend_lower_foreach_method_fixture(
        program,
        16u,
        jinx_zend_array_value(array),
        jinx_zend_object_value(collector),
        "collect"
    );
    if (program_len != 7u) {
        return fail("foreach lowering did not emit seven ops");
    }

    jinx_zend_vm_state_init(&state, &table);
    result = jinx_zend_vm_run(&executor, &state, program, program_len);
    if (result != JINX_ZEND_VM_HALTED) {
        return fail("foreach lowered VM program did not halt");
    }
    if (g_stats.calls != 2u || g_stats.sum != 50) {
        return fail("foreach lowered VM program did not visit expected live values");
    }
    if (state.registers[JINX_ZEND_LOWER_FOREACH_RETURN_REG].type != JINX_ZEND_LONG ||
        state.registers[JINX_ZEND_LOWER_FOREACH_RETURN_REG].value.lval != 50) {
        return fail("foreach lowered return register was wrong");
    }
    seen = jinx_zend_object_get_property(collector, "seen");
    if (seen == 0 || seen->type != JINX_ZEND_LONG || seen->value.lval != 2) {
        return fail("foreach lowered method side effect was wrong");
    }

    exception_class = jinx_zend_throwable_class_entry("Exception");
    throwable = jinx_zend_throwable_new(&exception_class, "lowered boom", 5, "fixture.php", 77u);
    if (throwable == 0) {
        return fail("throwable allocation failed");
    }

    memset(program, 0, sizeof(program));
    program_len = jinx_zend_lower_throw_catch_fixture(
        program,
        16u,
        jinx_zend_object_value(throwable),
        "Exception",
        "fixture.php"
    );
    if (program_len != 5u) {
        return fail("throw/catch lowering did not emit five ops");
    }

    jinx_zend_vm_state_init(&state, &table);
    result = jinx_zend_vm_run(&executor, &state, program, program_len);
    if (result != JINX_ZEND_VM_HALTED) {
        return fail("throw/catch lowered VM program did not halt");
    }
    if (jinx_zend_error_state_has_error(&state.error_state)) {
        return fail("throw/catch lowered VM program did not clear exception");
    }
    if (state.registers[JINX_ZEND_LOWER_THROW_CAUGHT_REG].type != JINX_ZEND_OBJECT ||
        state.registers[JINX_ZEND_LOWER_THROW_CAUGHT_REG].value.object != throwable) {
        return fail("throw/catch lowered VM program did not store caught throwable");
    }

    jinx_zend_object_release(throwable);
    jinx_zend_object_release(collector);
    jinx_zend_array_release(array);
    jinx_zend_class_table_release(&table);

    printf("PASS: Zend lowering fixture smoke passed\n");
    printf("PASS: fixture emitters generate VM programs for foreach-method and throw-catch shapes\n");
    return 0;
}
