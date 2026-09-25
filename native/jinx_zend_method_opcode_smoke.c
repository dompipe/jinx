#include <stdio.h>
#include <string.h>

#include "../runtime/jinx_zend_method_opcode.h"

static JinxZendValue add_handler(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
) {
    JinxZendValue *base;
    long long total = 0;

    (void)executor;

    base = jinx_zend_object_get_property(object, "base");
    if (base != 0 && base->type == JINX_ZEND_LONG) {
        total += (long long)base->value.lval;
    }

    for (size_t i = 0; i < argc; i++) {
        if (args[i].type == JINX_ZEND_LONG) {
            total += (long long)args[i].value.lval;
        }
    }

    return jinx_zend_long(total);
}

static JinxZendValue class_name_handler(
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
        { "add", add_handler },
        { "className", class_name_handler }
    };
    static const JinxZendClassEntry ce = {
        "Accumulator",
        methods,
        sizeof(methods) / sizeof(methods[0])
    };

    JinxZendClassTable table;
    JinxZendExecutor executor;
    JinxZendObject *object;
    JinxZendValue object_value;
    JinxZendValue args[2];
    JinxZendMethodCallFrame frame;
    JinxZendValue result;

    jinx_zend_class_table_init(&table);
    jinx_zend_executor_init(&executor);

    if (!jinx_zend_class_table_register(&table, &ce)) {
        fprintf(stderr, "FAIL: could not register class\n");
        return 1;
    }

    object = jinx_zend_object_new(&ce);
    if (object == 0) {
        fprintf(stderr, "FAIL: could not allocate object\n");
        jinx_zend_class_table_release(&table);
        return 1;
    }

    if (!jinx_zend_object_set_property(object, "base", jinx_zend_long(10))) {
        fprintf(stderr, "FAIL: could not set base property\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    object_value = jinx_zend_object_value(object);
    args[0] = jinx_zend_long(7);
    args[1] = jinx_zend_long(5);

    if (jinx_zend_init_method_call(&frame, object_value, "add") != JINX_ZEND_METHOD_OK) {
        fprintf(stderr, "FAIL: INIT_METHOD_CALL rejected object\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    if (jinx_zend_send_method_args(&frame, args, 2u) != JINX_ZEND_METHOD_OK) {
        fprintf(stderr, "FAIL: SEND_METHOD_ARGS failed\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    if (jinx_zend_do_method_call(&executor, &table, &frame, &result) != JINX_ZEND_METHOD_OK ||
        result.type != JINX_ZEND_LONG || result.value.lval != 22) {
        fprintf(stderr, "FAIL: DO_METHOD_CALL did not return accumulated value\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    if (executor.executed_ops != 1u) {
        fprintf(stderr, "FAIL: method dispatch did not advance executor ops once\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    if (jinx_zend_init_method_call(&frame, object_value, "className") != JINX_ZEND_METHOD_OK ||
        jinx_zend_send_method_args(&frame, 0, 0u) != JINX_ZEND_METHOD_OK ||
        jinx_zend_do_method_call(&executor, &table, &frame, &result) != JINX_ZEND_METHOD_OK ||
        result.type != JINX_ZEND_STRING ||
        !jinx_zend_string_equals_bytes(result.value.str, "Accumulator", 11)) {
        fprintf(stderr, "FAIL: zero-arg method did not return class name\n");
        jinx_zend_value_release(result);
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }
    jinx_zend_value_release(result);

    if (jinx_zend_init_method_call(&frame, jinx_zend_long(123), "add") != JINX_ZEND_METHOD_NOT_OBJECT) {
        fprintf(stderr, "FAIL: INIT_METHOD_CALL accepted non-object source\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    if (jinx_zend_init_method_call(&frame, object_value, "missing") != JINX_ZEND_METHOD_OK ||
        jinx_zend_do_method_call(&executor, &table, &frame, &result) != JINX_ZEND_METHOD_NOT_FOUND ||
        result.type != JINX_ZEND_NULL ||
        executor.last_error == 0 ||
        strcmp(executor.last_error, "method not found") != 0) {
        fprintf(stderr, "FAIL: missing method did not report method-not-found\n");
        jinx_zend_object_release(object);
        jinx_zend_class_table_release(&table);
        return 1;
    }

    jinx_zend_object_release(object);
    jinx_zend_class_table_release(&table);

    printf("PASS: Zend method-call opcode smoke passed\n");
    printf("PASS: INIT_METHOD_CALL/SEND_ARGS/DO_METHOD_CALL dispatch object methods and report errors\n");
    return 0;
}
