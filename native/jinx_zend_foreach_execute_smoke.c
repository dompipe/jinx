#include <stdio.h>

#include "../runtime/jinx_zend_foreach.h"

typedef struct ForeachAccum {
    long long numeric_key_sum;
    long long value_sum;
    size_t string_keys;
    size_t visits;
} ForeachAccum;

static int accumulate_foreach(JinxZendExecutor *executor, const JinxZendForeachEntry *entry, void *user_data) {
    ForeachAccum *accum = (ForeachAccum *)user_data;

    if (executor == 0 || entry == 0 || accum == 0 || !entry->valid) {
        return 0;
    }

    accum->visits++;

    if (entry->key.type == JINX_ZEND_LONG) {
        accum->numeric_key_sum += entry->key.value.lval;
    } else if (entry->key.type == JINX_ZEND_STRING) {
        accum->string_keys++;
    }

    if (entry->value.type == JINX_ZEND_LONG) {
        accum->value_sum += entry->value.value.lval;
    }

    return 1;
}

int main(void) {
    JinxZendExecutor executor;
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    ForeachAccum accum;
    size_t executed;

    if (array == 0) {
        fprintf(stderr, "FAIL: could not allocate Zend array\n");
        return 1;
    }

    if (!jinx_zend_array_append(array, jinx_zend_long(10)) ||
        !jinx_zend_array_append(array, jinx_zend_long(20)) ||
        !jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30)) ||
        !jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40))) {
        fprintf(stderr, "FAIL: could not seed Zend array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!jinx_zend_array_delete_index(array, 1u) || !jinx_zend_array_delete_string(array, "name", 4)) {
        fprintf(stderr, "FAIL: could not tombstone Zend array entries\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_executor_init(&executor);
    accum.numeric_key_sum = 0;
    accum.value_sum = 0;
    accum.string_keys = 0;
    accum.visits = 0;

    executed = jinx_zend_foreach_execute(&executor, array, accumulate_foreach, &accum);

    if (executed != 2u || accum.visits != 2u || executor.executed_ops != 2u ||
        accum.numeric_key_sum != 0 || accum.string_keys != 1u || accum.value_sum != 50) {
        fprintf(stderr, "FAIL: foreach execute did not lower live entries correctly\n");
        fprintf(stderr, "executed=%zu visits=%zu ops=%zu key_sum=%lld string_keys=%zu value_sum=%lld\n",
            executed,
            accum.visits,
            executor.executed_ops,
            accum.numeric_key_sum,
            accum.string_keys,
            accum.value_sum);
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_array_release(array);
    printf("PASS: Zend foreach executor lowering smoke passed\n");
    printf("PASS: foreach_execute skips tombstones, invokes body for live entries, and advances executor ops\n");
    return 0;
}
