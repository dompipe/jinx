#include <stdio.h>

#include "../runtime/jinx_zend_engine.h"
#include "../runtime/jinx_zend_array_delete.h"

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    const JinxZendBucket *live0;
    const JinxZendBucket *live1;
    JinxZendValue *slot;
    int ok = 1;

    if (array == 0) {
        fprintf(stderr, "FAIL: could not allocate Zend array\n");
        return 1;
    }

    ok = ok && jinx_zend_array_append(array, jinx_zend_long(10));
    ok = ok && jinx_zend_array_append(array, jinx_zend_long(20));
    ok = ok && jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30));
    ok = ok && jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40));

    if (!ok || array->count != 4u || jinx_zend_array_live_count(array) != 4u) {
        fprintf(stderr, "FAIL: initial Zend array delete setup failed\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!jinx_zend_array_delete_index(array, 1u) || !jinx_zend_array_delete_string(array, "name", 4)) {
        fprintf(stderr, "FAIL: delete_index/delete_string failed\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (jinx_zend_array_live_count(array) != 2u ||
        jinx_zend_array_delete_index(array, 1u) ||
        jinx_zend_array_delete_string(array, "name", 4)) {
        fprintf(stderr, "FAIL: tombstone live count or repeated delete failed\n");
        jinx_zend_array_release(array);
        return 1;
    }

    live0 = jinx_zend_array_live_iter_at(array, 0);
    live1 = jinx_zend_array_live_iter_at(array, 1);
    if (live0 == 0 || live1 == 0 ||
        live0->key != 0 || live0->h != 0u || live0->value.type != JINX_ZEND_LONG || live0->value.value.lval != 10 ||
        live1->key == 0 || !jinx_zend_string_equals_bytes(live1->key, "keep", 4) ||
        live1->value.type != JINX_ZEND_LONG || live1->value.value.lval != 40) {
        fprintf(stderr, "FAIL: live iteration did not skip tombstones\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!jinx_zend_array_compact(array) || array->count != 2u || jinx_zend_array_live_count(array) != 2u) {
        fprintf(stderr, "FAIL: compact failed\n");
        jinx_zend_array_release(array);
        return 1;
    }

    slot = jinx_zend_array_index(array, 0u);
    if (slot == 0 || slot->type != JINX_ZEND_LONG || slot->value.lval != 10 ||
        jinx_zend_array_find(array, "keep", 4) == 0 ||
        jinx_zend_array_find(array, "name", 4) != 0) {
        fprintf(stderr, "FAIL: compacted lookup state is wrong\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_array_release(array);
    printf("PASS: Zend array delete/tombstone smoke passed\n");
    printf("PASS: delete_index, delete_string, live iteration, and compact preserve valid buckets\n");
    return 0;
}
