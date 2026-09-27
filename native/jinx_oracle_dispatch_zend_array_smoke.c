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

static int expect_string_index(JinxZendArray *array, size_t index, const char *expected) {
    JinxZendValue *slot = jinx_zend_array_index(array, index);
    size_t len = strlen(expected);
    return slot != 0 && slot->type == JINX_ZEND_STRING && slot->value.str != 0 &&
        slot->value.str->len == len && memcmp(slot->value.str->bytes, expected, len) == 0;
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

    JinxZendArray *recursive_inner = jinx_zend_array_new_packed(2);
    JinxZendArray *recursive_outer = jinx_zend_array_new_packed(2);
    if (recursive_inner == 0 || recursive_outer == 0 ||
        !jinx_zend_array_append(recursive_inner, jinx_zend_long(1)) ||
        !jinx_zend_array_append(recursive_inner, jinx_zend_long(2)) ||
        !jinx_zend_array_append(recursive_outer, jinx_zend_array_value(recursive_inner)) ||
        !jinx_zend_array_append(recursive_outer, jinx_zend_long(3))) {
        return fail("recursive count source");
    }
    jinx_zend_array_release(recursive_inner);
    args[0] = jinx_oracle_zend_array_value_borrowed(recursive_outer);
    args[1] = jinx_oracle_int_value(1);
    if (!expect_int(jinx_call_builtin_through_oracle("count", args, 2), 4)) return fail("recursive count");
    jinx_zend_array_release(recursive_outer);

    args[0] = jinx_oracle_zend_array_value_borrowed(array);
    args[1] = jinx_oracle_string_value("20");
    args[2] = jinx_oracle_bool_value(0);
    result = jinx_call_builtin_through_oracle("array_keys", args, 3);
    if (!expect_array_count(result, 1) || !expect_long_index(jinx_oracle_zend_array_ptr(result), 0, 1)) {
        return fail("array_keys loose filter");
    }
    jinx_oracle_zend_array_value_release(result);
    args[2] = jinx_oracle_bool_value(1);
    result = jinx_call_builtin_through_oracle("array_keys", args, 3);
    if (!expect_array_count(result, 0)) return fail("array_keys strict filter");
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zend_array_value_borrowed(array);
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

    args[0] = jinx_oracle_string_value("abca");
    args[1] = jinx_oracle_int_value(3);
    result = jinx_call_builtin_through_oracle("count_chars", args, 2);
    if (!expect_string(result, "abc")) return fail("count_chars mode 3");

    args[1] = jinx_oracle_int_value(5);
    result = jinx_call_builtin_through_oracle("count_chars", args, 2);
    if (result.type != 0u) return fail("count_chars invalid mode must fault");

    args[0] = jinx_oracle_string_value("Hello, world!");
    result = jinx_call_builtin_through_oracle("str_word_count", args, 1);
    if (!expect_int(result, 2)) return fail("str_word_count mode 0");

    args[1] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("str_word_count", args, 2);
    if (!expect_array_count(result, 2)) return fail("str_word_count mode 1 count");
    JinxZendArray *words1 = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(words1, 0, "Hello") || !expect_string_index(words1, 1, "world")) {
        return fail("str_word_count mode 1 values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[1] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("str_word_count", args, 2);
    if (!expect_array_count(result, 2)) return fail("str_word_count mode 2 count");
    JinxZendArray *words2 = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(words2, 0, "Hello") || !expect_string_index(words2, 7, "world")) {
        return fail("str_word_count mode 2 offsets");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("abc123");
    args[1] = jinx_oracle_int_value(0);
    args[2] = jinx_oracle_string_value("0..9");
    result = jinx_call_builtin_through_oracle("str_word_count", args, 3);
    if (!expect_int(result, 1)) return fail("str_word_count charlist");

    args[0] = jinx_oracle_string_value(",");
    args[1] = jinx_oracle_zend_array_value_borrowed(array);
    result = jinx_call_builtin_through_oracle("implode", args, 2);
    if (!expect_string(result, "10,20,30,40")) return fail("implode");

    JinxZendArray *vs_values = jinx_zend_array_new_packed(2);
    JinxZendString *amsterdam = jinx_zend_string_new("Amsterdam", 9);
    if (vs_values == 0 || amsterdam == 0 ||
        !jinx_zend_array_append(vs_values, jinx_zend_long(7)) ||
        !jinx_zend_array_append(vs_values, jinx_zend_string_value(amsterdam))) {
        return fail("vsprintf source");
    }
    jinx_zend_string_release(amsterdam);
    args[0] = jinx_oracle_string_value("There are %u million bicycles in %s.");
    args[1] = jinx_oracle_zend_array_value_borrowed(vs_values);
    result = jinx_call_builtin_through_oracle("vsprintf", args, 2);
    if (!expect_string(result, "There are 7 million bicycles in Amsterdam.")) return fail("vsprintf");
    jinx_zend_array_release(vs_values);

    args[0] = jinx_oracle_int_value(1);
    args[1] = jinx_oracle_int_value(5);
    result = jinx_call_builtin_through_oracle("range", args, 2);
    if (!expect_array_count(result, 5)) return fail("range count");
    JinxZendArray *range_array = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(range_array, 0, 1) || !expect_long_index(range_array, 4, 5)) return fail("range values");
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_int_value(5);
    args[1] = jinx_oracle_int_value(1);
    args[2] = jinx_oracle_int_value(-2);
    result = jinx_call_builtin_through_oracle("range", args, 3);
    if (!expect_array_count(result, 3)) return fail("negative-step range count");
    JinxZendArray *negative_range = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(negative_range, 0, 5) ||
        !expect_long_index(negative_range, 1, 3) ||
        !expect_long_index(negative_range, 2, 1)) {
        return fail("negative-step range values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_int_value(1);
    args[1] = jinx_oracle_int_value(5);
    args[2] = jinx_oracle_int_value(-1);
    result = jinx_call_builtin_through_oracle("range", args, 3);
    if (result.type != 0u) return fail("negative increasing range must fault");

    args[0] = jinx_oracle_int_value(1);
    args[1] = jinx_oracle_int_value(5);
    args[2] = jinx_oracle_int_value(9);
    result = jinx_call_builtin_through_oracle("range", args, 3);
    if (result.type != 0u) return fail("oversized range step must fault");

    args[2] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("range", args, 3);
    if (result.type != 0u) return fail("zero range step must fault");

    args[0] = jinx_oracle_int_value(2);
    args[1] = jinx_oracle_int_value(3);
    args[2] = jinx_oracle_int_value(9);
    result = jinx_call_builtin_through_oracle("array_fill", args, 3);
    if (!expect_array_count(result, 3)) return fail("array_fill count");
    JinxZendArray *filled = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(filled, 2, 9) || !expect_long_index(filled, 4, 9)) return fail("array_fill values");
    jinx_oracle_zend_array_value_release(result);

    args[1] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("array_fill", args, 3);
    if (!expect_array_count(result, 0)) return fail("array_fill zero count");
    jinx_oracle_zend_array_value_release(result);

    args[1] = jinx_oracle_int_value(-1);
    result = jinx_call_builtin_through_oracle("array_fill", args, 3);
    if (result.type != 0u) return fail("array_fill negative count must fault");

    JinxZendArray *key_array = jinx_zend_array_new_packed(2);
    JinxZendArray *value_array = jinx_zend_array_new_packed(2);
    JinxZendString *x_string = jinx_zend_string_new("x", 1);
    if (key_array == 0 || value_array == 0 || x_string == 0 ||
        !jinx_zend_array_append(key_array, jinx_zend_long(2)) ||
        !jinx_zend_array_append(key_array, jinx_zend_string_value(x_string)) ||
        !jinx_zend_array_append(value_array, jinx_zend_long(70)) ||
        !jinx_zend_array_append(value_array, jinx_zend_long(80))) {
        return fail("combine source");
    }
    jinx_zend_string_release(x_string);

    args[0] = jinx_oracle_zend_array_value_borrowed(key_array);
    args[1] = jinx_oracle_int_value(7);
    result = jinx_call_builtin_through_oracle("array_fill_keys", args, 2);
    if (!expect_array_count(result, 2)) return fail("array_fill_keys count");
    JinxZendArray *fill_keys = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *x_slot = jinx_zend_array_find(fill_keys, "x", 1);
    if (!expect_long_index(fill_keys, 2, 7) || x_slot == 0 || x_slot->type != JINX_ZEND_LONG || x_slot->value.lval != 7) {
        return fail("array_fill_keys values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zend_array_value_borrowed(key_array);
    args[1] = jinx_oracle_zend_array_value_borrowed(value_array);
    result = jinx_call_builtin_through_oracle("array_combine", args, 2);
    if (!expect_array_count(result, 2)) return fail("array_combine count");
    JinxZendArray *combined = jinx_oracle_zend_array_ptr(result);
    x_slot = jinx_zend_array_find(combined, "x", 1);
    if (!expect_long_index(combined, 2, 70) || x_slot == 0 || x_slot->type != JINX_ZEND_LONG || x_slot->value.lval != 80) {
        return fail("array_combine values");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *numeric_keys = jinx_zend_array_new_packed(4);
    JinxZendString *key_2 = jinx_zend_string_new("2", 1);
    JinxZendString *key_02 = jinx_zend_string_new("02", 2);
    JinxZendString *key_plus_2 = jinx_zend_string_new("+2", 2);
    JinxZendString *key_minus_2 = jinx_zend_string_new("-2", 2);
    if (numeric_keys == 0 || key_2 == 0 || key_02 == 0 || key_plus_2 == 0 || key_minus_2 == 0 ||
        !jinx_zend_array_append(numeric_keys, jinx_zend_string_value(key_2)) ||
        !jinx_zend_array_append(numeric_keys, jinx_zend_string_value(key_02)) ||
        !jinx_zend_array_append(numeric_keys, jinx_zend_string_value(key_plus_2)) ||
        !jinx_zend_array_append(numeric_keys, jinx_zend_string_value(key_minus_2))) {
        return fail("numeric string key source");
    }
    jinx_zend_string_release(key_2);
    jinx_zend_string_release(key_02);
    jinx_zend_string_release(key_plus_2);
    jinx_zend_string_release(key_minus_2);

    args[0] = jinx_oracle_zend_array_value_borrowed(numeric_keys);
    args[1] = jinx_oracle_int_value(9);
    result = jinx_call_builtin_through_oracle("array_fill_keys", args, 2);
    if (!expect_array_count(result, 4)) return fail("array_fill_keys numeric-string count");
    JinxZendArray *numeric_fill = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(numeric_fill, 2, 9) ||
        jinx_zend_array_find(numeric_fill, "02", 2) == 0 ||
        jinx_zend_array_find(numeric_fill, "+2", 2) == 0 ||
        !expect_long_index(numeric_fill, (size_t)-2, 9)) {
        return fail("array_fill_keys numeric-string coercion");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *numeric_values = jinx_zend_array_new_packed(2);
    JinxZendArray *numeric_combine_keys = jinx_zend_array_new_packed(2);
    JinxZendString *combine_2 = jinx_zend_string_new("2", 1);
    JinxZendString *combine_02 = jinx_zend_string_new("02", 2);
    if (numeric_values == 0 || numeric_combine_keys == 0 || combine_2 == 0 || combine_02 == 0 ||
        !jinx_zend_array_append(numeric_values, jinx_zend_long(70)) ||
        !jinx_zend_array_append(numeric_values, jinx_zend_long(80)) ||
        !jinx_zend_array_append(numeric_combine_keys, jinx_zend_string_value(combine_2)) ||
        !jinx_zend_array_append(numeric_combine_keys, jinx_zend_string_value(combine_02))) {
        return fail("numeric combine source");
    }
    jinx_zend_string_release(combine_2);
    jinx_zend_string_release(combine_02);

    args[0] = jinx_oracle_zend_array_value_borrowed(numeric_combine_keys);
    args[1] = jinx_oracle_zend_array_value_borrowed(numeric_values);
    result = jinx_call_builtin_through_oracle("array_combine", args, 2);
    if (!expect_array_count(result, 2)) return fail("array_combine numeric-string count");
    JinxZendArray *numeric_combined = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *leading_zero = jinx_zend_array_find(numeric_combined, "02", 2);
    if (!expect_long_index(numeric_combined, 2, 70) ||
        leading_zero == 0 || leading_zero->type != JINX_ZEND_LONG || leading_zero->value.lval != 80) {
        return fail("array_combine numeric-string coercion");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(numeric_combine_keys);
    jinx_zend_array_release(numeric_values);
    jinx_zend_array_release(numeric_keys);

    JinxZendArray *short_values = jinx_zend_array_new_packed(1);
    if (short_values == 0 || !jinx_zend_array_append(short_values, jinx_zend_long(70))) {
        return fail("array_combine mismatch source");
    }
    args[1] = jinx_oracle_zend_array_value_borrowed(short_values);
    result = jinx_call_builtin_through_oracle("array_combine", args, 2);
    if (result.type != 0u) return fail("array_combine mismatch must fault");
    jinx_zend_array_release(short_values);

    JinxZendArray *count_source = jinx_zend_array_new_packed(5);
    JinxZendString *count_x = jinx_zend_string_new("x", 1);
    if (count_source == 0 || count_x == 0 ||
        !jinx_zend_array_append(count_source, jinx_zend_long(2)) ||
        !jinx_zend_array_append(count_source, jinx_zend_long(2)) ||
        !jinx_zend_array_append(count_source, jinx_zend_string_value(count_x)) ||
        !jinx_zend_array_append(count_source, jinx_zend_string_value(count_x)) ||
        !jinx_zend_array_append(count_source, jinx_zend_string_value(count_x))) {
        return fail("count_values source");
    }
    jinx_zend_string_release(count_x);

    args[0] = jinx_oracle_zend_array_value_borrowed(count_source);
    result = jinx_call_builtin_through_oracle("array_count_values", args, 1);
    if (!expect_array_count(result, 2)) return fail("array_count_values count");
    JinxZendArray *counted = jinx_oracle_zend_array_ptr(result);
    x_slot = jinx_zend_array_find(counted, "x", 1);
    if (!expect_long_index(counted, 2, 2) || x_slot == 0 || x_slot->type != JINX_ZEND_LONG || x_slot->value.lval != 3) {
        return fail("array_count_values values");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *numeric_count_source = jinx_zend_array_new_packed(3);
    JinxZendString *count_2 = jinx_zend_string_new("2", 1);
    JinxZendString *count_02 = jinx_zend_string_new("02", 2);
    if (numeric_count_source == 0 || count_2 == 0 || count_02 == 0 ||
        !jinx_zend_array_append(numeric_count_source, jinx_zend_long(2)) ||
        !jinx_zend_array_append(numeric_count_source, jinx_zend_string_value(count_2)) ||
        !jinx_zend_array_append(numeric_count_source, jinx_zend_string_value(count_02))) {
        return fail("numeric count_values source");
    }
    jinx_zend_string_release(count_2);
    jinx_zend_string_release(count_02);
    args[0] = jinx_oracle_zend_array_value_borrowed(numeric_count_source);
    result = jinx_call_builtin_through_oracle("array_count_values", args, 1);
    if (!expect_array_count(result, 2)) return fail("numeric count_values count");
    JinxZendArray *numeric_counted = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *count_02_slot = jinx_zend_array_find(numeric_counted, "02", 2);
    if (!expect_long_index(numeric_counted, 2, 2) ||
        count_02_slot == 0 || count_02_slot->type != JINX_ZEND_LONG || count_02_slot->value.lval != 1) {
        return fail("numeric count_values coercion");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(numeric_count_source);

    args[0] = jinx_oracle_zend_array_value_borrowed(array);
    args[1] = jinx_oracle_int_value(2);
    args[2] = jinx_oracle_bool_value(0);
    result = jinx_call_builtin_through_oracle("array_chunk", args, 3);
    if (!expect_array_count(result, 2)) return fail("array_chunk outer count");
    JinxZendArray *chunks = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *chunk0_value = jinx_zend_array_index(chunks, 0);
    if (chunk0_value == 0 || chunk0_value->type != JINX_ZEND_ARRAY ||
        !expect_long_index(chunk0_value->value.array, 0, 10) ||
        !expect_long_index(chunk0_value->value.array, 1, 20)) {
        return fail("array_chunk values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zend_array_value_borrowed(array);
    args[1] = jinx_oracle_int_value(6);
    args[2] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("array_pad", args, 3);
    if (!expect_array_count(result, 6)) return fail("array_pad count");
    JinxZendArray *padded = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(padded, 0, 10) || !expect_long_index(padded, 1, 20) ||
        !expect_long_index(padded, 2, 0) || !expect_long_index(padded, 3, 0)) {
        return fail("array_pad values");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *unique_source = jinx_zend_array_new_packed(6);
    JinxZendString *four_string = jinx_zend_string_new("4", 1);
    JinxZendString *three_string = jinx_zend_string_new("3", 1);
    if (unique_source == 0 || four_string == 0 || three_string == 0 ||
        !jinx_zend_array_append(unique_source, jinx_zend_long(4)) ||
        !jinx_zend_array_append(unique_source, jinx_zend_string_value(four_string)) ||
        !jinx_zend_array_append(unique_source, jinx_zend_string_value(three_string)) ||
        !jinx_zend_array_append(unique_source, jinx_zend_long(4)) ||
        !jinx_zend_array_append(unique_source, jinx_zend_long(3)) ||
        !jinx_zend_array_append(unique_source, jinx_zend_string_value(three_string))) {
        return fail("array_unique source");
    }
    jinx_zend_string_release(four_string);
    jinx_zend_string_release(three_string);
    args[0] = jinx_oracle_zend_array_value_borrowed(unique_source);
    result = jinx_call_builtin_through_oracle("array_unique", args, 1);
    if (!expect_array_count(result, 2)) return fail("array_unique count");
    JinxZendArray *unique = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(unique, 0, 4)) return fail("array_unique first key");
    JinxZendValue *unique_three = jinx_zend_array_index(unique, 2);
    if (unique_three == 0 || unique_three->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(unique_three->value.str, "3", 1)) {
        return fail("array_unique preserved key");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(unique_source);

    JinxZendArray *filter = jinx_zend_array_new_packed(2);
    if (filter == 0 ||
        !jinx_zend_array_append(filter, jinx_zend_long(20)) ||
        !jinx_zend_array_append(filter, jinx_zend_long(40))) {
        return fail("array diff/intersect filter");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(array);
    args[1] = jinx_oracle_zend_array_value_borrowed(filter);

    result = jinx_call_builtin_through_oracle("array_diff", args, 2);
    if (!expect_array_count(result, 2)) return fail("array_diff count");
    JinxZendArray *diffed = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(diffed, 0, 10)) return fail("array_diff numeric key");
    JinxZendValue *name_value = jinx_zend_array_find(diffed, "name", 4);
    if (name_value == 0 || name_value->type != JINX_ZEND_LONG || name_value->value.lval != 30) {
        return fail("array_diff string key");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_intersect", args, 2);
    if (!expect_array_count(result, 2)) return fail("array_intersect count");
    JinxZendArray *intersected = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(intersected, 1, 20)) return fail("array_intersect numeric key");
    JinxZendValue *keep_value = jinx_zend_array_find(intersected, "keep", 4);
    if (keep_value == 0 || keep_value->type != JINX_ZEND_LONG || keep_value->value.lval != 40) {
        return fail("array_intersect string key");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *filter2 = jinx_zend_array_new_packed(2);
    if (filter2 == 0 ||
        !jinx_zend_array_append(filter2, jinx_zend_long(40)) ||
        !jinx_zend_array_append(filter2, jinx_zend_long(50))) {
        return fail("array intersect third filter");
    }
    args[2] = jinx_oracle_zend_array_value_borrowed(filter2);
    result = jinx_call_builtin_through_oracle("array_intersect", args, 3);
    if (!expect_array_count(result, 1)) return fail("array_intersect three-array count");
    JinxZendArray *intersected3 = jinx_oracle_zend_array_ptr(result);
    keep_value = jinx_zend_array_find(intersected3, "keep", 4);
    if (keep_value == 0 || keep_value->type != JINX_ZEND_LONG || keep_value->value.lval != 40) {
        return fail("array_intersect must match all arrays");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(filter2);
    jinx_zend_array_release(filter);

    args[0] = jinx_oracle_string_value(",");
    args[1] = jinx_oracle_string_value("a,b,c");
    result = jinx_call_builtin_through_oracle("explode", args, 2);
    if (!expect_array_count(result, 3)) return fail("explode count");
    JinxZendArray *exploded = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(exploded, 0, "a") ||
        !expect_string_index(exploded, 1, "b") ||
        !expect_string_index(exploded, 2, "c")) {
        return fail("explode values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("abcdef");
    args[1] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("str_split", args, 2);
    if (!expect_array_count(result, 3)) return fail("str_split count");
    JinxZendArray *split = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(split, 0, "ab") ||
        !expect_string_index(split, 1, "cd") ||
        !expect_string_index(split, 2, "ef")) {
        return fail("str_split values");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *rows = jinx_zend_array_new_packed(2);
    JinxZendArray *row1 = jinx_zend_array_new_packed(2);
    JinxZendArray *row2 = jinx_zend_array_new_packed(2);
    JinxZendString *ada = jinx_zend_string_new("Ada", 3);
    JinxZendString *grace = jinx_zend_string_new("Grace", 5);
    if (rows == 0 || row1 == 0 || row2 == 0 || ada == 0 || grace == 0 ||
        !jinx_zend_array_add_assoc(row1, "id", 2, jinx_zend_long(1)) ||
        !jinx_zend_array_add_assoc(row1, "name", 4, jinx_zend_string_value(ada)) ||
        !jinx_zend_array_add_assoc(row2, "id", 2, jinx_zend_long(2)) ||
        !jinx_zend_array_add_assoc(row2, "name", 4, jinx_zend_string_value(grace)) ||
        !jinx_zend_array_append(rows, jinx_zend_array_value(row1)) ||
        !jinx_zend_array_append(rows, jinx_zend_array_value(row2))) {
        return fail("array_column source");
    }
    jinx_zend_string_release(ada);
    jinx_zend_string_release(grace);
    jinx_zend_array_release(row1);
    jinx_zend_array_release(row2);

    args[0] = jinx_oracle_zend_array_value_borrowed(rows);
    args[1] = jinx_oracle_string_value("name");
    args[2] = jinx_oracle_string_value("id");
    result = jinx_call_builtin_through_oracle("array_column", args, 3);
    if (!expect_array_count(result, 2)) return fail("array_column count");
    JinxZendArray *column = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(column, 1, "Ada") || !expect_string_index(column, 2, "Grace")) {
        return fail("array_column keyed values");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(rows);

    JinxZendArray *numeric_rows = jinx_zend_array_new_packed(2);
    JinxZendArray *numeric_row1 = jinx_zend_array_new_packed(2);
    JinxZendArray *numeric_row2 = jinx_zend_array_new_packed(2);
    JinxZendString *numeric_id1 = jinx_zend_string_new("1", 1);
    JinxZendString *numeric_id02 = jinx_zend_string_new("02", 2);
    JinxZendString *numeric_ada = jinx_zend_string_new("Ada", 3);
    JinxZendString *numeric_grace = jinx_zend_string_new("Grace", 5);
    if (numeric_rows == 0 || numeric_row1 == 0 || numeric_row2 == 0 ||
        numeric_id1 == 0 || numeric_id02 == 0 || numeric_ada == 0 || numeric_grace == 0 ||
        !jinx_zend_array_add_assoc(numeric_row1, "id", 2, jinx_zend_string_value(numeric_id1)) ||
        !jinx_zend_array_add_assoc(numeric_row1, "name", 4, jinx_zend_string_value(numeric_ada)) ||
        !jinx_zend_array_add_assoc(numeric_row2, "id", 2, jinx_zend_string_value(numeric_id02)) ||
        !jinx_zend_array_add_assoc(numeric_row2, "name", 4, jinx_zend_string_value(numeric_grace)) ||
        !jinx_zend_array_append(numeric_rows, jinx_zend_array_value(numeric_row1)) ||
        !jinx_zend_array_append(numeric_rows, jinx_zend_array_value(numeric_row2))) {
        return fail("numeric array_column source");
    }
    jinx_zend_string_release(numeric_id1);
    jinx_zend_string_release(numeric_id02);
    jinx_zend_string_release(numeric_ada);
    jinx_zend_string_release(numeric_grace);
    jinx_zend_array_release(numeric_row1);
    jinx_zend_array_release(numeric_row2);

    args[0] = jinx_oracle_zend_array_value_borrowed(numeric_rows);
    args[1] = jinx_oracle_string_value("name");
    args[2] = jinx_oracle_string_value("id");
    result = jinx_call_builtin_through_oracle("array_column", args, 3);
    if (!expect_array_count(result, 2)) return fail("numeric array_column count");
    JinxZendArray *numeric_column = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *numeric_grace_slot = jinx_zend_array_find(numeric_column, "02", 2);
    if (!expect_string_index(numeric_column, 1, "Ada") ||
        numeric_grace_slot == 0 || numeric_grace_slot->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(numeric_grace_slot->value.str, "Grace", 5)) {
        return fail("numeric array_column key coercion");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(numeric_rows);

    args[0] = jinx_oracle_string_value("20");
    args[1] = jinx_oracle_zend_array_value_borrowed(array);
    args[2] = jinx_oracle_bool_value(0);
    if (!expect_bool(jinx_call_builtin_through_oracle("in_array", args, 3), 1)) {
        return fail("in_array loose scalar");
    }
    args[2] = jinx_oracle_bool_value(1);
    if (!expect_bool(jinx_call_builtin_through_oracle("in_array", args, 3), 0)) {
        return fail("in_array strict scalar");
    }

    args[0] = jinx_oracle_string_value("30");
    args[1] = jinx_oracle_zend_array_value_borrowed(array);
    args[2] = jinx_oracle_bool_value(0);
    result = jinx_call_builtin_through_oracle("array_search", args, 3);
    if (!expect_string(result, "name")) return fail("array_search loose key");
    args[2] = jinx_oracle_bool_value(1);
    result = jinx_call_builtin_through_oracle("array_search", args, 3);
    if (!expect_bool(result, 0)) return fail("array_search strict miss");

    JinxZendArray *filter_source = jinx_zend_array_new_packed(5);
    JinxZendString *filter_zero = jinx_zend_string_new("0", 1);
    JinxZendString *filter_x = jinx_zend_string_new("x", 1);
    if (filter_source == 0 || filter_zero == 0 || filter_x == 0 ||
        !jinx_zend_array_append(filter_source, jinx_zend_long(0)) ||
        !jinx_zend_array_append(filter_source, jinx_zend_long(1)) ||
        !jinx_zend_array_append(filter_source, jinx_zend_string_value(filter_zero)) ||
        !jinx_zend_array_append(filter_source, jinx_zend_string_value(filter_x)) ||
        !jinx_zend_array_append(filter_source, jinx_zend_bool(0))) {
        return fail("array_filter source");
    }
    jinx_zend_string_release(filter_zero);
    jinx_zend_string_release(filter_x);
    args[0] = jinx_oracle_zend_array_value_borrowed(filter_source);
    result = jinx_call_builtin_through_oracle("array_filter", args, 1);
    if (!expect_array_count(result, 2)) return fail("array_filter default count");
    JinxZendArray *filtered = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(filtered, 1, 1) || !expect_string_index(filtered, 3, "x")) {
        return fail("array_filter default key preservation");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(filter_source);

    jinx_zend_array_release(count_source);
    jinx_zend_array_release(value_array);
    jinx_zend_array_release(key_array);
    jinx_zend_array_release(other);
    jinx_zend_array_release(array);

    printf("PARITY:recursive=4;keys_loose=1;keys_strict=;sum=100;product=240000;words=2;words1=Hello,world;words2=0:Hello,7:world;words_digits=1;implode=10,20,30,40;vsprintf=There are 7 million bicycles in Amsterdam.;range=1,2,3,4,5;range_neg=5,3,1;fill=2:9,3:9,4:9;fill_zero=0;combine=2:70,x:80;fill_keys_num=2:9,02:9,+2:9,-2:9;combine_num=2:70,02:80;count_values=2:2,x:3;count_values_num=2:2,02:1;chunk0=10,20;pad=10,20,30,40,0,0;unique=0:4,2:3;diff=0:10,name:30;intersect=1:20,keep:40;intersect3=keep:40;explode=a,b,c;split=ab,cd,ef;column=1:Ada,2:Grace;column_num=1:Ada,02:Grace;in_loose=1;in_strict=0;search_loose=name;search_strict=false;filter=1:1,3:x\n");
    printf("PASS: Oracle generated dispatch Zend-array native core passed\n");
    return 0;
}
