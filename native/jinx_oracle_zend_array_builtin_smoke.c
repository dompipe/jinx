#include <stdio.h>

#include "../runtime/jinx_oracle_zend_array_builtins.h"

static int expect_int(JinxValue value, int64_t expected) {
    return value.type == 1u && value.as.i64 == expected;
}

static int expect_bool(JinxValue value, int expected) {
    return value.type == 2u && value.as.i64 == (expected ? 1 : 0);
}

static int expect_array_live_count(JinxValue value, size_t expected) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(value);
    return array != 0 && jinx_zend_array_live_count(array) == expected;
}

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxValue carried;
    JinxValue result;
    JinxZendArray *values;
    JinxZendArray *keys;
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
    ok = ok && jinx_zend_array_delete_index(array, 1u);
    ok = ok && jinx_zend_array_delete_string(array, "name", 4);

    if (!ok) {
        fprintf(stderr, "FAIL: could not seed/delete Zend array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    carried = jinx_oracle_zend_array_value_borrowed(array);

    result = jinx_oracle_zend_array_dispatch_builtin("count", carried, jinx_oracle_zero_value());
    if (!expect_int(result, 2)) {
        fprintf(stderr, "FAIL: carrier count did not ignore tombstones\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_oracle_zend_array_dispatch_builtin("array_key_exists", carried, jinx_oracle_int_value(1));
    if (!expect_bool(result, 0)) {
        fprintf(stderr, "FAIL: deleted numeric key still exists\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_oracle_zend_array_dispatch_builtin("array_key_exists", carried, jinx_oracle_int_value(0));
    if (!expect_bool(result, 1)) {
        fprintf(stderr, "FAIL: live numeric key missing\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_oracle_zend_array_dispatch_builtin("array_key_exists", carried, jinx_oracle_string_value("name"));
    if (!expect_bool(result, 0)) {
        fprintf(stderr, "FAIL: deleted string key still exists\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_oracle_zend_array_dispatch_builtin("array_key_exists", carried, jinx_oracle_string_value("keep"));
    if (!expect_bool(result, 1)) {
        fprintf(stderr, "FAIL: live string key missing\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_oracle_zend_array_dispatch_builtin("array_is_list", carried, jinx_oracle_zero_value());
    if (!expect_bool(result, 0)) {
        fprintf(stderr, "FAIL: tombstoned sparse array reported as list\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_oracle_zend_array_dispatch_builtin("array_values", carried, jinx_oracle_zero_value());
    if (!expect_array_live_count(result, 2)) {
        fprintf(stderr, "FAIL: array_values did not return two live values\n");
        jinx_oracle_zend_array_value_release(result);
        jinx_zend_array_release(array);
        return 1;
    }
    values = jinx_oracle_zend_array_ptr(result);
    slot = jinx_zend_array_index(values, 1u);
    if (slot == 0 || slot->type != JINX_ZEND_LONG || slot->value.lval != 40 ||
        !jinx_zend_array_live_is_list(values)) {
        fprintf(stderr, "FAIL: array_values carrier result lost value/list semantics\n");
        jinx_oracle_zend_array_value_release(result);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_oracle_zend_array_dispatch_builtin("array_keys", carried, jinx_oracle_zero_value());
    if (!expect_array_live_count(result, 2)) {
        fprintf(stderr, "FAIL: array_keys did not return two live keys\n");
        jinx_oracle_zend_array_value_release(result);
        jinx_zend_array_release(array);
        return 1;
    }
    keys = jinx_oracle_zend_array_ptr(result);
    slot = jinx_zend_array_index(keys, 1u);
    if (slot == 0 || slot->type != JINX_ZEND_STRING || !jinx_zend_string_equals_bytes(slot->value.str, "keep", 4)) {
        fprintf(stderr, "FAIL: array_keys carrier result lost live string key\n");
        jinx_oracle_zend_array_value_release(result);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_oracle_zend_array_value_release(result);

    jinx_zend_array_release(array);
    printf("PASS: Oracle Zend array builtin carrier dispatch smoke passed\n");
    printf("PASS: count, key_exists, is_list, values, keys route carried JinxZendArray through live-aware helpers\n");
    return 0;
}
