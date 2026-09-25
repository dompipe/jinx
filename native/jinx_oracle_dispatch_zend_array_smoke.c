#include <stdio.h>

#include "../runtime/jinx_builtin_dispatch.h"
#include "../runtime/jinx_oracle_zend_array_carrier.h"
#include "../runtime/jinx_zend_array_delete.h"

static int expect_int(JinxValue value, long long expected) {
    return value.type == 1u && value.as.i64 == expected;
}

static int expect_bool(JinxValue value, int expected) {
    return value.type == 2u && value.as.i64 == (expected ? 1 : 0);
}

static int expect_carried_array_count(JinxValue value, size_t expected) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(value);
    return array != 0 && jinx_zend_array_live_count(array) == expected;
}

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxValue args[2];
    JinxValue result;
    JinxZendArray *result_array;
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
        fprintf(stderr, "FAIL: could not seed and delete Zend array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    args[0] = jinx_oracle_zend_array_value_borrowed(array);

    result = jinx_call_builtin_through_oracle("count", args, 1);
    if (!expect_int(result, 2)) {
        fprintf(stderr, "FAIL: Oracle dispatch count did not use live Zend array count\n");
        jinx_zend_array_release(array);
        return 1;
    }

    args[1] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("array_key_exists", args, 2);
    if (!expect_bool(result, 1)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_key_exists missed live numeric key 0\n");
        jinx_zend_array_release(array);
        return 1;
    }

    args[1] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("array_key_exists", args, 2);
    if (!expect_bool(result, 0)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_key_exists saw deleted numeric key 1\n");
        jinx_zend_array_release(array);
        return 1;
    }

    args[1] = jinx_oracle_string_value("keep");
    result = jinx_call_builtin_through_oracle("array_key_exists", args, 2);
    if (!expect_bool(result, 1)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_key_exists missed live string key\n");
        jinx_zend_array_release(array);
        return 1;
    }

    args[1] = jinx_oracle_string_value("name");
    result = jinx_call_builtin_through_oracle("array_key_exists", args, 2);
    if (!expect_bool(result, 0)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_key_exists saw deleted string key\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_call_builtin_through_oracle("array_is_list", args, 1);
    if (!expect_bool(result, 0)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_is_list ignored live mixed key state\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = jinx_call_builtin_through_oracle("array_values", args, 1);
    if (!expect_carried_array_count(result, 2)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_values did not return live carried array\n");
        jinx_oracle_zend_array_value_release(result);
        jinx_zend_array_release(array);
        return 1;
    }
    result_array = jinx_oracle_zend_array_ptr(result);
    if (!jinx_zend_array_live_is_list(result_array)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_values result is not list\n");
        jinx_oracle_zend_array_value_release(result);
        jinx_zend_array_release(result_array);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(result_array);

    result = jinx_call_builtin_through_oracle("array_keys", args, 1);
    if (!expect_carried_array_count(result, 2)) {
        fprintf(stderr, "FAIL: Oracle dispatch array_keys did not return live carried array\n");
        jinx_oracle_zend_array_value_release(result);
        jinx_zend_array_release(array);
        return 1;
    }
    result_array = jinx_oracle_zend_array_ptr(result);
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(result_array);

    jinx_zend_array_release(array);
    printf("PASS: Oracle generated dispatch Zend-array smoke passed\n");
    printf("PASS: jinx_call_builtin_through_oracle routes carried JinxZendArray before count-only fallbacks\n");
    return 0;
}
