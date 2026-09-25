#ifndef JINX_ZEND_OBJECT_H
#define JINX_ZEND_OBJECT_H

#include "jinx_zend_engine.h"

#include <stdlib.h>
#include <string.h>

/*
 * Native class/object dispatch layer for the JINX-owned Zend-shaped runtime.
 *
 * This is deliberately additive to the existing JinxZendObject shell. Objects
 * still carry class_name + properties, while class metadata and method tables
 * are supplied by JinxZendClassEntry records. Later opcodes can bind object
 * values to these entries when lowering method calls.
 */

typedef JinxZendValue (*JinxZendMethodHandler)(
    JinxZendExecutor *executor,
    JinxZendObject *object,
    JinxZendValue *args,
    size_t argc
);

typedef struct JinxZendMethodEntry {
    const char *name;
    JinxZendMethodHandler handler;
} JinxZendMethodEntry;

typedef struct JinxZendClassEntry {
    const char *name;
    const JinxZendMethodEntry *methods;
    size_t method_count;
} JinxZendClassEntry;

typedef struct JinxZendClassTable {
    const JinxZendClassEntry **classes;
    size_t count;
    size_t capacity;
} JinxZendClassTable;

static inline JinxZendValue jinx_zend_object_value(JinxZendObject *object) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_OBJECT;
    value.value.object = object;
    return value;
}

static inline JinxZendObject *jinx_zend_object_new(const JinxZendClassEntry *ce) {
    JinxZendObject *object;

    if (ce == 0 || ce->name == 0) {
        return 0;
    }

    object = (JinxZendObject *)calloc(1, sizeof(JinxZendObject));
    if (object == 0) {
        return 0;
    }

    object->refcount = 1u;
    object->flags = 0u;
    object->class_name = ce->name;
    object->properties = jinx_zend_array_new_packed(4u);
    if (object->properties == 0) {
        free(object);
        return 0;
    }

    return object;
}

static inline JinxZendObject *jinx_zend_object_retain(JinxZendObject *object) {
    if (object != 0) {
        object->refcount++;
    }
    return object;
}

static inline void jinx_zend_object_release(JinxZendObject *object) {
    if (object == 0) {
        return;
    }
    if (object->refcount > 1u) {
        object->refcount--;
        return;
    }
    jinx_zend_array_release(object->properties);
    free(object);
}

static inline void jinx_zend_class_table_init(JinxZendClassTable *table) {
    if (table == 0) {
        return;
    }
    table->classes = 0;
    table->count = 0u;
    table->capacity = 0u;
}

static inline void jinx_zend_class_table_release(JinxZendClassTable *table) {
    if (table == 0) {
        return;
    }
    free(table->classes);
    table->classes = 0;
    table->count = 0u;
    table->capacity = 0u;
}

static inline int jinx_zend_class_table_register(JinxZendClassTable *table, const JinxZendClassEntry *ce) {
    const JinxZendClassEntry **classes;
    size_t new_capacity;

    if (table == 0 || ce == 0 || ce->name == 0) {
        return 0;
    }

    for (size_t i = 0; i < table->count; i++) {
        if (strcmp(table->classes[i]->name, ce->name) == 0) {
            table->classes[i] = ce;
            return 1;
        }
    }

    if (table->count == table->capacity) {
        new_capacity = table->capacity == 0u ? 4u : table->capacity * 2u;
        classes = (const JinxZendClassEntry **)realloc(table->classes, new_capacity * sizeof(const JinxZendClassEntry *));
        if (classes == 0) {
            return 0;
        }
        table->classes = classes;
        table->capacity = new_capacity;
    }

    table->classes[table->count++] = ce;
    return 1;
}

static inline const JinxZendClassEntry *jinx_zend_class_table_find(
    const JinxZendClassTable *table,
    const char *class_name
) {
    if (table == 0 || class_name == 0) {
        return 0;
    }

    for (size_t i = 0; i < table->count; i++) {
        if (table->classes[i] != 0 && strcmp(table->classes[i]->name, class_name) == 0) {
            return table->classes[i];
        }
    }

    return 0;
}

static inline const JinxZendMethodEntry *jinx_zend_class_find_method(
    const JinxZendClassEntry *ce,
    const char *method_name
) {
    if (ce == 0 || method_name == 0) {
        return 0;
    }

    for (size_t i = 0; i < ce->method_count; i++) {
        if (ce->methods[i].name != 0 && strcmp(ce->methods[i].name, method_name) == 0) {
            return &ce->methods[i];
        }
    }

    return 0;
}

static inline int jinx_zend_object_set_property(
    JinxZendObject *object,
    const char *name,
    JinxZendValue value
) {
    if (object == 0 || object->properties == 0 || name == 0) {
        return 0;
    }
    return jinx_zend_array_add_assoc(object->properties, name, strlen(name), value);
}

static inline JinxZendValue *jinx_zend_object_get_property(JinxZendObject *object, const char *name) {
    if (object == 0 || object->properties == 0 || name == 0) {
        return 0;
    }
    return jinx_zend_array_find(object->properties, name, strlen(name));
}

static inline JinxZendValue jinx_zend_call_method(
    JinxZendExecutor *executor,
    const JinxZendClassTable *table,
    JinxZendObject *object,
    const char *method_name,
    JinxZendValue *args,
    size_t argc
) {
    const JinxZendClassEntry *ce;
    const JinxZendMethodEntry *method;

    if (executor == 0 || table == 0 || object == 0 || method_name == 0 || object->class_name == 0) {
        if (executor != 0) {
            executor->last_error = "invalid method call";
            executor->error_level = 1u;
        }
        return jinx_zend_null();
    }

    ce = jinx_zend_class_table_find(table, object->class_name);
    method = jinx_zend_class_find_method(ce, method_name);
    if (method == 0 || method->handler == 0) {
        executor->last_error = "method not found";
        executor->error_level = 1u;
        return jinx_zend_null();
    }

    executor->executed_ops++;
    return method->handler(executor, object, args, argc);
}

#endif /* JINX_ZEND_OBJECT_H */
