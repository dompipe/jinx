#include <stdio.h>

#include "../runtime/jinx_zend_foreach.h"

static int expect_long_value(JinxZendValue value, int64_t expected) {
    return value.type == JINX_ZEND_LONG && value.value.lval == expected;
}

static int expect_string_key(JinxZendValue value, const char *text, size_t len) {
    return value.type == JINX_ZEND_STRING && jinx_zend_string_equals_bytes(value.value.str, text, len);
}

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxZendForeachIterator it;
    JinxZendForeachEntry first;
    JinxZendForeachEntry second;
    JinxZendForeachEntry end;
    int ok = 1;

    if (array == 0) {
        fprintf(stderr, "FAIL: could not allocate foreach array\n");
        return 1;
    }

    ok = ok && jinx_zend_array_append(array, jinx_zend_long(10));
    ok = ok && jinx_zend_array_append(array, jinx_zend_long(20));
    ok = ok && jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30));
    ok = ok && jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40));

    if (!ok || !jinx_zend_array_delete_index(array, 1u) || !jinx_zend_array_delete_string(array, "name", 4)) {
        fprintf(stderr, "FAIL: could not seed deleted foreach array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_foreach_init(&it, array);
    first = jinx_zend_foreach_next(&it);
    second = jinx_zend_foreach_next(&it);
    end = jinx_zend_foreach_next(&it);

    if (!first.valid || first.live_position != 0u ||
        !expect_long_value(first.key, 0) ||
        !expect_long_value(first.value, 10)) {
        fprintf(stderr, "FAIL: foreach first live entry was wrong\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!second.valid || second.live_position != 1u ||
        !expect_string_key(second.key, "keep", 4) ||
        !expect_long_value(second.value, 40)) {
        fprintf(stderr, "FAIL: foreach second live entry was wrong\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (end.valid) {
        fprintf(stderr, "FAIL: foreach did not stop after live entries\n");
        jinx_zend_array_release(array);
        return 1;
    }

    jinx_zend_array_release(array);
    printf("PASS: Zend foreach live iterator smoke passed\n");
    printf("PASS: foreach skips tombstones and yields live keys/values in insertion order\n");
    return 0;
}
