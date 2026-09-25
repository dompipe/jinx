#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_object.h"

static JinxZendValue method_get_total(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
) {
    JinxZendValue *base = jinx_zend_object_get_property(object, "base");
    int64_t total = 0;

    (void)executor;

    if (base != 0 && base->type == JINX_ZEND_LONG) {
        total += base->value.lval;
    }
    if (argc > 0u && args != 0 && args[0].type == JINX_ZEND_LONG) {
        total += args[0].value.lval;
    }

    return jinx_zend_long(total);
}

static JinxZendValue method_class_name(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
) {
    (void)executor;
    (void)args;
    (void)argc;
    return jinx_zend_string_value(jinx_zend_string_new(object->class_name, strlen(object->class_name)));
}

int main(void) {
    static const JinxZendMethodEntry methods[] = {
        { "getTotal", method_get_total },
        { "className", method_class_name }
    };
    static const JinxZendClassEntry class_entry = {
        "JinxCounter",
        methods,
        sizeof(methods) / sizeof(methods[0])
    };

    JinxZendClassTable table;
    JinxZendExecutor executor;
    JinxZendObject *object;
    JinxZendValue args[1];
    JinxZendValue result;
    JinxZendValue *base;
    int ok = 1;

    jinx_zend_class_table_init(&table);
    jinx_zend_executor_init(&executor);

    if (!jinx_zend_class_table_register(&table, &class_entry) ||
        jinx_zend_class_table_find(&table, "JinxCounter") != &class_entry ||
        jinx_zend_class_table_find(&table, "Missing") != 0) {
        fprintf(stderr, "FAIL: class table registration/lookup failed\n");
        jinx_zend_class_table_release(&table);
        return 1;
    }

    object = jinx_zend_object_new(&class_entry);
    if (object == 0 || strcmp(object->class_name, "JinxCounter") != 0 || object->refcount != 1u) {
        fprintf(stderr, "FAIL: object allocation failed\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    if (!jinx_zend_object_set_property(object, "base", jinx_zend_long(40))) {
        fprintf(stderr, "FAIL: property set failed\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    base = jinx_zend_object_get_property(object, "base");
    if (base == 0 || base->type != JINX_ZEND_LONG || base->value.lval != 40) {
        fprintf(stderr, "FAIL: property get failed\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    args[0] = jinx_zend_long(2);
    result = jinx_zend_call_method(&executor, &table, object, "getTotal", args, 1u);
    if (result.type != JINX_ZEND_LONG || result.value.lval != 42 || executor.executed_ops != 1u) {
        fprintf(stderr, "FAIL: getTotal method dispatch failed\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    result = jinx_zend_call_method(&executor, &table, object, "className", 0, 0u);
    if (result.type != JINX_ZEND_STRING || result.value.str == 0 ||
        !jinx_zend_string_equals_bytes(result.value.str, "JinxCounter", 11) || executor.executed_ops != 2u) {
        fprintf(stderr, "FAIL: className method dispatch failed\n");
        jinx_zend_value_release(result);
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }
    jinx_zend_value_release(result);

    result = jinx_zend_call_method(&executor, &table, object, "missing", 0, 0u);
    ok = result.type == JINX_ZEND_NULL && executor.last_error != 0 &&
        strcmp(executor.last_error, "method not found") == 0 && executor.error_level == 1u && executor.executed_ops == 2u;

    jinx_zend_object_release(object);
    jinx_zend_class_table_release(&table);

    if (!ok) {
        fprintf(stderr, "FAIL: missing method error state failed\n");
        return 1;
    }

    printf("PASS: Zend object class table smoke passed\n");
    printf("PASS: class registration, object allocation, property access, and method dispatch work\n");
    return 0;
}
