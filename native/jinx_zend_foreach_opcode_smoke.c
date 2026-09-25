#include <stdio.h>

#include "../runtime/jinx_zend_foreach_opcode.h"

typedef struct ForeachOpcodeStats {
    long long value_sum;
    long long numeric_key_sum;
    int string_key_seen;
    size_t bodies;
} ForeachOpcodeStats;

static int collect_body(JinxZendExecutor *executor, const JinxZendForeachEntry *entry, void *user_data) {
    ForeachOpcodeStats *stats = (ForeachOpcodeStats *)user_data;
    (void)executor;

    if (stats == 0 || entry == 0 || !entry->valid) {
        return 0;
    }

    stats->bodies++;
    if (entry->key.type == JINX_ZEND_LONG) {
        stats->numeric_key_sum += entry->key.value.lval;
    }
    if (entry->key.type == JINX_ZEND_STRING && jinx_zend_string_equals_bytes(entry->key.value.str, "keep", 4)) {
        stats->string_key_seen = 1;
    }
    if (entry->value.type == JINX_ZEND_LONG) {
        stats->value_sum += entry->value.value.lval;
    }

    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendForeachFrame frame;
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxZendValue key;
    JinxZendValue value;
    ForeachOpcodeStats stats;

    if (array == 0) {
        fprintf(stderr, "FAIL: could not allocate Zend array\n");
        return 1;
    }

    if (!jinx_zend_array_append(array, jinx_zend_long(10)) ||
        !jinx_zend_array_append(array, jinx_zend_long(20)) ||
        !jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30)) ||
        !jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40)) ||
        !jinx_zend_array_delete_index(array, 1u) ||
        !jinx_zend_array_delete_string(array, "name", 4)) {
        fprintf(stderr, "FAIL: could not seed foreach opcode array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (jinx_zend_fe_reset(&frame, jinx_zend_long(123)) != JINX_ZEND_FE_TYPE_ERROR) {
        fprintf(stderr, "FAIL: FE_RESET accepted non-array source\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (jinx_zend_fe_reset(&frame, jinx_zend_array_value(array)) != JINX_ZEND_FE_OK) {
        fprintf(stderr, "FAIL: FE_RESET rejected array source\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (jinx_zend_fe_fetch(&frame, &key, &value) != JINX_ZEND_FE_OK ||
        key.type != JINX_ZEND_LONG || key.value.lval != 0 ||
        value.type != JINX_ZEND_LONG || value.value.lval != 10) {
        fprintf(stderr, "FAIL: first FE_FETCH did not yield index 0 => 10\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (jinx_zend_fe_fetch(&frame, &key, &value) != JINX_ZEND_FE_OK ||
        key.type != JINX_ZEND_STRING || !jinx_zend_string_equals_bytes(key.value.str, "keep", 4) ||
        value.type != JINX_ZEND_LONG || value.value.lval != 40) {
        fprintf(stderr, "FAIL: second FE_FETCH did not skip tombstones to keep => 40\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (jinx_zend_fe_fetch(&frame, &key, &value) != JINX_ZEND_FE_DONE) {
        fprintf(stderr, "FAIL: FE_FETCH did not stop after live entries\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_executor_init(&executor);
    stats.value_sum = 0;
    stats.numeric_key_sum = 0;
    stats.string_key_seen = 0;
    stats.bodies = 0;

    if (jinx_zend_fe_reset(&frame, jinx_zend_array_value(array)) != JINX_ZEND_FE_OK) {
        fprintf(stderr, "FAIL: FE_RESET rejected array source for run_all\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (jinx_zend_fe_run_all(&executor, &frame, collect_body, &stats) != 2u ||
        executor.executed_ops != 2u ||
        stats.bodies != 2u ||
        stats.value_sum != 50 ||
        stats.numeric_key_sum != 0 ||
        !stats.string_key_seen) {
        fprintf(stderr, "FAIL: FE_RUN_ALL did not execute lowered foreach body correctly\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_array_release(array);
    printf("PASS: Zend foreach opcode primitive smoke passed\n");
    printf("PASS: FE_RESET/FE_FETCH skip tombstones and FE_RUN_ALL dispatches live foreach body\n");
    return 0;
}
