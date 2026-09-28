#include "jinx_oracle_resource_registry.h"
#include "jinx_oracle_zend_array_builtins.h"

#include <stdlib.h>
#include <string.h>

typedef struct JinxOracleResourceEntry {
    void *ptr;
    int64_t id;
    char *type_name;
    struct JinxOracleResourceEntry *next;
} JinxOracleResourceEntry;

static JinxOracleResourceEntry *jinx_oracle_resource_head = NULL;
static int64_t jinx_oracle_resource_next_id = 1;

static char *jinx_oracle_resource_strdup(const char *text) {
    size_t len;
    char *copy;
    if (text == NULL) return NULL;
    len = strlen(text);
    copy = (char *)malloc(len + 1u);
    if (copy == NULL) return NULL;
    memcpy(copy, text, len + 1u);
    return copy;
}

static JinxOracleResourceEntry *jinx_oracle_resource_find(void *ptr) {
    for (JinxOracleResourceEntry *it = jinx_oracle_resource_head; it != NULL; it = it->next) {
        if (it->ptr == ptr) return it;
    }
    return NULL;
}

int64_t jinx_oracle_resource_register(void *ptr, const char *type_name) {
    JinxOracleResourceEntry *existing;
    JinxOracleResourceEntry *entry;
    char *type_copy;

    if (ptr == NULL || type_name == NULL || *type_name == '\0') return 0;

    existing = jinx_oracle_resource_find(ptr);
    if (existing != NULL) return existing->id;

    type_copy = jinx_oracle_resource_strdup(type_name);
    if (type_copy == NULL) return 0;

    entry = (JinxOracleResourceEntry *)calloc(1u, sizeof(*entry));
    if (entry == NULL) {
        free(type_copy);
        return 0;
    }

    entry->ptr = ptr;
    entry->id = jinx_oracle_resource_next_id++;
    entry->type_name = type_copy;
    entry->next = jinx_oracle_resource_head;
    jinx_oracle_resource_head = entry;
    return entry->id;
}

void jinx_oracle_resource_unregister(void *ptr) {
    JinxOracleResourceEntry **link = &jinx_oracle_resource_head;
    while (*link != NULL) {
        JinxOracleResourceEntry *entry = *link;
        if (entry->ptr == ptr) {
            *link = entry->next;
            free(entry->type_name);
            free(entry);
            return;
        }
        link = &entry->next;
    }
}

int64_t jinx_oracle_resource_id(void *ptr) {
    JinxOracleResourceEntry *entry = jinx_oracle_resource_find(ptr);
    return entry != NULL ? entry->id : 0;
}

const char *jinx_oracle_resource_type(void *ptr) {
    JinxOracleResourceEntry *entry = jinx_oracle_resource_find(ptr);
    return entry != NULL ? entry->type_name : NULL;
}

size_t jinx_oracle_resource_count(const char *type_name) {
    size_t count = 0u;
    for (JinxOracleResourceEntry *it = jinx_oracle_resource_head; it != NULL; it = it->next) {
        if (type_name == NULL || strcmp(type_name, it->type_name) == 0) count++;
    }
    return count;
}

JinxValue jinx_oracle_resource_list_value(const char *type_name) {
    size_t count = jinx_oracle_resource_count(type_name);
    JinxZendArray *array = jinx_zend_array_new_packed(count == 0u ? 1u : count);
    if (array == NULL) return jinx_oracle_zero_value();

    for (JinxOracleResourceEntry *it = jinx_oracle_resource_head; it != NULL; it = it->next) {
        JinxZendValue resource = jinx_zend_null();
        if (type_name != NULL && strcmp(type_name, it->type_name) != 0) continue;
        resource.type = JINX_ZEND_RESOURCE;
        resource.value.ptr = it->ptr;
        if (!jinx_zend_array_add_index(array, (size_t)it->id, resource)) {
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
    }

    return jinx_oracle_zend_array_value_owned(array);
}
