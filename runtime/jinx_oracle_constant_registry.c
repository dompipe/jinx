#include "jinx_oracle_constant_registry.h"
#include "jinx_oracle_zend_array_builtins.h"

#include <stdlib.h>
#include <string.h>

typedef struct JinxOracleConstantEntry {
    char *name;
    JinxZendValue value;
    struct JinxOracleConstantEntry *next;
} JinxOracleConstantEntry;

static JinxOracleConstantEntry *jinx_oracle_constant_head = NULL;

static char *constant_strdup(const char *text) {
    size_t len;
    char *copy;
    if (text == NULL) return NULL;
    len = strlen(text);
    copy = (char *)malloc(len + 1u);
    if (copy == NULL) return NULL;
    memcpy(copy, text, len + 1u);
    return copy;
}

static JinxOracleConstantEntry *constant_find(const char *name) {
    if (name == NULL) return NULL;
    for (JinxOracleConstantEntry *it = jinx_oracle_constant_head;
         it != NULL;
         it = it->next) {
        if (strcmp(it->name, name) == 0) return it;
    }
    return NULL;
}

int jinx_oracle_constant_registry_define(
    const char *name,
    JinxValue value
) {
    JinxOracleConstantEntry *entry;
    JinxZendValue stored;

    if (name == NULL || *name == '\0' || constant_find(name) != NULL) {
        return 0;
    }

    /* PHP constants support null/scalars/arrays, not object/resource values. */
    if (value.type == JINX_ORACLE_VALUE_ZEND_OBJECT ||
        value.type == JINX_ORACLE_VALUE_ZEND_RESOURCE) {
        return 0;
    }

    if (!jinx_oracle_zend_owned_from_jinx(value, &stored)) {
        return 0;
    }

    entry = (JinxOracleConstantEntry *)calloc(1u, sizeof(*entry));
    if (entry == NULL) {
        jinx_zend_value_release(stored);
        return 0;
    }

    entry->name = constant_strdup(name);
    if (entry->name == NULL) {
        jinx_zend_value_release(stored);
        free(entry);
        return 0;
    }

    entry->value = stored;
    entry->next = jinx_oracle_constant_head;
    jinx_oracle_constant_head = entry;
    return 1;
}

int jinx_oracle_constant_registry_defined(const char *name) {
    return constant_find(name) != NULL;
}

int jinx_oracle_constant_registry_get(
    const char *name,
    JinxValue *out
) {
    JinxOracleConstantEntry *entry;
    if (out == NULL) return 0;
    entry = constant_find(name);
    if (entry == NULL) return 0;
    return jinx_oracle_zend_to_jinx_borrowed(entry->value, out);
}

size_t jinx_oracle_constant_registry_count(void) {
    size_t count = 0u;
    for (JinxOracleConstantEntry *it = jinx_oracle_constant_head;
         it != NULL;
         it = it->next) {
        count++;
    }
    return count;
}

int jinx_oracle_constant_registry_append_to_array(
    JinxZendArray *array
) {
    if (array == NULL) return 0;

    for (JinxOracleConstantEntry *it = jinx_oracle_constant_head;
         it != NULL;
         it = it->next) {
        if (!jinx_zend_array_add_assoc(
                array,
                it->name,
                strlen(it->name),
                it->value
            )) {
            return 0;
        }
    }
    return 1;
}
