#include <stdio.h>
#include <string.h>
#include <math.h>

#include "../runtime/jinx_builtin_dispatch.h"
#include "../runtime/jinx_oracle_zend_array_carrier.h"
#include "../runtime/jinx_zend_array_delete.h"

static int expect_int(JinxValue value, long long expected) {
    return value.type == 1u && value.as.i64 == expected;
}

static int expect_bool(JinxValue value, int expected) {
    return value.type == 2u && value.as.i64 == (expected ? 1 : 0);
}

static int expect_float(JinxValue value, double expected) {
    return value.type == 5u && fabs(value.as.f64 - expected) < 1e-12;
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

static int expect_string_key(JinxZendArray *array, const char *key, const char *expected) {
    size_t key_len = strlen(key);
    size_t expected_len = strlen(expected);
    JinxZendValue *slot = jinx_zend_array_find(array, key, key_len);
    return slot != 0 && slot->type == JINX_ZEND_STRING && slot->value.str != 0 &&
        slot->value.str->len == expected_len &&
        memcmp(slot->value.str->bytes, expected, expected_len) == 0;
}

static int expect_long_key(JinxZendArray *array, const char *key, long long expected) {
    JinxZendValue *slot = jinx_zend_array_find(array, key, strlen(key));
    return slot != 0 && slot->type == JINX_ZEND_LONG && slot->value.lval == expected;
}


static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxZendArray *other = jinx_zend_array_new_packed(2);
    JinxValue args[8];
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

    JinxZendArray *numeric_extrema = jinx_zend_array_new_packed(3);
    if (numeric_extrema == 0 ||
        !jinx_zend_array_append(numeric_extrema, jinx_zend_long(2)) ||
        !jinx_zend_array_append(numeric_extrema, jinx_zend_double(3.5)) ||
        !jinx_zend_array_append(numeric_extrema, jinx_zend_long(3))) {
        return fail("numeric min/max source");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(numeric_extrema);
    if (!expect_int(jinx_call_builtin_through_oracle("min", args, 1), 2)) return fail("array min");
    if (!expect_float(jinx_call_builtin_through_oracle("max", args, 1), 3.5)) return fail("array max");
    jinx_zend_array_release(numeric_extrema);

    JinxZendArray *numeric_tie = jinx_zend_array_new_packed(2);
    if (numeric_tie == 0 ||
        !jinx_zend_array_append(numeric_tie, jinx_zend_long(4)) ||
        !jinx_zend_array_append(numeric_tie, jinx_zend_double(4.0))) {
        return fail("numeric min/max tie source");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(numeric_tie);
    if (!expect_int(jinx_call_builtin_through_oracle("max", args, 1), 4)) return fail("array max tie type");
    jinx_zend_array_release(numeric_tie);

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

    args[0] = jinx_oracle_string_value("vprintf:%u:%s\n");
    result = jinx_call_builtin_through_oracle("vprintf", args, 2);
    if (!expect_int(result, 20)) return fail("vprintf return length");

    jinx_zend_array_release(vs_values);

    args[0] = jinx_oracle_string_value("bafoobar");
    args[1] = jinx_oracle_string_value("barfoo");
    args[2] = jinx_oracle_float_value(0.0);
    result = jinx_call_builtin_through_oracle("similar_text", args, 3);
    if (!expect_int(result, 5)) return fail("similar_text return");
    if (args[2].type != 5u || fabs(args[2].as.f64 - 71.42857142857143) > 1e-12) {
        return fail("similar_text by-reference percent");
    }

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

    JinxZendArray *mutation = jinx_zend_array_new_packed(4);
    if (mutation == 0 ||
        !jinx_zend_array_append(mutation, jinx_zend_long(10)) ||
        !jinx_zend_array_add_assoc(mutation, "x", 1, jinx_zend_long(20)) ||
        !jinx_zend_array_add_index(mutation, 2, jinx_zend_long(30))) {
        return fail("array mutation source");
    }

    args[0] = jinx_oracle_zend_array_value_borrowed(mutation);
    args[1] = jinx_oracle_int_value(40);
    result = jinx_call_builtin_through_oracle("array_push", args, 2);
    if (!expect_int(result, 4) || !expect_long_index(mutation, 3, 40)) {
        return fail("array_push mutation");
    }

    result = jinx_call_builtin_through_oracle("array_pop", args, 1);
    if (!expect_int(result, 40) || jinx_zend_array_live_count(mutation) != 3u) {
        return fail("array_pop mutation");
    }

    args[1] = jinx_oracle_int_value(50);
    result = jinx_call_builtin_through_oracle("array_push", args, 2);
    if (!expect_int(result, 4) || !expect_long_index(mutation, 3, 50)) {
        return fail("array_push after pop next index");
    }

    result = jinx_call_builtin_through_oracle("array_shift", args, 1);
    if (!expect_int(result, 10) || jinx_zend_array_live_count(mutation) != 3u) {
        return fail("array_shift mutation");
    }
    JinxZendValue *mutation_x = jinx_zend_array_find(mutation, "x", 1);
    if (mutation_x == 0 || mutation_x->type != JINX_ZEND_LONG || mutation_x->value.lval != 20 ||
        !expect_long_index(mutation, 0, 30) || !expect_long_index(mutation, 1, 50)) {
        return fail("array_shift reindex");
    }

    args[1] = jinx_oracle_int_value(5);
    args[2] = jinx_oracle_int_value(6);
    result = jinx_call_builtin_through_oracle("array_unshift", args, 3);
    if (!expect_int(result, 5) ||
        !expect_long_index(mutation, 0, 5) ||
        !expect_long_index(mutation, 1, 6) ||
        !expect_long_index(mutation, 2, 30) ||
        !expect_long_index(mutation, 3, 50)) {
        return fail("array_unshift reindex");
    }
    mutation_x = jinx_zend_array_find(mutation, "x", 1);
    if (mutation_x == 0 || mutation_x->type != JINX_ZEND_LONG || mutation_x->value.lval != 20) {
        return fail("array_unshift string key preservation");
    }
    jinx_zend_array_release(mutation);

    JinxZendArray *splice_source = jinx_zend_array_new_packed(6);
    JinxZendArray *splice_replacement = jinx_zend_array_new_packed(2);
    if (splice_source == 0 || splice_replacement == 0 ||
        !jinx_zend_array_append(splice_source, jinx_zend_long(10)) ||
        !jinx_zend_array_add_assoc(splice_source, "keep", 4, jinx_zend_long(20)) ||
        !jinx_zend_array_add_index(splice_source, 2, jinx_zend_long(30)) ||
        !jinx_zend_array_add_assoc(splice_source, "tail", 4, jinx_zend_long(40)) ||
        !jinx_zend_array_add_index(splice_source, 5, jinx_zend_long(50)) ||
        !jinx_zend_array_append(splice_replacement, jinx_zend_long(70)) ||
        !jinx_zend_array_append(splice_replacement, jinx_zend_long(80))) {
        return fail("array_splice source");
    }

    args[0] = jinx_oracle_zend_array_value_borrowed(splice_source);
    args[1] = jinx_oracle_int_value(2);
    args[2] = jinx_oracle_int_value(2);
    args[3] = jinx_oracle_zend_array_value_borrowed(splice_replacement);
    result = jinx_call_builtin_through_oracle("array_splice", args, 4);

    if (!expect_array_count(result, 2)) return fail("array_splice removed count");
    JinxZendArray *removed = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *removed_tail = jinx_zend_array_find(removed, "tail", 4);
    if (!expect_long_index(removed, 0, 30) ||
        removed_tail == 0 || removed_tail->type != JINX_ZEND_LONG || removed_tail->value.lval != 40) {
        return fail("array_splice removed values");
    }

    JinxZendValue *splice_keep = jinx_zend_array_find(splice_source, "keep", 4);
    if (jinx_zend_array_live_count(splice_source) != 5u ||
        !expect_long_index(splice_source, 0, 10) ||
        splice_keep == 0 || splice_keep->type != JINX_ZEND_LONG || splice_keep->value.lval != 20 ||
        !expect_long_index(splice_source, 1, 70) ||
        !expect_long_index(splice_source, 2, 80) ||
        !expect_long_index(splice_source, 3, 50)) {
        return fail("array_splice rebuilt values");
    }

    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(splice_replacement);
    jinx_zend_array_release(splice_source);

    JinxZendArray *merge_color1 = jinx_zend_array_new_packed(2);
    JinxZendArray *merge_color2 = jinx_zend_array_new_packed(2);
    JinxZendArray *merge_a = jinx_zend_array_new_packed(2);
    JinxZendArray *merge_b = jinx_zend_array_new_packed(2);
    JinxZendString *red = jinx_zend_string_new("red", 3);
    JinxZendString *green = jinx_zend_string_new("green", 5);
    JinxZendString *blue = jinx_zend_string_new("blue", 4);
    if (merge_color1 == 0 || merge_color2 == 0 || merge_a == 0 || merge_b == 0 ||
        red == 0 || green == 0 || blue == 0 ||
        !jinx_zend_array_add_assoc(merge_color1, "favorite", 8, jinx_zend_string_value(red)) ||
        !jinx_zend_array_add_assoc(merge_color2, "favorite", 8, jinx_zend_string_value(green)) ||
        !jinx_zend_array_append(merge_color2, jinx_zend_string_value(blue)) ||
        !jinx_zend_array_add_assoc(merge_a, "color", 5, jinx_zend_array_value(merge_color1)) ||
        !jinx_zend_array_append(merge_a, jinx_zend_long(5)) ||
        !jinx_zend_array_append(merge_b, jinx_zend_long(10)) ||
        !jinx_zend_array_add_assoc(merge_b, "color", 5, jinx_zend_array_value(merge_color2))) {
        return fail("array_merge_recursive source");
    }
    jinx_zend_string_release(red);
    jinx_zend_string_release(green);
    jinx_zend_string_release(blue);

    args[0] = jinx_oracle_zend_array_value_borrowed(merge_a);
    args[1] = jinx_oracle_zend_array_value_borrowed(merge_b);
    result = jinx_call_builtin_through_oracle("array_merge_recursive", args, 2);
    if (!expect_array_count(result, 3)) return fail("array_merge_recursive outer count");
    JinxZendArray *merge_result = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *merged_color_value = jinx_zend_array_find(merge_result, "color", 5);
    if (merged_color_value == 0 || merged_color_value->type != JINX_ZEND_ARRAY) {
        return fail("array_merge_recursive color");
    }
    JinxZendArray *merged_color = merged_color_value->value.array;
    JinxZendValue *favorite_value = jinx_zend_array_find(merged_color, "favorite", 8);
    if (favorite_value == 0 || favorite_value->type != JINX_ZEND_ARRAY ||
        !expect_string_index(favorite_value->value.array, 0, "red") ||
        !expect_string_index(favorite_value->value.array, 1, "green") ||
        !expect_string_index(merged_color, 0, "blue") ||
        !expect_long_index(merge_result, 0, 5) ||
        !expect_long_index(merge_result, 1, 10)) {
        return fail("array_merge_recursive nested values");
    }
    JinxZendValue *source_favorite = jinx_zend_array_find(merge_color1, "favorite", 8);
    if (source_favorite == 0 || source_favorite->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(source_favorite->value.str, "red", 3)) {
        return fail("array_merge_recursive mutated source");
    }
    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(merge_a);
    jinx_zend_array_release(merge_b);
    jinx_zend_array_release(merge_color1);
    jinx_zend_array_release(merge_color2);

    JinxZendArray *base_citrus = jinx_zend_array_new_packed(2);
    JinxZendArray *base_pome = jinx_zend_array_new_packed(1);
    JinxZendArray *replace_base = jinx_zend_array_new_packed(2);
    JinxZendArray *rep1_citrus = jinx_zend_array_new_packed(1);
    JinxZendArray *replace_one = jinx_zend_array_new_packed(1);
    JinxZendArray *rep2_citrus = jinx_zend_array_new_packed(2);
    JinxZendArray *rep2_pome = jinx_zend_array_new_packed(1);
    JinxZendArray *replace_two = jinx_zend_array_new_packed(2);
    JinxZendString *orange = jinx_zend_string_new("orange", 6);
    JinxZendString *lemon = jinx_zend_string_new("lemon", 5);
    JinxZendString *apple = jinx_zend_string_new("apple", 5);
    JinxZendString *grapefruit = jinx_zend_string_new("grapefruit", 10);
    JinxZendString *kumquat = jinx_zend_string_new("kumquat", 7);
    JinxZendString *citron = jinx_zend_string_new("citron", 6);
    JinxZendString *loquat = jinx_zend_string_new("loquat", 6);

    if (base_citrus == 0 || base_pome == 0 || replace_base == 0 ||
        rep1_citrus == 0 || replace_one == 0 || rep2_citrus == 0 ||
        rep2_pome == 0 || replace_two == 0 || orange == 0 || lemon == 0 ||
        apple == 0 || grapefruit == 0 || kumquat == 0 || citron == 0 || loquat == 0 ||
        !jinx_zend_array_append(base_citrus, jinx_zend_string_value(orange)) ||
        !jinx_zend_array_append(base_citrus, jinx_zend_string_value(lemon)) ||
        !jinx_zend_array_append(base_pome, jinx_zend_string_value(apple)) ||
        !jinx_zend_array_add_assoc(replace_base, "citrus", 6, jinx_zend_array_value(base_citrus)) ||
        !jinx_zend_array_add_assoc(replace_base, "pome", 4, jinx_zend_array_value(base_pome)) ||
        !jinx_zend_array_append(rep1_citrus, jinx_zend_string_value(grapefruit)) ||
        !jinx_zend_array_add_assoc(replace_one, "citrus", 6, jinx_zend_array_value(rep1_citrus)) ||
        !jinx_zend_array_append(rep2_citrus, jinx_zend_string_value(kumquat)) ||
        !jinx_zend_array_append(rep2_citrus, jinx_zend_string_value(citron)) ||
        !jinx_zend_array_append(rep2_pome, jinx_zend_string_value(loquat)) ||
        !jinx_zend_array_add_assoc(replace_two, "citrus", 6, jinx_zend_array_value(rep2_citrus)) ||
        !jinx_zend_array_add_assoc(replace_two, "pome", 4, jinx_zend_array_value(rep2_pome))) {
        return fail("array_replace_recursive source");
    }

    jinx_zend_string_release(orange);
    jinx_zend_string_release(lemon);
    jinx_zend_string_release(apple);
    jinx_zend_string_release(grapefruit);
    jinx_zend_string_release(kumquat);
    jinx_zend_string_release(citron);
    jinx_zend_string_release(loquat);

    args[0] = jinx_oracle_zend_array_value_borrowed(replace_base);
    args[1] = jinx_oracle_zend_array_value_borrowed(replace_one);
    args[2] = jinx_oracle_zend_array_value_borrowed(replace_two);
    result = jinx_call_builtin_through_oracle("array_replace_recursive", args, 3);
    if (!expect_array_count(result, 2)) return fail("array_replace_recursive outer count");
    JinxZendArray *replace_result = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *citrus_value = jinx_zend_array_find(replace_result, "citrus", 6);
    JinxZendValue *pome_value = jinx_zend_array_find(replace_result, "pome", 4);
    if (citrus_value == 0 || citrus_value->type != JINX_ZEND_ARRAY ||
        pome_value == 0 || pome_value->type != JINX_ZEND_ARRAY ||
        !expect_string_index(citrus_value->value.array, 0, "kumquat") ||
        !expect_string_index(citrus_value->value.array, 1, "citron") ||
        !expect_string_index(pome_value->value.array, 0, "loquat") ||
        !expect_string_index(base_citrus, 0, "orange") ||
        !expect_string_index(base_citrus, 1, "lemon")) {
        return fail("array_replace_recursive nested values");
    }

    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(replace_base);
    jinx_zend_array_release(replace_one);
    jinx_zend_array_release(replace_two);
    jinx_zend_array_release(base_citrus);
    jinx_zend_array_release(base_pome);
    jinx_zend_array_release(rep1_citrus);
    jinx_zend_array_release(rep2_citrus);
    jinx_zend_array_release(rep2_pome);

    args[0] = jinx_oracle_string_value("a,b,c");
    args[1] = jinx_oracle_string_value(",");
    args[2] = jinx_oracle_string_value("\"");
    args[3] = jinx_oracle_string_value("\\");
    result = jinx_call_builtin_through_oracle("str_getcsv", args, 4);
    if (!expect_array_count(result, 3)) return fail("str_getcsv simple count");
    JinxZendArray *csv = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(csv, 0, "a") ||
        !expect_string_index(csv, 1, "b") ||
        !expect_string_index(csv, 2, "c")) {
        return fail("str_getcsv simple values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value(" \"a,b\",c");
    result = jinx_call_builtin_through_oracle("str_getcsv", args, 4);
    if (!expect_array_count(result, 2)) return fail("str_getcsv quoted count");
    csv = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(csv, 0, "a,b") || !expect_string_index(csv, 1, "c")) {
        return fail("str_getcsv quoted values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("\"a\"\"b\",\"c\\\"d\"");
    result = jinx_call_builtin_through_oracle("str_getcsv", args, 4);
    if (!expect_array_count(result, 2)) return fail("str_getcsv escaped count");
    csv = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(csv, 0, "a\"b") || !expect_string_index(csv, 1, "c\\\"d")) {
        return fail("str_getcsv escaped values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("a,\r\n");
    result = jinx_call_builtin_through_oracle("str_getcsv", args, 4);
    if (!expect_array_count(result, 2)) return fail("str_getcsv trailing empty count");
    csv = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(csv, 0, "a") || !expect_string_index(csv, 1, "")) {
        return fail("str_getcsv trailing empty values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("");
    result = jinx_call_builtin_through_oracle("str_getcsv", args, 4);
    if (!expect_array_count(result, 1)) return fail("str_getcsv empty count");
    csv = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *csv_empty = jinx_zend_array_index(csv, 0);
    if (csv_empty == 0 || csv_empty->type != JINX_ZEND_NULL) {
        return fail("str_getcsv empty must return null field");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("\"unterminated\n");
    args[1] = jinx_oracle_string_value(",");
    result = jinx_call_builtin_through_oracle("str_getcsv", args, 4);
    if (!expect_array_count(result, 1)) return fail("str_getcsv unterminated quoted count");
    csv = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_index(csv, 0, "unterminated\n")) {
        return fail("str_getcsv unterminated quoted newline");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("a,b");
    args[1] = jinx_oracle_string_value("::");
    result = jinx_call_builtin_through_oracle("str_getcsv", args, 4);
    if (result.type != 0u) return fail("str_getcsv invalid separator must fault");

    JinxZendArray *allowed_tags = jinx_zend_array_new_packed(2);
    JinxZendString *tag_p = jinx_zend_string_new("p", 1);
    JinxZendString *tag_a = jinx_zend_string_new("a", 1);
    if (allowed_tags == 0 || tag_p == 0 || tag_a == 0 ||
        !jinx_zend_array_append(allowed_tags, jinx_zend_string_value(tag_p)) ||
        !jinx_zend_array_append(allowed_tags, jinx_zend_string_value(tag_a))) {
        return fail("strip_tags allow-list source");
    }
    jinx_zend_string_release(tag_p);
    jinx_zend_string_release(tag_a);

    args[0] = jinx_oracle_string_value("<p>Test paragraph.</p><!-- Comment --> <a href=\"#fragment\">Other text</a>");
    args[1] = jinx_oracle_zend_array_value_borrowed(allowed_tags);
    result = jinx_call_builtin_through_oracle("strip_tags", args, 2);
    if (!expect_string(result, "<p>Test paragraph.</p> <a href=\"#fragment\">Other text</a>")) {
        return fail("strip_tags array allow-list");
    }
    jinx_zend_array_release(allowed_tags);

    args[0] = jinx_oracle_string_value("http://username:password@hostname:9090/path?arg=value#anchor");
    result = jinx_call_builtin_through_oracle("parse_url", args, 1);
    if (!expect_array_count(result, 8)) return fail("parse_url full count");
    JinxZendArray *parsed = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *url_scheme = jinx_zend_array_find(parsed, "scheme", 6);
    JinxZendValue *url_host = jinx_zend_array_find(parsed, "host", 4);
    JinxZendValue *url_port = jinx_zend_array_find(parsed, "port", 4);
    JinxZendValue *url_user = jinx_zend_array_find(parsed, "user", 4);
    JinxZendValue *url_pass = jinx_zend_array_find(parsed, "pass", 4);
    JinxZendValue *url_path = jinx_zend_array_find(parsed, "path", 4);
    JinxZendValue *url_query = jinx_zend_array_find(parsed, "query", 5);
    JinxZendValue *url_fragment = jinx_zend_array_find(parsed, "fragment", 8);
    if (url_scheme == 0 || url_scheme->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_scheme->value.str, "http", 4) ||
        url_host == 0 || url_host->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_host->value.str, "hostname", 8) ||
        url_port == 0 || url_port->type != JINX_ZEND_LONG || url_port->value.lval != 9090 ||
        url_user == 0 || url_user->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_user->value.str, "username", 8) ||
        url_pass == 0 || url_pass->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_pass->value.str, "password", 8) ||
        url_path == 0 || url_path->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_path->value.str, "/path", 5) ||
        url_query == 0 || url_query->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_query->value.str, "arg=value", 9) ||
        url_fragment == 0 || url_fragment->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_fragment->value.str, "anchor", 6)) {
        return fail("parse_url full components");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("//www.example.com/path?googleguy=googley");
    result = jinx_call_builtin_through_oracle("parse_url", args, 1);
    if (!expect_array_count(result, 3)) return fail("parse_url schemeless count");
    parsed = jinx_oracle_zend_array_ptr(result);
    url_host = jinx_zend_array_find(parsed, "host", 4);
    url_path = jinx_zend_array_find(parsed, "path", 4);
    url_query = jinx_zend_array_find(parsed, "query", 5);
    if (url_host == 0 || url_host->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_host->value.str, "www.example.com", 15) ||
        url_path == 0 || url_path->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_path->value.str, "/path", 5) ||
        url_query == 0 || url_query->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(url_query->value.str, "googleguy=googley", 18)) {
        return fail("parse_url schemeless components");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("http://example.com/path?#");
    result = jinx_call_builtin_through_oracle("parse_url", args, 1);
    if (!expect_array_count(result, 5)) return fail("parse_url empty query fragment count");
    parsed = jinx_oracle_zend_array_ptr(result);
    url_query = jinx_zend_array_find(parsed, "query", 5);
    url_fragment = jinx_zend_array_find(parsed, "fragment", 8);
    if (url_query == 0 || url_query->type != JINX_ZEND_STRING || url_query->value.str->len != 0u ||
        url_fragment == 0 || url_fragment->type != JINX_ZEND_STRING || url_fragment->value.str->len != 0u) {
        return fail("parse_url empty query fragment");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("http://example.com:8080/a");
    args[1] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("parse_url", args, 2);
    if (!expect_string(result, "example.com")) return fail("parse_url host component");
    args[1] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("parse_url", args, 2);
    if (!expect_int(result, 8080)) return fail("parse_url port component");

    JinxZendArray *query_user = jinx_zend_array_new_packed(2);
    JinxZendArray *query_data = jinx_zend_array_new_packed(5);
    JinxZendString *bob_smith = jinx_zend_string_new("Bob Smith", 9);
    JinxZendString *ceo = jinx_zend_string_new("CEO", 3);
    if (query_user == 0 || query_data == 0 || bob_smith == 0 || ceo == 0 ||
        !jinx_zend_array_add_assoc(query_user, "name", 4, jinx_zend_string_value(bob_smith)) ||
        !jinx_zend_array_add_assoc(query_user, "age", 3, jinx_zend_long(47)) ||
        !jinx_zend_array_add_assoc(query_data, "user", 4, jinx_zend_array_value(query_user)) ||
        !jinx_zend_array_add_index(query_data, 0, jinx_zend_string_value(ceo)) ||
        !jinx_zend_array_add_assoc(query_data, "flag", 4, jinx_zend_bool(0)) ||
        !jinx_zend_array_add_assoc(query_data, "skip", 4, jinx_zend_null())) {
        return fail("http_build_query source");
    }
    jinx_zend_string_release(bob_smith);
    jinx_zend_string_release(ceo);
    jinx_zend_array_release(query_user);

    args[0] = jinx_oracle_zend_array_value_borrowed(query_data);
    args[1] = jinx_oracle_string_value("flags_");
    args[2] = jinx_oracle_zero_value();
    args[3] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("http_build_query", args, 4);
    if (!expect_string(result, "user%5Bname%5D=Bob+Smith&user%5Bage%5D=47&flags_0=CEO&flag=0")) {
        return fail("http_build_query RFC1738");
    }

    args[2] = jinx_oracle_string_value(";");
    result = jinx_call_builtin_through_oracle("http_build_query", args, 4);
    if (!expect_string(result, "user%5Bname%5D=Bob+Smith;user%5Bage%5D=47;flags_0=CEO;flag=0")) {
        return fail("http_build_query separator");
    }

    args[2] = jinx_oracle_zero_value();
    args[3] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("http_build_query", args, 4);
    if (!expect_string(result, "user%5Bname%5D=Bob%20Smith&user%5Bage%5D=47&flags_0=CEO&flag=0")) {
        return fail("http_build_query RFC3986");
    }
    jinx_zend_array_release(query_data);

    result = jinx_call_builtin_through_oracle("localeconv", args, 0);
    if (!expect_array_count(result, 18)) return fail("localeconv field count");
    JinxZendArray *locale_info = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *locale_decimal = jinx_zend_array_find(locale_info, "decimal_point", 13);
    JinxZendValue *locale_thousands = jinx_zend_array_find(locale_info, "thousands_sep", 13);
    JinxZendValue *locale_frac = jinx_zend_array_find(locale_info, "frac_digits", 11);
    JinxZendValue *locale_grouping = jinx_zend_array_find(locale_info, "grouping", 8);
    JinxZendValue *locale_mon_grouping = jinx_zend_array_find(locale_info, "mon_grouping", 12);
    if (locale_decimal == 0 || locale_decimal->type != JINX_ZEND_STRING ||
        locale_thousands == 0 || locale_thousands->type != JINX_ZEND_STRING ||
        locale_frac == 0 || locale_frac->type != JINX_ZEND_LONG ||
        locale_grouping == 0 || locale_grouping->type != JINX_ZEND_ARRAY ||
        locale_mon_grouping == 0 || locale_mon_grouping->type != JINX_ZEND_ARRAY) {
        return fail("localeconv field types");
    }
    printf(
        "LOCALE:decimal=%.*s;thousands=%.*s;frac=%lld;grouping=%zu;mon_grouping=%zu\n",
        (int)locale_decimal->value.str->len,
        locale_decimal->value.str->bytes,
        (int)locale_thousands->value.str->len,
        locale_thousands->value.str->bytes,
        (long long)locale_frac->value.lval,
        jinx_zend_array_live_count(locale_grouping->value.array),
        jinx_zend_array_live_count(locale_mon_grouping->value.array)
    );
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("/www/htdocs/inc/lib.inc.php");
    result = jinx_call_builtin_through_oracle("pathinfo", args, 1);
    if (!expect_array_count(result, 4)) return fail("pathinfo full count");
    JinxZendArray *path_parts = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *pi_dir = jinx_zend_array_find(path_parts, "dirname", 7);
    JinxZendValue *pi_base = jinx_zend_array_find(path_parts, "basename", 8);
    JinxZendValue *pi_ext = jinx_zend_array_find(path_parts, "extension", 9);
    JinxZendValue *pi_file = jinx_zend_array_find(path_parts, "filename", 8);
    if (pi_dir == 0 || pi_dir->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(pi_dir->value.str, "/www/htdocs/inc", 15) ||
        pi_base == 0 || pi_base->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(pi_base->value.str, "lib.inc.php", 11) ||
        pi_ext == 0 || pi_ext->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(pi_ext->value.str, "php", 3) ||
        pi_file == 0 || pi_file->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(pi_file->value.str, "lib.inc", 7)) {
        return fail("pathinfo full values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("/some/path/.test");
    result = jinx_call_builtin_through_oracle("pathinfo", args, 1);
    if (!expect_array_count(result, 4)) return fail("pathinfo dotfile count");
    path_parts = jinx_oracle_zend_array_ptr(result);
    pi_ext = jinx_zend_array_find(path_parts, "extension", 9);
    pi_file = jinx_zend_array_find(path_parts, "filename", 8);
    if (pi_ext == 0 || pi_ext->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(pi_ext->value.str, "test", 4) ||
        pi_file == 0 || pi_file->type != JINX_ZEND_STRING || pi_file->value.str->len != 0u) {
        return fail("pathinfo dotfile values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("/path/noextension");
    result = jinx_call_builtin_through_oracle("pathinfo", args, 1);
    if (!expect_array_count(result, 3)) return fail("pathinfo no-extension count");
    path_parts = jinx_oracle_zend_array_ptr(result);
    if (jinx_zend_array_find(path_parts, "extension", 9) != 0) {
        return fail("pathinfo no-extension key");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_string_value("/www/htdocs/inc/lib.inc.php");
    args[1] = jinx_oracle_int_value(1);
    if (!expect_string(jinx_call_builtin_through_oracle("pathinfo", args, 2), "/www/htdocs/inc")) return fail("pathinfo dirname flag");
    args[1] = jinx_oracle_int_value(2);
    if (!expect_string(jinx_call_builtin_through_oracle("pathinfo", args, 2), "lib.inc.php")) return fail("pathinfo basename flag");
    args[1] = jinx_oracle_int_value(4);
    if (!expect_string(jinx_call_builtin_through_oracle("pathinfo", args, 2), "php")) return fail("pathinfo extension flag");
    args[1] = jinx_oracle_int_value(8);
    if (!expect_string(jinx_call_builtin_through_oracle("pathinfo", args, 2), "lib.inc")) return fail("pathinfo filename flag");

    args[0] = jinx_oracle_string_value("first=value&arr[]=foo+bar&arr[]=baz&My+Value=Something&nested[x][0]=yes");
    args[1] = jinx_oracle_zero_value();
    result = jinx_call_builtin_through_oracle("parse_str", args, 2);
    if (result.type != 0u || !jinx_oracle_value_is_zend_array(args[1])) {
        return fail("parse_str return/by-ref carrier");
    }
    JinxZendArray *parsed_query = jinx_oracle_zend_array_ptr(args[1]);
    JinxZendValue *first_value = jinx_zend_array_find(parsed_query, "first", 5);
    JinxZendValue *mangled_value = jinx_zend_array_find(parsed_query, "My_Value", 8);
    JinxZendValue *arr_value = jinx_zend_array_find(parsed_query, "arr", 3);
    JinxZendValue *nested_value = jinx_zend_array_find(parsed_query, "nested", 6);
    if (first_value == 0 || first_value->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(first_value->value.str, "value", 5) ||
        mangled_value == 0 || mangled_value->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(mangled_value->value.str, "Something", 9) ||
        arr_value == 0 || arr_value->type != JINX_ZEND_ARRAY ||
        !expect_string_index(arr_value->value.array, 0, "foo bar") ||
        !expect_string_index(arr_value->value.array, 1, "baz") ||
        nested_value == 0 || nested_value->type != JINX_ZEND_ARRAY) {
        return fail("parse_str common fields");
    }
    JinxZendValue *nested_x = jinx_zend_array_find(nested_value->value.array, "x", 1);
    if (nested_x == 0 || nested_x->type != JINX_ZEND_ARRAY ||
        !expect_string_index(nested_x->value.array, 0, "yes")) {
        return fail("parse_str nested bracket fields");
    }
    jinx_oracle_zend_array_value_release(args[1]);
    args[1] = jinx_oracle_zero_value();

    JinxZendArray *query_data = jinx_zend_array_new_packed(6);
    JinxZendArray *query_user = jinx_zend_array_new_packed(2);
    JinxZendString *query_bar = jinx_zend_string_new("bar", 3);
    JinxZendString *query_php = jinx_zend_string_new("hypertext processor", 19);
    JinxZendString *query_ceo = jinx_zend_string_new("CEO", 3);
    JinxZendString *query_bob = jinx_zend_string_new("Bob Smith", 9);
    if (query_data == 0 || query_user == 0 || query_bar == 0 || query_php == 0 ||
        query_ceo == 0 || query_bob == 0 ||
        !jinx_zend_array_add_assoc(query_data, "foo", 3, jinx_zend_string_value(query_bar)) ||
        !jinx_zend_array_add_assoc(query_data, "php", 3, jinx_zend_string_value(query_php)) ||
        !jinx_zend_array_add_assoc(query_data, "null", 4, jinx_zend_null()) ||
        !jinx_zend_array_add_index(query_data, 0, jinx_zend_string_value(query_ceo)) ||
        !jinx_zend_array_add_assoc(query_user, "name", 4, jinx_zend_string_value(query_bob)) ||
        !jinx_zend_array_add_assoc(query_user, "age", 3, jinx_zend_long(47)) ||
        !jinx_zend_array_add_assoc(query_data, "user", 4, jinx_zend_array_value(query_user))) {
        return fail("http_build_query source");
    }
    jinx_zend_string_release(query_bar);
    jinx_zend_string_release(query_php);
    jinx_zend_string_release(query_ceo);
    jinx_zend_string_release(query_bob);
    jinx_zend_array_release(query_user);

    args[0] = jinx_oracle_zend_array_value_borrowed(query_data);
    args[1] = jinx_oracle_string_value("flags_");
    args[2] = jinx_oracle_string_value("&");
    args[3] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("http_build_query", args, 4);
    if (!expect_string(
        result,
        "foo=bar&php=hypertext+processor&flags_0=CEO&user%5Bname%5D=Bob+Smith&user%5Bage%5D=47"
    )) return fail("http_build_query RFC1738");

    args[3] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("http_build_query", args, 4);
    if (!expect_string(
        result,
        "foo=bar&php=hypertext%20processor&flags_0=CEO&user%5Bname%5D=Bob%20Smith&user%5Bage%5D=47"
    )) return fail("http_build_query RFC3986");
    jinx_zend_array_release(query_data);



    JinxZendArray *sort_values = jinx_zend_array_new_packed(4);
    if (sort_values == 0 ||
        !jinx_zend_array_append(sort_values, jinx_zend_long(3)) ||
        !jinx_zend_array_append(sort_values, jinx_zend_long(1)) ||
        !jinx_zend_array_append(sort_values, jinx_zend_long(2))) {
        return fail("sort source");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(sort_values);
    result = jinx_call_builtin_through_oracle("sort", args, 1);
    if (!expect_bool(result, 1) ||
        !expect_long_index(sort_values, 0, 1) ||
        !expect_long_index(sort_values, 1, 2) ||
        !expect_long_index(sort_values, 2, 3)) {
        return fail("sort reindex");
    }

    JinxZendArray *rsort_values = jinx_zend_array_new_packed(4);
    JinxZendString *s10 = jinx_zend_string_new("10", 2);
    JinxZendString *s2 = jinx_zend_string_new("2", 1);
    JinxZendString *s1 = jinx_zend_string_new("1", 1);
    if (rsort_values == 0 || s10 == 0 || s2 == 0 || s1 == 0 ||
        !jinx_zend_array_append(rsort_values, jinx_zend_string_value(s10)) ||
        !jinx_zend_array_append(rsort_values, jinx_zend_string_value(s2)) ||
        !jinx_zend_array_append(rsort_values, jinx_zend_string_value(s1))) {
        return fail("rsort source");
    }
    jinx_zend_string_release(s10);
    jinx_zend_string_release(s2);
    jinx_zend_string_release(s1);
    args[0] = jinx_oracle_zend_array_value_borrowed(rsort_values);
    args[1] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("rsort", args, 2);
    if (!expect_bool(result, 1) ||
        !expect_string_index(rsort_values, 0, "10") ||
        !expect_string_index(rsort_values, 1, "2") ||
        !expect_string_index(rsort_values, 2, "1")) {
        return fail("rsort numeric");
    }

    JinxZendArray *asort_values = jinx_zend_array_new_packed(4);
    if (asort_values == 0 ||
        !jinx_zend_array_add_assoc(asort_values, "b", 1, jinx_zend_long(2)) ||
        !jinx_zend_array_add_assoc(asort_values, "a", 1, jinx_zend_long(1)) ||
        !jinx_zend_array_add_assoc(asort_values, "c", 1, jinx_zend_long(1))) {
        return fail("asort source");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(asort_values);
    result = jinx_call_builtin_through_oracle("asort", args, 1);
    if (!expect_bool(result, 1) ||
        jinx_zend_array_live_iter_at(asort_values, 0)->key == 0 ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(asort_values, 0)->key, "a", 1) ||
        jinx_zend_array_live_iter_at(asort_values, 1)->key == 0 ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(asort_values, 1)->key, "c", 1) ||
        jinx_zend_array_live_iter_at(asort_values, 2)->key == 0 ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(asort_values, 2)->key, "b", 1)) {
        return fail("asort stable preserve keys");
    }

    JinxZendArray *arsort_values = jinx_zend_array_new_packed(4);
    if (arsort_values == 0 ||
        !jinx_zend_array_add_assoc(arsort_values, "a", 1, jinx_zend_long(1)) ||
        !jinx_zend_array_add_assoc(arsort_values, "b", 1, jinx_zend_long(3)) ||
        !jinx_zend_array_add_assoc(arsort_values, "c", 1, jinx_zend_long(2))) {
        return fail("arsort source");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(arsort_values);
    result = jinx_call_builtin_through_oracle("arsort", args, 1);
    if (!expect_bool(result, 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(arsort_values, 0)->key, "b", 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(arsort_values, 1)->key, "c", 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(arsort_values, 2)->key, "a", 1)) {
        return fail("arsort preserve keys");
    }

    JinxZendArray *ksort_values = jinx_zend_array_new_packed(4);
    if (ksort_values == 0 ||
        !jinx_zend_array_add_assoc(ksort_values, "b", 1, jinx_zend_long(2)) ||
        !jinx_zend_array_add_assoc(ksort_values, "a", 1, jinx_zend_long(1)) ||
        !jinx_zend_array_add_assoc(ksort_values, "c", 1, jinx_zend_long(3))) {
        return fail("ksort source");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(ksort_values);
    result = jinx_call_builtin_through_oracle("ksort", args, 1);
    if (!expect_bool(result, 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(ksort_values, 0)->key, "a", 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(ksort_values, 1)->key, "b", 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(ksort_values, 2)->key, "c", 1)) {
        return fail("ksort keys");
    }
    result = jinx_call_builtin_through_oracle("krsort", args, 1);
    if (!expect_bool(result, 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(ksort_values, 0)->key, "c", 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(ksort_values, 1)->key, "b", 1) ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(ksort_values, 2)->key, "a", 1)) {
        return fail("krsort keys");
    }

    JinxZendArray *natural_values = jinx_zend_array_new_packed(4);
    JinxZendString *img12 = jinx_zend_string_new("img12.png", 9);
    JinxZendString *img10 = jinx_zend_string_new("img10.png", 9);
    JinxZendString *img2 = jinx_zend_string_new("img2.png", 8);
    JinxZendString *img1 = jinx_zend_string_new("img1.png", 8);
    if (natural_values == 0 || img12 == 0 || img10 == 0 || img2 == 0 || img1 == 0 ||
        !jinx_zend_array_append(natural_values, jinx_zend_string_value(img12)) ||
        !jinx_zend_array_append(natural_values, jinx_zend_string_value(img10)) ||
        !jinx_zend_array_append(natural_values, jinx_zend_string_value(img2)) ||
        !jinx_zend_array_append(natural_values, jinx_zend_string_value(img1))) {
        return fail("natsort source");
    }
    jinx_zend_string_release(img12);
    jinx_zend_string_release(img10);
    jinx_zend_string_release(img2);
    jinx_zend_string_release(img1);
    args[0] = jinx_oracle_zend_array_value_borrowed(natural_values);
    result = jinx_call_builtin_through_oracle("natsort", args, 1);
    if (!expect_bool(result, 1) ||
        jinx_zend_array_live_iter_at(natural_values, 0)->h != 3u ||
        jinx_zend_array_live_iter_at(natural_values, 1)->h != 2u ||
        jinx_zend_array_live_iter_at(natural_values, 2)->h != 1u ||
        jinx_zend_array_live_iter_at(natural_values, 3)->h != 0u ||
        !expect_string_index(natural_values, 3, "img1.png") ||
        !expect_string_index(natural_values, 2, "img2.png") ||
        !expect_string_index(natural_values, 1, "img10.png") ||
        !expect_string_index(natural_values, 0, "img12.png")) {
        return fail("natsort preserve keys/order");
    }

    JinxZendArray *natcase_values = jinx_zend_array_new_packed(3);
    JinxZendString *nc12 = jinx_zend_string_new("IMG12", 5);
    JinxZendString *nc2 = jinx_zend_string_new("img2", 4);
    JinxZendString *nc1 = jinx_zend_string_new("Img1", 4);
    if (natcase_values == 0 || nc12 == 0 || nc2 == 0 || nc1 == 0 ||
        !jinx_zend_array_append(natcase_values, jinx_zend_string_value(nc12)) ||
        !jinx_zend_array_append(natcase_values, jinx_zend_string_value(nc2)) ||
        !jinx_zend_array_append(natcase_values, jinx_zend_string_value(nc1))) {
        return fail("natcasesort source");
    }
    jinx_zend_string_release(nc12);
    jinx_zend_string_release(nc2);
    jinx_zend_string_release(nc1);
    args[0] = jinx_oracle_zend_array_value_borrowed(natcase_values);
    result = jinx_call_builtin_through_oracle("natcasesort", args, 1);
    if (!expect_bool(result, 1) ||
        jinx_zend_array_live_iter_at(natcase_values, 0)->h != 2u ||
        jinx_zend_array_live_iter_at(natcase_values, 1)->h != 1u ||
        jinx_zend_array_live_iter_at(natcase_values, 2)->h != 0u ||
        !expect_string_index(natcase_values, 2, "Img1") ||
        !expect_string_index(natcase_values, 1, "img2") ||
        !expect_string_index(natcase_values, 0, "IMG12")) {
        return fail("natcasesort preserve keys/order");
    }

    JinxZendArray *multi_primary = jinx_zend_array_new_packed(4);
    JinxZendArray *multi_secondary = jinx_zend_array_new_packed(4);
    JinxZendString *ma = jinx_zend_string_new("a", 1);
    JinxZendString *mb = jinx_zend_string_new("b", 1);
    JinxZendString *mc = jinx_zend_string_new("c", 1);
    JinxZendString *md = jinx_zend_string_new("d", 1);
    if (multi_primary == 0 || multi_secondary == 0 || ma == 0 || mb == 0 || mc == 0 || md == 0 ||
        !jinx_zend_array_append(multi_primary, jinx_zend_long(10)) ||
        !jinx_zend_array_append(multi_primary, jinx_zend_long(10)) ||
        !jinx_zend_array_append(multi_primary, jinx_zend_long(20)) ||
        !jinx_zend_array_append(multi_primary, jinx_zend_long(20)) ||
        !jinx_zend_array_append(multi_secondary, jinx_zend_string_value(ma)) ||
        !jinx_zend_array_append(multi_secondary, jinx_zend_string_value(mb)) ||
        !jinx_zend_array_append(multi_secondary, jinx_zend_string_value(mc)) ||
        !jinx_zend_array_append(multi_secondary, jinx_zend_string_value(md))) {
        return fail("array_multisort source");
    }
    jinx_zend_string_release(ma);
    jinx_zend_string_release(mb);
    jinx_zend_string_release(mc);
    jinx_zend_string_release(md);

    args[0] = jinx_oracle_zend_array_value_borrowed(multi_primary);
    args[1] = jinx_oracle_int_value(4);
    args[2] = jinx_oracle_zend_array_value_borrowed(multi_secondary);
    args[3] = jinx_oracle_int_value(3);
    args[4] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("array_multisort", args, 5);
    if (!expect_bool(result, 1) ||
        !expect_long_index(multi_primary, 0, 10) ||
        !expect_long_index(multi_primary, 1, 10) ||
        !expect_long_index(multi_primary, 2, 20) ||
        !expect_long_index(multi_primary, 3, 20) ||
        !expect_string_index(multi_secondary, 0, "b") ||
        !expect_string_index(multi_secondary, 1, "a") ||
        !expect_string_index(multi_secondary, 2, "d") ||
        !expect_string_index(multi_secondary, 3, "c")) {
        return fail("array_multisort tie-break");
    }

    JinxZendArray *multi_key_primary = jinx_zend_array_new_packed(2);
    JinxZendArray *multi_key_payload = jinx_zend_array_new_packed(2);
    JinxZendString *mx = jinx_zend_string_new("X", 1);
    JinxZendString *my = jinx_zend_string_new("Y", 1);
    if (multi_key_primary == 0 || multi_key_payload == 0 || mx == 0 || my == 0 ||
        !jinx_zend_array_append(multi_key_primary, jinx_zend_long(2)) ||
        !jinx_zend_array_append(multi_key_primary, jinx_zend_long(1)) ||
        !jinx_zend_array_add_assoc(multi_key_payload, "x", 1, jinx_zend_string_value(mx)) ||
        !jinx_zend_array_add_index(multi_key_payload, 7, jinx_zend_string_value(my))) {
        return fail("array_multisort key source");
    }
    jinx_zend_string_release(mx);
    jinx_zend_string_release(my);

    args[0] = jinx_oracle_zend_array_value_borrowed(multi_key_primary);
    args[1] = jinx_oracle_zend_array_value_borrowed(multi_key_payload);
    result = jinx_call_builtin_through_oracle("array_multisort", args, 2);
    if (!expect_bool(result, 1) ||
        !expect_long_index(multi_key_primary, 0, 1) ||
        !expect_long_index(multi_key_primary, 1, 2) ||
        jinx_zend_array_live_iter_at(multi_key_payload, 0)->key != 0 ||
        jinx_zend_array_live_iter_at(multi_key_payload, 0)->h != 0u ||
        !expect_string_index(multi_key_payload, 0, "Y") ||
        jinx_zend_array_live_iter_at(multi_key_payload, 1)->key == 0 ||
        !jinx_zend_string_equals_bytes(jinx_zend_array_live_iter_at(multi_key_payload, 1)->key, "x", 1)) {
        return fail("array_multisort key preservation");
    }
    JinxZendValue *multi_x = jinx_zend_array_find(multi_key_payload, "x", 1);
    if (multi_x == 0 || multi_x->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(multi_x->value.str, "X", 1)) {
        return fail("array_multisort string key value");
    }

    JinxZendArray *map_source = jinx_zend_array_new_packed(3);
    JinxZendString *map_a = jinx_zend_string_new("a", 1);
    JinxZendString *map_four = jinx_zend_string_new("four", 4);
    JinxZendString *map_xx = jinx_zend_string_new("xx", 2);
    if (map_source == 0 || map_a == 0 || map_four == 0 || map_xx == 0 ||
        !jinx_zend_array_add_assoc(map_source, "short", 5, jinx_zend_string_value(map_a)) ||
        !jinx_zend_array_add_index(map_source, 5, jinx_zend_string_value(map_four)) ||
        !jinx_zend_array_add_assoc(map_source, "x", 1, jinx_zend_string_value(map_xx))) {
        return fail("array_map named source");
    }
    jinx_zend_string_release(map_a);
    jinx_zend_string_release(map_four);
    jinx_zend_string_release(map_xx);

    args[0] = jinx_oracle_string_value("strlen");
    args[1] = jinx_oracle_zend_array_value_borrowed(map_source);
    result = jinx_call_builtin_through_oracle("array_map", args, 2);
    if (!expect_array_count(result, 3)) return fail("array_map named count");
    JinxZendArray *mapped = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *mapped_short = jinx_zend_array_find(mapped, "short", 5);
    JinxZendValue *mapped_x = jinx_zend_array_find(mapped, "x", 1);
    if (mapped_short == 0 || mapped_short->type != JINX_ZEND_LONG || mapped_short->value.lval != 1 ||
        !expect_long_index(mapped, 5, 4) ||
        mapped_x == 0 || mapped_x->type != JINX_ZEND_LONG || mapped_x->value.lval != 2) {
        return fail("array_map named preserve keys");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *map_left = jinx_zend_array_new_packed(3);
    JinxZendArray *map_right = jinx_zend_array_new_packed(3);
    if (map_left == 0 || map_right == 0 ||
        !jinx_zend_array_append(map_left, jinx_zend_long(1)) ||
        !jinx_zend_array_append(map_left, jinx_zend_long(5)) ||
        !jinx_zend_array_append(map_left, jinx_zend_long(3)) ||
        !jinx_zend_array_append(map_right, jinx_zend_long(4)) ||
        !jinx_zend_array_append(map_right, jinx_zend_long(2)) ||
        !jinx_zend_array_append(map_right, jinx_zend_long(9))) {
        return fail("array_map multi source");
    }

    args[0] = jinx_oracle_string_value("max");
    args[1] = jinx_oracle_zend_array_value_borrowed(map_left);
    args[2] = jinx_oracle_zend_array_value_borrowed(map_right);
    result = jinx_call_builtin_through_oracle("array_map", args, 3);
    if (!expect_array_count(result, 3)) return fail("array_map multi count");
    JinxZendArray *mapped_multi = jinx_oracle_zend_array_ptr(result);
    if (!expect_long_index(mapped_multi, 0, 4) ||
        !expect_long_index(mapped_multi, 1, 5) ||
        !expect_long_index(mapped_multi, 2, 9)) {
        return fail("array_map multi values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zero_value();
    result = jinx_call_builtin_through_oracle("array_map", args, 3);
    if (!expect_array_count(result, 3)) return fail("array_map null callback count");
    JinxZendArray *mapped_rows = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *row0 = jinx_zend_array_index(mapped_rows, 0);
    JinxZendValue *row1 = jinx_zend_array_index(mapped_rows, 1);
    JinxZendValue *row2 = jinx_zend_array_index(mapped_rows, 2);
    if (row0 == 0 || row0->type != JINX_ZEND_ARRAY ||
        row1 == 0 || row1->type != JINX_ZEND_ARRAY ||
        row2 == 0 || row2->type != JINX_ZEND_ARRAY ||
        !expect_long_index(row0->value.array, 0, 1) ||
        !expect_long_index(row0->value.array, 1, 4) ||
        !expect_long_index(row1->value.array, 0, 5) ||
        !expect_long_index(row1->value.array, 1, 2) ||
        !expect_long_index(row2->value.array, 0, 3) ||
        !expect_long_index(row2->value.array, 1, 9)) {
        return fail("array_map null callback rows");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zend_array_value_borrowed(map_left);
    args[1] = jinx_oracle_string_value("max");
    args[2] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("array_reduce", args, 3);
    if (!expect_int(result, 5)) return fail("array_reduce named max");

    JinxZendArray *filter_named_source = jinx_zend_array_new_packed(3);
    JinxZendString *filter_one = jinx_zend_string_new("1", 1);
    JinxZendString *filter_bad = jinx_zend_string_new("x", 1);
    JinxZendString *filter_two = jinx_zend_string_new("2", 1);
    if (filter_named_source == 0 || filter_one == 0 || filter_bad == 0 || filter_two == 0 ||
        !jinx_zend_array_add_assoc(filter_named_source, "a", 1, jinx_zend_string_value(filter_one)) ||
        !jinx_zend_array_add_assoc(filter_named_source, "b", 1, jinx_zend_string_value(filter_bad)) ||
        !jinx_zend_array_add_assoc(filter_named_source, "c", 1, jinx_zend_string_value(filter_two))) {
        return fail("array_filter named source");
    }
    jinx_zend_string_release(filter_one);
    jinx_zend_string_release(filter_bad);
    jinx_zend_string_release(filter_two);

    args[0] = jinx_oracle_zend_array_value_borrowed(filter_named_source);
    args[1] = jinx_oracle_string_value("is_numeric");
    args[2] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("array_filter", args, 3);
    if (!expect_array_count(result, 2)) return fail("array_filter named count");
    JinxZendArray *filtered_named = jinx_oracle_zend_array_ptr(result);
    if (jinx_zend_array_find(filtered_named, "a", 1) == 0 ||
        jinx_zend_array_find(filtered_named, "b", 1) != 0 ||
        jinx_zend_array_find(filtered_named, "c", 1) == 0) {
        return fail("array_filter named values");
    }
    jinx_oracle_zend_array_value_release(result);

    JinxZendArray *predicate_source = jinx_zend_array_new_packed(3);
    JinxZendString *apple = jinx_zend_string_new("apple", 5);
    JinxZendString *banana = jinx_zend_string_new("banana", 6);
    JinxZendString *carrot = jinx_zend_string_new("carrot", 6);
    if (predicate_source == 0 || apple == 0 || banana == 0 || carrot == 0 ||
        !jinx_zend_array_add_assoc(predicate_source, "a", 1, jinx_zend_string_value(apple)) ||
        !jinx_zend_array_add_assoc(predicate_source, "b", 1, jinx_zend_string_value(banana)) ||
        !jinx_zend_array_add_assoc(predicate_source, "x", 1, jinx_zend_string_value(carrot))) {
        return fail("array predicate source");
    }
    jinx_zend_string_release(apple);
    jinx_zend_string_release(banana);
    jinx_zend_string_release(carrot);

    args[0] = jinx_oracle_zend_array_value_borrowed(predicate_source);
    args[1] = jinx_oracle_string_value("str_starts_with");
    args[2] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("array_filter", args, 3);
    if (!expect_array_count(result, 2)) return fail("array_filter both count");
    JinxZendArray *filtered_both = jinx_oracle_zend_array_ptr(result);
    if (jinx_zend_array_find(filtered_both, "a", 1) == 0 ||
        jinx_zend_array_find(filtered_both, "b", 1) == 0 ||
        jinx_zend_array_find(filtered_both, "x", 1) != 0) {
        return fail("array_filter both values");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zend_array_value_borrowed(predicate_source);
    args[1] = jinx_oracle_string_value("str_starts_with");
    result = jinx_call_builtin_through_oracle("array_find", args, 2);
    if (!expect_string(result, "apple")) return fail("array_find named");
    result = jinx_call_builtin_through_oracle("array_find_key", args, 2);
    if (!expect_string(result, "a")) return fail("array_find_key named");
    result = jinx_call_builtin_through_oracle("array_any", args, 2);
    if (!expect_bool(result, 1)) return fail("array_any named");
    result = jinx_call_builtin_through_oracle("array_all", args, 2);
    if (!expect_bool(result, 0)) return fail("array_all named false");

    JinxZendArray *all_source = jinx_zend_array_new_packed(2);
    JinxZendString *all_apple = jinx_zend_string_new("apple", 5);
    JinxZendString *all_banana = jinx_zend_string_new("banana", 6);
    if (all_source == 0 || all_apple == 0 || all_banana == 0 ||
        !jinx_zend_array_add_assoc(all_source, "a", 1, jinx_zend_string_value(all_apple)) ||
        !jinx_zend_array_add_assoc(all_source, "b", 1, jinx_zend_string_value(all_banana))) {
        return fail("array_all true source");
    }
    jinx_zend_string_release(all_apple);
    jinx_zend_string_release(all_banana);
    args[0] = jinx_oracle_zend_array_value_borrowed(all_source);
    args[1] = jinx_oracle_string_value("str_starts_with");
    result = jinx_call_builtin_through_oracle("array_all", args, 2);
    if (!expect_bool(result, 1)) return fail("array_all named true");

    JinxZendArray *empty_predicate = jinx_zend_array_new_packed(1);
    if (empty_predicate == 0) return fail("array_all empty source");
    args[0] = jinx_oracle_zend_array_value_borrowed(empty_predicate);
    result = jinx_call_builtin_through_oracle("array_all", args, 2);
    if (!expect_bool(result, 1)) return fail("array_all empty true");

    args[0] = jinx_oracle_int_value(42);
    args[1] = jinx_oracle_string_value("STRING");
    int settype_ok = 0;
    result = jinx_call_builtin_through_oracle_checked("settype", args, 2, &settype_ok);
    if (!settype_ok || !expect_bool(result, 1) || !expect_string(args[0], "42")) {
        return fail("settype direct by-reference writeback");
    }

    JinxZendArray *walk_source = jinx_zend_array_new_packed(3);
    JinxZendString *walk_seven = jinx_zend_string_new("7", 1);
    if (walk_source == 0 || walk_seven == 0 ||
        !jinx_zend_array_add_assoc(walk_source, "string", 6, jinx_zend_long(42)) ||
        !jinx_zend_array_add_assoc(walk_source, "integer", 7, jinx_zend_string_value(walk_seven)) ||
        !jinx_zend_array_add_assoc(walk_source, "boolean", 7, jinx_zend_long(0))) {
        return fail("array_walk settype source");
    }
    jinx_zend_string_release(walk_seven);

    args[0] = jinx_oracle_zend_array_value_borrowed(walk_source);
    args[1] = jinx_oracle_string_value("settype");
    result = jinx_call_builtin_through_oracle("array_walk", args, 2);
    JinxZendValue *walk_string = jinx_zend_array_find(walk_source, "string", 6);
    JinxZendValue *walk_integer = jinx_zend_array_find(walk_source, "integer", 7);
    JinxZendValue *walk_boolean = jinx_zend_array_find(walk_source, "boolean", 7);
    if (!expect_bool(result, 1) ||
        walk_string == 0 || walk_string->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(walk_string->value.str, "42", 2) ||
        walk_integer == 0 || walk_integer->type != JINX_ZEND_LONG ||
        walk_integer->value.lval != 7 ||
        walk_boolean == 0 || walk_boolean->type != JINX_ZEND_FALSE) {
        return fail("array_walk settype mutation");
    }

    JinxZendArray *walk_nested = jinx_zend_array_new_packed(2);
    JinxZendArray *walk_recursive = jinx_zend_array_new_packed(2);
    JinxZendString *walk_eight = jinx_zend_string_new("8", 1);
    if (walk_nested == 0 || walk_recursive == 0 || walk_eight == 0 ||
        !jinx_zend_array_add_assoc(walk_nested, "string", 6, jinx_zend_long(9)) ||
        !jinx_zend_array_add_assoc(walk_nested, "integer", 7, jinx_zend_string_value(walk_eight)) ||
        !jinx_zend_array_add_assoc(walk_recursive, "nested", 6, jinx_zend_array_value(walk_nested)) ||
        !jinx_zend_array_add_assoc(walk_recursive, "boolean", 7, jinx_zend_long(1))) {
        return fail("array_walk_recursive source");
    }
    jinx_zend_string_release(walk_eight);
    jinx_zend_array_release(walk_nested);

    args[0] = jinx_oracle_zend_array_value_borrowed(walk_recursive);
    args[1] = jinx_oracle_string_value("settype");
    result = jinx_call_builtin_through_oracle("array_walk_recursive", args, 2);
    JinxZendValue *nested_slot = jinx_zend_array_find(walk_recursive, "nested", 6);
    JinxZendValue *recursive_bool = jinx_zend_array_find(walk_recursive, "boolean", 7);
    if (!expect_bool(result, 1) ||
        nested_slot == 0 || nested_slot->type != JINX_ZEND_ARRAY ||
        recursive_bool == 0 || recursive_bool->type != JINX_ZEND_TRUE) {
        return fail("array_walk_recursive structure");
    }
    JinxZendValue *recursive_string = jinx_zend_array_find(nested_slot->value.array, "string", 6);
    JinxZendValue *recursive_integer = jinx_zend_array_find(nested_slot->value.array, "integer", 7);
    if (recursive_string == 0 || recursive_string->type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(recursive_string->value.str, "9", 1) ||
        recursive_integer == 0 || recursive_integer->type != JINX_ZEND_LONG ||
        recursive_integer->value.lval != 8) {
        return fail("array_walk_recursive leaf mutation");
    }

    JinxZendArray *walk_userdata = jinx_zend_array_new_packed(1);
    if (walk_userdata == 0 ||
        !jinx_zend_array_add_index(walk_userdata, 29, jinx_zend_long(2))) {
        return fail("array_walk userdata source");
    }
    args[0] = jinx_oracle_zend_array_value_borrowed(walk_userdata);
    args[1] = jinx_oracle_string_value("checkdate");
    args[2] = jinx_oracle_int_value(2024);
    result = jinx_call_builtin_through_oracle("array_walk", args, 3);
    if (!expect_bool(result, 1) || !expect_long_index(walk_userdata, 29, 2)) {
        return fail("array_walk userdata callback");
    }

    jinx_zend_array_release(walk_source);
    jinx_zend_array_release(walk_recursive);
    jinx_zend_array_release(walk_userdata);

    JinxZendArray *cmp_a = jinx_zend_array_new_packed(3);
    JinxZendArray *cmp_b = jinx_zend_array_new_packed(3);
    JinxZendArray *cmp_c = jinx_zend_array_new_packed(2);
    JinxZendString *cmp_red = jinx_zend_string_new("red", 3);
    JinxZendString *cmp_green = jinx_zend_string_new("green", 5);
    JinxZendString *cmp_blue = jinx_zend_string_new("blue", 4);
    JinxZendString *cmp_green_b = jinx_zend_string_new("green", 5);
    JinxZendString *cmp_GREEN = jinx_zend_string_new("GREEN", 5);
    JinxZendString *cmp_blue_b = jinx_zend_string_new("blue", 4);
    JinxZendString *cmp_green_c = jinx_zend_string_new("green", 5);
    JinxZendString *cmp_other = jinx_zend_string_new("other", 5);

    if (cmp_a == 0 || cmp_b == 0 || cmp_c == 0 ||
        cmp_red == 0 || cmp_green == 0 || cmp_blue == 0 ||
        cmp_green_b == 0 || cmp_GREEN == 0 || cmp_blue_b == 0 ||
        cmp_green_c == 0 || cmp_other == 0 ||
        !jinx_zend_array_add_assoc(cmp_a, "a", 1, jinx_zend_string_value(cmp_red)) ||
        !jinx_zend_array_add_assoc(cmp_a, "b", 1, jinx_zend_string_value(cmp_green)) ||
        !jinx_zend_array_add_assoc(cmp_a, "c", 1, jinx_zend_string_value(cmp_blue)) ||
        !jinx_zend_array_add_assoc(cmp_b, "x", 1, jinx_zend_string_value(cmp_green_b)) ||
        !jinx_zend_array_add_assoc(cmp_b, "b", 1, jinx_zend_string_value(cmp_GREEN)) ||
        !jinx_zend_array_add_assoc(cmp_b, "c", 1, jinx_zend_string_value(cmp_blue_b)) ||
        !jinx_zend_array_add_assoc(cmp_c, "q", 1, jinx_zend_string_value(cmp_green_c)) ||
        !jinx_zend_array_add_assoc(cmp_c, "c", 1, jinx_zend_string_value(cmp_other))) {
        return fail("user comparator source");
    }

    jinx_zend_string_release(cmp_red);
    jinx_zend_string_release(cmp_green);
    jinx_zend_string_release(cmp_blue);
    jinx_zend_string_release(cmp_green_b);
    jinx_zend_string_release(cmp_GREEN);
    jinx_zend_string_release(cmp_blue_b);
    jinx_zend_string_release(cmp_green_c);
    jinx_zend_string_release(cmp_other);

    args[0] = jinx_oracle_zend_array_value_borrowed(cmp_a);
    args[1] = jinx_oracle_zend_array_value_borrowed(cmp_b);
    args[2] = jinx_oracle_string_value("strcmp");

    result = jinx_call_builtin_through_oracle("array_udiff", args, 3);
    if (!expect_array_count(result, 1) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "a", "red")) {
        return fail("array_udiff named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_diff_uassoc", args, 3);
    if (!expect_array_count(result, 2) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "a", "red") ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "b", "green")) {
        return fail("array_diff_uassoc named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_diff_ukey", args, 3);
    if (!expect_array_count(result, 1) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "a", "red")) {
        return fail("array_diff_ukey named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_udiff_assoc", args, 3);
    if (!expect_array_count(result, 2) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "a", "red") ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "b", "green")) {
        return fail("array_udiff_assoc named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    args[2] = jinx_oracle_string_value("strcmp");
    args[3] = jinx_oracle_string_value("strcmp");
    result = jinx_call_builtin_through_oracle("array_udiff_uassoc", args, 4);
    if (!expect_array_count(result, 2) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "a", "red") ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "b", "green")) {
        return fail("array_udiff_uassoc named comparators");
    }
    jinx_oracle_zend_array_value_release(result);

    args[2] = jinx_oracle_string_value("strcmp");
    result = jinx_call_builtin_through_oracle("array_uintersect", args, 3);
    if (!expect_array_count(result, 2) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "b", "green") ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "c", "blue")) {
        return fail("array_uintersect named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_intersect_uassoc", args, 3);
    if (!expect_array_count(result, 1) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "c", "blue")) {
        return fail("array_intersect_uassoc named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_intersect_ukey", args, 3);
    if (!expect_array_count(result, 2) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "b", "green") ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "c", "blue")) {
        return fail("array_intersect_ukey named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("array_uintersect_assoc", args, 3);
    if (!expect_array_count(result, 1) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "c", "blue")) {
        return fail("array_uintersect_assoc named comparator");
    }
    jinx_oracle_zend_array_value_release(result);

    args[2] = jinx_oracle_string_value("strcmp");
    args[3] = jinx_oracle_string_value("strcmp");
    result = jinx_call_builtin_through_oracle("array_uintersect_uassoc", args, 4);
    if (!expect_array_count(result, 1) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "c", "blue")) {
        return fail("array_uintersect_uassoc named comparators");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_zend_array_value_borrowed(cmp_a);
    args[1] = jinx_oracle_zend_array_value_borrowed(cmp_b);
    args[2] = jinx_oracle_zend_array_value_borrowed(cmp_c);
    args[3] = jinx_oracle_string_value("strcmp");
    result = jinx_call_builtin_through_oracle("array_uintersect", args, 4);
    if (!expect_array_count(result, 1) ||
        !expect_string_key(jinx_oracle_zend_array_ptr(result), "b", "green")) {
        return fail("array_uintersect three arrays");
    }
    jinx_oracle_zend_array_value_release(result);

    jinx_zend_array_release(cmp_a);
    jinx_zend_array_release(cmp_b);
    jinx_zend_array_release(cmp_c);

    jinx_zend_array_release(map_source);
    jinx_zend_array_release(map_left);
    jinx_zend_array_release(map_right);
    jinx_zend_array_release(filter_named_source);
    jinx_zend_array_release(predicate_source);
    jinx_zend_array_release(all_source);
    jinx_zend_array_release(empty_predicate);

    jinx_zend_array_release(multi_primary);
    jinx_zend_array_release(multi_secondary);
    jinx_zend_array_release(multi_key_primary);
    jinx_zend_array_release(multi_key_payload);

    jinx_zend_array_release(sort_values);
    jinx_zend_array_release(rsort_values);
    jinx_zend_array_release(asort_values);
    jinx_zend_array_release(arsort_values);
    jinx_zend_array_release(ksort_values);
    jinx_zend_array_release(natural_values);
    jinx_zend_array_release(natcase_values);

    args[0] = jinx_oracle_int_value(0);
    args[1] = jinx_oracle_int_value(1);
    args[2] = jinx_oracle_int_value(1);
    args[3] = jinx_oracle_int_value(2024);
    result = jinx_call_builtin_through_oracle("cal_to_jd", args, 4);
    if (result.type != 1u) return fail("cal_to_jd gregorian");
    long long greg_jd = (long long)result.as.i64;

    args[0] = jinx_oracle_int_value(greg_jd);
    args[1] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("cal_from_jd", args, 2);
    if (!expect_array_count(result, 9)) return fail("cal_from_jd gregorian count");
    JinxZendArray *cal_greg = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_key(cal_greg, "date", "1/1/2024") ||
        !expect_long_key(cal_greg, "month", 1) ||
        !expect_long_key(cal_greg, "day", 1) ||
        !expect_long_key(cal_greg, "year", 2024) ||
        !expect_long_key(cal_greg, "dow", 1) ||
        !expect_string_key(cal_greg, "abbrevdayname", "Mon") ||
        !expect_string_key(cal_greg, "dayname", "Monday") ||
        !expect_string_key(cal_greg, "abbrevmonth", "Jan") ||
        !expect_string_key(cal_greg, "monthname", "January")) {
        return fail("cal_from_jd gregorian fields");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_int_value(1);
    args[1] = jinx_oracle_int_value(1);
    args[2] = jinx_oracle_int_value(1);
    args[3] = jinx_oracle_int_value(2024);
    result = jinx_call_builtin_through_oracle("cal_to_jd", args, 4);
    if (result.type != 1u) return fail("cal_to_jd julian");
    long long julian_jd = (long long)result.as.i64;
    args[0] = jinx_oracle_int_value(julian_jd);
    args[1] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("cal_from_jd", args, 2);
    if (!expect_array_count(result, 9)) return fail("cal_from_jd julian count");
    JinxZendArray *cal_julian = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_key(cal_julian, "date", "1/1/2024") ||
        !expect_string_key(cal_julian, "abbrevmonth", "Jan") ||
        !expect_string_key(cal_julian, "monthname", "January")) {
        return fail("cal_from_jd julian fields");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_int_value(2);
    args[1] = jinx_oracle_int_value(1);
    args[2] = jinx_oracle_int_value(1);
    args[3] = jinx_oracle_int_value(5771);
    result = jinx_call_builtin_through_oracle("cal_to_jd", args, 4);
    if (result.type != 1u) return fail("cal_to_jd jewish");
    long long jewish_jd = (long long)result.as.i64;
    args[0] = jinx_oracle_int_value(jewish_jd);
    args[1] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("cal_from_jd", args, 2);
    if (!expect_array_count(result, 9)) return fail("cal_from_jd jewish count");
    JinxZendArray *cal_jewish = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_key(cal_jewish, "date", "1/1/5771") ||
        !expect_string_key(cal_jewish, "abbrevmonth", "Tishri") ||
        !expect_string_key(cal_jewish, "monthname", "Tishri")) {
        return fail("cal_from_jd jewish fields");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_int_value(3);
    args[1] = jinx_oracle_int_value(1);
    args[2] = jinx_oracle_int_value(1);
    args[3] = jinx_oracle_int_value(1);
    result = jinx_call_builtin_through_oracle("cal_to_jd", args, 4);
    if (result.type != 1u) return fail("cal_to_jd french");
    long long french_jd = (long long)result.as.i64;
    args[0] = jinx_oracle_int_value(french_jd);
    args[1] = jinx_oracle_int_value(3);
    result = jinx_call_builtin_through_oracle("cal_from_jd", args, 2);
    if (!expect_array_count(result, 9)) return fail("cal_from_jd french count");
    JinxZendArray *cal_french = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_key(cal_french, "date", "1/1/1") ||
        !expect_string_key(cal_french, "abbrevmonth", "Vendemiaire") ||
        !expect_string_key(cal_french, "monthname", "Vendemiaire")) {
        return fail("cal_from_jd french fields");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_int_value(0);
    result = jinx_call_builtin_through_oracle("cal_info", args, 1);
    if (!expect_array_count(result, 5)) return fail("cal_info gregorian count");
    JinxZendArray *cal_info_greg = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_key(cal_info_greg, "calname", "Gregorian") ||
        !expect_string_key(cal_info_greg, "calsymbol", "CAL_GREGORIAN") ||
        !expect_long_key(cal_info_greg, "maxdaysinmonth", 31)) {
        return fail("cal_info gregorian scalar fields");
    }
    JinxZendValue *greg_months_value = jinx_zend_array_find(cal_info_greg, "months", 6);
    JinxZendValue *greg_short_value = jinx_zend_array_find(cal_info_greg, "abbrevmonths", 12);
    if (greg_months_value == 0 || greg_months_value->type != JINX_ZEND_ARRAY ||
        greg_short_value == 0 || greg_short_value->type != JINX_ZEND_ARRAY ||
        !expect_string_index(greg_months_value->value.array, 1, "January") ||
        !expect_string_index(greg_short_value->value.array, 1, "Jan")) {
        return fail("cal_info gregorian months");
    }
    jinx_oracle_zend_array_value_release(result);

    args[0] = jinx_oracle_int_value(2);
    result = jinx_call_builtin_through_oracle("cal_info", args, 1);
    if (!expect_array_count(result, 5)) return fail("cal_info jewish count");
    JinxZendArray *cal_info_jewish = jinx_oracle_zend_array_ptr(result);
    if (!expect_string_key(cal_info_jewish, "calname", "Jewish") ||
        !expect_string_key(cal_info_jewish, "calsymbol", "CAL_JEWISH") ||
        !expect_long_key(cal_info_jewish, "maxdaysinmonth", 30)) {
        return fail("cal_info jewish scalar fields");
    }
    JinxZendValue *jewish_months_value = jinx_zend_array_find(cal_info_jewish, "months", 6);
    if (jewish_months_value == 0 || jewish_months_value->type != JINX_ZEND_ARRAY ||
        !expect_string_index(jewish_months_value->value.array, 6, "Adar I") ||
        !expect_string_index(jewish_months_value->value.array, 7, "Adar II")) {
        return fail("cal_info jewish months");
    }
    jinx_oracle_zend_array_value_release(result);

    result = jinx_call_builtin_through_oracle("cal_info", args, 0);
    if (!expect_array_count(result, 4)) return fail("cal_info all calendars");
    jinx_oracle_zend_array_value_release(result);

    printf(
        "CAL_PARITY:greg=1/1/2024|1|Mon|Monday|Jan|January;"
        "jul=1/1/2024|Jan|January;"
        "jew=1/1/5771|Tishri;"
        "french=1/1/1|Vendemiaire;"
        "info0=Gregorian|CAL_GREGORIAN|31|Jan|January;"
        "info2=Jewish|CAL_JEWISH|30|Adar I|Adar II;"
        "all=4\n"
    );

    JinxZendArray *rand_source = jinx_zend_array_new_packed(5);
    if (rand_source == 0 ||
        !jinx_zend_array_add_assoc(rand_source, "a", 1, jinx_zend_long(1)) ||
        !jinx_zend_array_add_assoc(rand_source, "b", 1, jinx_zend_long(2)) ||
        !jinx_zend_array_add_assoc(rand_source, "c", 1, jinx_zend_long(3)) ||
        !jinx_zend_array_add_assoc(rand_source, "d", 1, jinx_zend_long(4)) ||
        !jinx_zend_array_add_assoc(rand_source, "e", 1, jinx_zend_long(5))) {
        return fail("array_rand source");
    }

    int rng_ok = 0;
    args[0] = jinx_oracle_int_value(1234);
    result = jinx_call_builtin_through_oracle_checked("mt_srand", args, 1, &rng_ok);
    if (!rng_ok || result.type != 0u) return fail("mt_srand seeded");

    result = jinx_call_builtin_through_oracle("mt_rand", args, 0);
    if (result.type != 1u) return fail("mt_rand no-arg");
    long long rng_mt = (long long)result.as.i64;

    args[0] = jinx_oracle_int_value(1234);
    result = jinx_call_builtin_through_oracle_checked("mt_srand", args, 1, &rng_ok);
    if (!rng_ok) return fail("mt_srand range seed");
    args[0] = jinx_oracle_int_value(10);
    args[1] = jinx_oracle_int_value(99);
    result = jinx_call_builtin_through_oracle("mt_rand", args, 2);
    if (result.type != 1u) return fail("mt_rand range");
    long long rng_range = (long long)result.as.i64;

    args[0] = jinx_oracle_int_value(1234);
    result = jinx_call_builtin_through_oracle_checked("srand", args, 1, &rng_ok);
    if (!rng_ok) return fail("srand alias seed");
    args[0] = jinx_oracle_int_value(99);
    args[1] = jinx_oracle_int_value(10);
    result = jinx_call_builtin_through_oracle("rand", args, 2);
    if (result.type != 1u) return fail("rand reversed range");
    long long rng_reverse = (long long)result.as.i64;

    result = jinx_call_builtin_through_oracle("mt_getrandmax", args, 0);
    if (!expect_int(result, 2147483647LL)) return fail("mt_getrandmax");
    long long rng_max = (long long)result.as.i64;
    result = jinx_call_builtin_through_oracle("getrandmax", args, 0);
    if (!expect_int(result, 2147483647LL)) return fail("getrandmax alias");

    args[0] = jinx_oracle_int_value(1234);
    result = jinx_call_builtin_through_oracle_checked("mt_srand", args, 1, &rng_ok);
    if (!rng_ok) return fail("array_rand one seed");
    args[0] = jinx_oracle_zend_array_value_borrowed(rand_source);
    result = jinx_call_builtin_through_oracle("array_rand", args, 1);
    if (result.type != 3u) return fail("array_rand one key");
    JinxValue rand_one = result;

    args[0] = jinx_oracle_int_value(1234);
    result = jinx_call_builtin_through_oracle_checked("mt_srand", args, 1, &rng_ok);
    if (!rng_ok) return fail("array_rand many seed");
    args[0] = jinx_oracle_zend_array_value_borrowed(rand_source);
    args[1] = jinx_oracle_int_value(3);
    result = jinx_call_builtin_through_oracle("array_rand", args, 2);
    if (!expect_array_count(result, 3)) return fail("array_rand many count");
    JinxZendArray *rand_many = jinx_oracle_zend_array_ptr(result);
    JinxZendValue *rand_many_0 = jinx_zend_array_index(rand_many, 0);
    JinxZendValue *rand_many_1 = jinx_zend_array_index(rand_many, 1);
    JinxZendValue *rand_many_2 = jinx_zend_array_index(rand_many, 2);
    if (rand_many_0 == 0 || rand_many_0->type != JINX_ZEND_STRING ||
        rand_many_1 == 0 || rand_many_1->type != JINX_ZEND_STRING ||
        rand_many_2 == 0 || rand_many_2->type != JINX_ZEND_STRING) {
        return fail("array_rand many keys");
    }

    printf(
        "RNG_PARITY:mt=%lld;range=%lld;reverse=%lld;max=%lld;one=%.*s;many=%.*s,%.*s,%.*s\n",
        rng_mt,
        rng_range,
        rng_reverse,
        rng_max,
        (int)rand_one.flags,
        (const char *)rand_one.as.ptr,
        (int)rand_many_0->value.str->len,
        rand_many_0->value.str->bytes,
        (int)rand_many_1->value.str->len,
        rand_many_1->value.str->bytes,
        (int)rand_many_2->value.str->len,
        rand_many_2->value.str->bytes
    );

    jinx_oracle_zend_array_value_release(result);
    jinx_zend_array_release(rand_source);

    jinx_zend_array_release(count_source);
    jinx_zend_array_release(value_array);
    jinx_zend_array_release(key_array);
    jinx_zend_array_release(other);
    jinx_zend_array_release(array);

    printf("PARITY:recursive=4;keys_loose=1;keys_strict=;sum=100;product=240000;words=2;words1=Hello,world;words2=0:Hello,7:world;words_digits=1;implode=10,20,30,40;vsprintf=There are 7 million bicycles in Amsterdam.;range=1,2,3,4,5;range_neg=5,3,1;fill=2:9,3:9,4:9;fill_zero=0;combine=2:70,x:80;fill_keys_num=2:9,02:9,+2:9,-2:9;combine_num=2:70,02:80;count_values=2:2,x:3;count_values_num=2:2,02:1;chunk0=10,20;pad=10,20,30,40,0,0;unique=0:4,2:3;diff=0:10,name:30;intersect=1:20,keep:40;intersect3=keep:40;explode=a,b,c;split=ab,cd,ef;column=1:Ada,2:Grace;column_num=1:Ada,02:Grace;in_loose=1;in_strict=0;search_loose=name;search_strict=false;filter=1:1,3:x;push=4;pop=40;shift=10;unshift=5;mutation=0:5,1:6,x:20,2:30,3:50;splice=0:10,keep:20,1:70,2:80,3:50;spliced=0:30,tail:40;merge_rec=red,green,blue,5,10;replace_rec=kumquat,citron,loquat;csv=a|b|c;csvq=a,b|c;csvesc=a\"b|c\\\"d;csvtrail=a|;csvempty=null;csvunterminated=unterminated\\n;strip_array=<p>Test paragraph.</p> <a href=\"#fragment\">Other text</a>;url=http|hostname|9090|username|password|/path|arg=value|anchor;url2=www.example.com|/path|googleguy=googley;url_empty=1|1;url_component=example.com|8080;query1738=user%5Bname%5D=Bob+Smith&user%5Bage%5D=47&flags_0=CEO&flag=0;query3986=user%5Bname%5D=Bob%20Smith&user%5Bage%5D=47&flags_0=CEO&flag=0;parse_str=value|foo bar,baz|Something|yes;pathinfo=/www/htdocs/inc|lib.inc.php|php|lib.inc;path_dot=test|;path_noext=0;path_flags=/www/htdocs/inc|lib.inc.php|php|lib.inc;array_min=2;array_max=3.5;array_tie_type=integer;sort=1,2,3;rsort_num=10,2,1;asort=a:1,c:1,b:2;arsort=b:3,c:2,a:1;ksort=a:1,b:2,c:3;krsort=c:3,b:2,a:1;natsort=3:img1.png,2:img2.png,1:img10.png,0:img12.png;natcase=2:Img1,1:img2,0:IMG12;multisort=10,10,20,20|b,a,d,c;multikeys=0:Y,x:X;map=short:1,5:4,x:2;map2=4,5,9;mapnull=1,4|5,2|3,9;reduce=5;filtercb=a:1,c:2;filterboth=a:apple,b:banana;find=apple|a|1|0|1|1;udiff=a:red;duassoc=a:red,b:green;dukey=a:red;udassoc=a:red,b:green;uduassoc=a:red,b:green;uinter=b:green,c:blue;iuassoc=c:blue;iukey=b:green,c:blue;uiassoc=c:blue;uiuassoc=c:blue;uinter3=b:green;walk=42|7|false;walkrec=9|8|true;walkdata=2\n");
    printf("PASS: Oracle generated dispatch Zend-array native core passed\n");
    return 0;
}
