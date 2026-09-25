#include <stdio.h>

#include "../runtime/jinx_zend_foreach_program.h"

typedef struct ProgramAccum {
    long long sum;
    long long numeric_key_sum;
    int saw_keep;
    size_t calls;
} ProgramAccum;

static int program_body(JinxZendExecutor *executor, const JinxZendForeachEntry *entry, void *user_data) {
    ProgramAccum *accum = (ProgramAccum *)user_data;
    (void)executor;

    if (entry == 0 || accum == 0 || !entry->valid) {
        return 0;
    }

    accum->calls++;
    if (entry->key.type == JINX_ZEND_LONG) {
        accum->numeric_key_sum += entry->key.value.lval;
    } else if (entry->key.type == JINX_ZEND_STRING &&
        jinx_zend_string_equals_bytes(entry->key.value.str, "keep", 4)) {
        accum->saw_keep = 1;
    }

    if (entry->value.type == JINX_ZEND_LONG) {
        accum->sum += entry->value.value.lval;
    }

    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendForeachProgramState state;
    JinxZendForeachProgramResult result;
    ProgramAccum accum;
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxZendValue source;

    const JinxZendForeachProgramOp program[] = {
        { JINX_ZEND_OP_FE_RESET, 0u },
        { JINX_ZEND_OP_FE_FETCH, 4u },
        { JINX_ZEND_OP_FE_BODY, 0u },
        { JINX_ZEND_OP_JMP, 1u },
        { JINX_ZEND_OP_HALT, 0u }
    };

    if (array == 0) {
        fprintf(stderr, "FAIL: could not allocate array\n");
        return 1;
    }

    if (!jinx_zend_array_append(array, jinx_zend_long(10)) ||
        !jinx_zend_array_append(array, jinx_zend_long(20)) ||
        !jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30)) ||
        !jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40))) {
        fprintf(stderr, "FAIL: could not seed array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!jinx_zend_array_delete_index(array, 1u) ||
        !jinx_zend_array_delete_string(array, "name", 4)) {
        fprintf(stderr, "FAIL: could not tombstone array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_executor_init(&executor);
    accum.sum = 0;
    accum.numeric_key_sum = 0;
    accum.saw_keep = 0;
    accum.calls = 0u;
    source = jinx_zend_array_value(array);
    jinx_zend_foreach_program_state_init(&state, &executor, source, program_body, &accum);

    result = jinx_zend_foreach_program_run(
        &state,
        program,
        sizeof(program) / sizeof(program[0])
    );

    if (!result.ok || result.fault != 0) {
        fprintf(stderr, "FAIL: foreach program fault: %s\n", result.fault == 0 ? "unknown" : result.fault);
        jinx_zend_array_release(array);
        return 1;
    }

    if (accum.calls != 2u || result.body_calls != 2u ||
        accum.sum != 50 || accum.numeric_key_sum != 0 || !accum.saw_keep) {
        fprintf(stderr, "FAIL: foreach program body did not see expected live entries\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (state.key_slot.type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(state.key_slot.value.str, "keep", 4) ||
        state.value_slot.type != JINX_ZEND_LONG || state.value_slot.value.lval != 40) {
        fprintf(stderr, "FAIL: final key/value slots were not last live entry\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (result.final_pc != 4u || executor.executed_ops < 8u) {
        fprintf(stderr, "FAIL: foreach program control flow counters are wrong\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_array_release(array);
    printf("PASS: Zend foreach opcode program smoke passed\n");
    printf("PASS: FE_RESET/FE_FETCH/BODY/JMP/HALT loop over live buckets and skip tombstones\n");
    return 0;
}
