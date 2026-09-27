#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_builtin_dispatch.h"
#include "../runtime/jinx_oracle_zend_array_carrier.h"
#include "../runtime/jinx_zend_array_delete.h"

static int expect_int(JinxValue value, long long expected) {
    return value.type == 1u && value.as.i64 == expected;
}

static int expect_bool(JinxValue value, int expected) {
    return value.type == 2u && value.as.i64 == (expected ? 1 : 0);
}

static int expect_string(JinxValue value, const char *expected) {
    size_t len = strlen(expected);
    return value.type == 3u && value.flags == len && memcmp(value.as.ptr, expected, len) == 0;
}

static int expect_array_count(JinxValue value, size_t expected) {
    JinxZendArray *array = jinx_oracle_zend_array_ptr(value);
    return array != 0 && jinx_zend_array_live_count(array) == expected;
}

static int expect_long_index(JinxZendArray *array, size_t index, long long expected) {
    JinxZendValue *slot = jinx_zend_array_index(array, index);
    return slot != 0 && slot->type == JINX_ZEND_LONG && slot->value.lval == expected;
}

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxZendArray *other = jinx_zend_array_new_packed(2);
    JinxValue args[4];
    JinxValue result;

    if (array == 0 || other == 0) return fail("allocation");

    if (!jinx_zend_array_append(array, jinx_zend_long(10)) ||
        !jinx_zend_array_append(array, jinx_zend_long(20)) ||
        !jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30)) ||
        !jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40)) ||
        !jinx_zend_array_append(other, jinx_zend_long(5)) ||
        !jinx_zend_array_add_assoc(other, "keep", 4, jinx_zend_long(99))) {
        return fail("seeding");
    }

    args[0] = jinx_oracle_zend_array_value_borrowed(array);

    if (!expect_int(jinx_call_builtin_through_oracle("count", args, 1), 4)) return fail("count");
    if (!expect_int(jinx_call_builtin_through_oracle("array_key_first", args, 1), 0)) return fail("key first");
    if (!expect_string(jinx_call_builtin_through_oracle("array_key_last", args, 1), "keep")) return fail("key last");
    if (!expect_int(jinx_call_builtin_through_oracle("array_sum", args, 1), 100)) return fail("sum");
    if (!expect_int(jinx_call_builtin_through_oracle("array_product", args, 1), 240000)) return fail("product");

    args[1] = jinx_oracle_string_value("keep");
    if (!expect_bool(jinx_call_builtin_through_oracle("array_key_exists", args, 2), 1)) return fail("key exists");

    args[1] = jinx_oracle_bool_value(0);
    result = jinx_call_builtin_through_oracle("array_reverse", args, 2);
    if (!expect_array_count(result, 4)) return fail("reverse count");
    JinxZendArray *reversed = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *keep = jinx_zend_array_find(reversed, "keep", 4);
    if (keep == 0 || keep->type != JINX_ZEND_LONG || keep->value.lval != 40) return fail("reverse string key");
    jinx_oracle_zend_array_value_release(result);

    args[1] = jinx_oracle_int_value(1);
    args[2] = jinx_oracle_int_value(2);
    args[3] = jinx_oracle_bool_value(0);
    result = jinx_call_builtin_through_oracle("array_slice", args, 4);
    if (!expect_array_count(result, 2)) return fail("slice count");
    JinxZendArray *slice = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(slice, 0, 20)) return fail("slice value");
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zend_array_value_borrowed(array);
    args[1] = jinx_oracle_zend_array_value_borrowed(other);

    result = jinx_call_builtin_through_oracle("array_merge", args, 2);
    if (!expect_array_count(result, 5)) return fail("merge count");
    JinxZendArray *merged = jinx_oracle_zend_array_ptr(result);
    keep = jinx_zend_array_find(merged, "keep", 4);
    if (keep == 0 || keep->type != JINX_ZEND_LONG || keep->value.lval != 99) return fail("merge overwrite");
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_replace", args, 2);
    if (!expect_array_count(result, 4)) return fail("replace count");
    JinxZendArray *replaced = jinx_oracle_zend_array_ptr(result);
    keep = jinx_zend_array_find(replaced, "keep", 4);
    if (keep == 0 || keep->type != JINX_ZEND_LONG || keep->value.lval != 99) return fail("replace overwrite");
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *flip_source = jinx_zend_array_new_packed(2);
    JinxZendString *alpha = jinx_zend_string_new("alpha", 5);
    if (flip_source == 0 || alpha == 0 ||
        !jinx_zend_array_append(flip_source, jinx_zend_long(7)) ||
        !jinx_zend_array_append(flip_source, jinx_zend_string_value(alpha))) {
        return fail("flip source");
    }
    jinx_zend_string_release(alpha);

    args[0] = jinx_oracle_zend_array_value_borrowed(flip_source);
    result = jinx_call_builtin_through_oracle("array_flip", args, 1);
    if (!expect_array_count(result, 2)) return fail("flip count");
    JinxZendArray *flipped = jinx_oracle_zend_array_ptr(result);
    if (jinx_zend_array_index(flipped, 7) == 0 || jinx_zend_array_find(flipped, "alpha", 5) == 0) return fail("flip keys");
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(flip_source);

    JinxZendArray *case_source = jinx_zend_array_new_packed(1);
    if (case_source == 0 || !jinx_zend_array_add_assoc(case_source, "MiXeD", 5, jinx_zend_long(1))) return fail("case source");
    args[0] = jinx_oracle_zend_array_value_borrowed(case_source);
    args[1] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("array_change_key_case", args, 2);
    if (!expect_array_count(result, 1) ||
        jinx_zend_array_find(jinx_oracle_zend_array_ptr(result), "mixed", 5) == 0) {
        return fail("change key case");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(case_source);

    jinx_zend_array_release(other);
    jinx_zend_array_release(array);

    printf("PASS: Oracle generated dispatch Zend-array native core passed\n");
    return 0;
}
