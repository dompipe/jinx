#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_engine.h"

typedef struct JinxZendNamedResult {
    const char *type;
    long long integer;
    JinxZendArray *array;
} JinxZendNamedResult;

static JinxZendNamedResult call_array_builtin(const char *name, JinxZendArray *array, const char *key, size_t key_len, size_t index) {
    JinxZendNamedResult result;
    result.type = "null";
    result.integer = 0;
    result.array = 0;

    if (strcmp(name, "count") == 0) {
        result.type = "int";
        result.integer = (long long)jinx_zend_array_count_builtin(array);
        return result;
    }

    if (strcmp(name, "array_key_exists") == 0) {
        result.type = "bool";
        if (key != 0) {
            result.integer = jinx_zend_array_key_exists_string(array, key, key_len);
        } else {
            result.integer = jinx_zend_array_key_exists_index(array, index);
        }
        return result;
    }

    if (strcmp(name, "array_is_list") == 0) {
        result.type = "bool";
        result.integer = jinx_zend_array_is_list_builtin(array);
        return result;
    }

    if (strcmp(name, "array_values") == 0) {
        result.type = "array";
        result.array = jinx_zend_array_values_builtin(array);
        result.integer = result.array == 0 ? 0 : (long long)result.array->count;
        return result;
    }

    if (strcmp(name, "array_keys") == 0) {
        result.type = "array";
        result.array = jinx_zend_array_keys_builtin(array);
        result.integer = result.array == 0 ? 0 : (long long)result.array->count;
        return result;
    }

    return result;
}

static int expect_int(JinxZendNamedResult result, long long expected) {
    return strcmp(result.type, "int") == 0 && result.integer == expected;
}

static int expect_bool(JinxZendNamedResult result, int expected) {
    return strcmp(result.type, "bool") == 0 && result.integer == (expected ? 1 : 0);
}

static int expect_array_count(JinxZendNamedResult result, size_t expected) {
    return strcmp(result.type, "array") == 0 && result.array != 0 && result.array->count == expected;
}

int main(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxZendNamedResult result;
    JinxZendValue *value;
    int ok = 1;

    if (array == 0) {
        fprintf(stderr, "FAIL: could not allocate Zend array\n");
        return 1;
    }

    ok = ok && jinx_zend_array_append(array, jinx_zend_long(11));
    ok = ok && jinx_zend_array_append(array, jinx_zend_long(22));
    ok = ok && jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(33));

    if (!ok) {
        fprintf(stderr, "FAIL: could not seed Zend array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!expect_int(call_array_builtin("count", array, 0, 0, 0), 3)) {
        fprintf(stderr, "FAIL: count did not return 3\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!expect_bool(call_array_builtin("array_key_exists", array, 0, 0, 1), 1) ||
        !expect_bool(call_array_builtin("array_key_exists", array, 0, 0, 4), 0) ||
        !expect_bool(call_array_builtin("array_key_exists", array, "name", 4, 0), 1) ||
        !expect_bool(call_array_builtin("array_key_exists", array, "missing", 7, 0), 0)) {
        fprintf(stderr, "FAIL: array_key_exists did not match numeric/string keys\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!expect_bool(call_array_builtin("array_is_list", array, 0, 0, 0), 0)) {
        fprintf(stderr, "FAIL: mixed array reported as list\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = call_array_builtin("array_values", array, 0, 0, 0);
    if (!expect_array_count(result, 3)) {
        fprintf(stderr, "FAIL: array_values did not return 3 values\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    value = jinx_zend_array_index(result.array, 2);
    if (value == 0 || value->type != JINX_ZEND_LONG || value->value.lval != 33) {
        fprintf(stderr, "FAIL: array_values lost insertion-order value\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_zend_array_release(result.array);

    result = call_array_builtin("array_keys", array, 0, 0, 0);
    if (!expect_array_count(result, 3)) {
        fprintf(stderr, "FAIL: array_keys did not return 3 keys\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    value = jinx_zend_array_index(result.array, 2);
    if (value == 0 || value->type != JINX_ZEND_STRING || !jinx_zend_string_equals_bytes(value->value.str, "name", 4)) {
        fprintf(stderr, "FAIL: array_keys did not preserve string key\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_zend_array_release(result.array);

    jinx_zend_array_release(array);

    printf("PASS: Zend array builtin name bridge smoke passed\n");
    printf("PASS: count, array_key_exists, array_is_list, array_values, array_keys route to native Zend helpers\n");
    return 0;
}
