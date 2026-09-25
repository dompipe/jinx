#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_engine.h"
#include "../runtime/jinx_zend_array_delete.h"

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
        result.integer = (long long)jinx_zend_array_live_count(array);
        return result;
    }

    if (strcmp(name, "array_key_exists") == 0) {
        result.type = "bool";
        if (key != 0) {
            result.integer = jinx_zend_array_live_key_exists_string(array, key, key_len);
        } else {
            result.integer = jinx_zend_array_live_key_exists_index(array, index);
        }
        return result;
    }

    if (strcmp(name, "array_is_list") == 0) {
        result.type = "bool";
        result.integer = jinx_zend_array_live_is_list(array);
        return result;
    }

    if (strcmp(name, "array_values") == 0) {
        result.type = "array";
        result.array = jinx_zend_array_live_values(array);
        result.integer = result.array == 0 ? 0 : (long long)result.array->count;
        return result;
    }

    if (strcmp(name, "array_keys") == 0) {
        result.type = "array";
        result.array = jinx_zend_array_live_keys(array);
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
    ok = ok && jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(44));

    if (!ok) {
        fprintf(stderr, "FAIL: could not seed Zend array\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!expect_int(call_array_builtin("count", array, 0, 0, 0), 4)) {
        fprintf(stderr, "FAIL: count did not return 4 before delete\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!jinx_zend_array_delete_index(array, 1u) || !jinx_zend_array_delete_string(array, "name", 4)) {
        fprintf(stderr, "FAIL: could not create tombstones before bridge checks\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!expect_int(call_array_builtin("count", array, 0, 0, 0), 2)) {
        fprintf(stderr, "FAIL: live count did not ignore tombstones\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!expect_bool(call_array_builtin("array_key_exists", array, 0, 0, 0), 1) ||
        !expect_bool(call_array_builtin("array_key_exists", array, 0, 0, 1), 0) ||
        !expect_bool(call_array_builtin("array_key_exists", array, "name", 4, 0), 0) ||
        !expect_bool(call_array_builtin("array_key_exists", array, "keep", 4, 0), 1) ||
        !expect_bool(call_array_builtin("array_key_exists", array, "missing", 7, 0), 0)) {
        fprintf(stderr, "FAIL: live array_key_exists did not match numeric/string keys after delete\n");
        jinx_zend_array_release(array);
        return 1;
    }

    if (!expect_bool(call_array_builtin("array_is_list", array, 0, 0, 0), 0)) {
        fprintf(stderr, "FAIL: array with deleted gap reported as list\n");
        jinx_zend_array_release(array);
        return 1;
    }

    result = call_array_builtin("array_values", array, 0, 0, 0);
    if (!expect_array_count(result, 2)) {
        fprintf(stderr, "FAIL: live array_values did not return 2 values\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    value = jinx_zend_array_index(result.array, 1);
    if (value == 0 || value->type != JINX_ZEND_LONG || value->value.lval != 44) {
        fprintf(stderr, "FAIL: live array_values did not skip tombstone value\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    if (!expect_bool(call_array_builtin("array_is_list", result.array, 0, 0, 0), 1)) {
        fprintf(stderr, "FAIL: array_values result was not a packed list\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_zend_array_release(result.array);

    result = call_array_builtin("array_keys", array, 0, 0, 0);
    if (!expect_array_count(result, 2)) {
        fprintf(stderr, "FAIL: live array_keys did not return 2 keys\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    value = jinx_zend_array_index(result.array, 1);
    if (value == 0 || value->type != JINX_ZEND_STRING || !jinx_zend_string_equals_bytes(value->value.str, "keep", 4)) {
        fprintf(stderr, "FAIL: live array_keys did not preserve surviving string key\n");
        jinx_zend_array_release(result.array);
        jinx_zend_array_release(array);
        return 1;
    }
    jinx_zend_array_release(result.array);

    jinx_zend_array_release(array);

    printf("PASS: Zend array builtin name bridge smoke passed\n");
    printf("PASS: count, array_key_exists, array_is_list, array_values, array_keys route to live-aware native Zend helpers\n");
    return 0;
}
