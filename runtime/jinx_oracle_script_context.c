#include "jinx_oracle_script_context.h"

#include <stdlib.h>
#include <string.h>

typedef struct JinxOracleScriptContext {
    char *main_path;
    char **files;
    size_t count;
    size_t capacity;
} JinxOracleScriptContext;

static _Thread_local JinxOracleScriptContext jinx_oracle_script_context = {0};

static char *jinx_oracle_script_context_strdup(const char *text) {
    size_t len;
    char *copy;
    if (text == NULL) return NULL;
    len = strlen(text);
    copy = (char *)malloc(len + 1u);
    if (copy == NULL) return NULL;
    memcpy(copy, text, len + 1u);
    return copy;
}

static char *jinx_oracle_script_context_normalize(const char *path) {
    char *resolved;
    if (path == NULL || *path == '\0') return NULL;
#if defined(_POSIX_VERSION)
    resolved = realpath(path, NULL);
    if (resolved != NULL) return resolved;
#endif
    return jinx_oracle_script_context_strdup(path);
}

void jinx_oracle_script_context_clear(void) {
    free(jinx_oracle_script_context.main_path);
    jinx_oracle_script_context.main_path = NULL;

    for (size_t i = 0u; i < jinx_oracle_script_context.count; i++) {
        free(jinx_oracle_script_context.files[i]);
    }
    free(jinx_oracle_script_context.files);
    jinx_oracle_script_context.files = NULL;
    jinx_oracle_script_context.count = 0u;
    jinx_oracle_script_context.capacity = 0u;
}

static int jinx_oracle_script_context_reserve(size_t wanted) {
    char **next;
    size_t capacity;
    if (wanted <= jinx_oracle_script_context.capacity) return 1;
    capacity = jinx_oracle_script_context.capacity == 0u
        ? 4u
        : jinx_oracle_script_context.capacity * 2u;
    while (capacity < wanted) capacity *= 2u;
    next = (char **)realloc(
        jinx_oracle_script_context.files,
        capacity * sizeof(char *)
    );
    if (next == NULL) return 0;
    jinx_oracle_script_context.files = next;
    jinx_oracle_script_context.capacity = capacity;
    return 1;
}

int jinx_oracle_script_context_add_include(const char *path) {
    char *normalized = jinx_oracle_script_context_normalize(path);
    if (normalized == NULL) return 0;

    for (size_t i = 0u; i < jinx_oracle_script_context.count; i++) {
        if (strcmp(jinx_oracle_script_context.files[i], normalized) == 0) {
            free(normalized);
            return 1;
        }
    }

    if (!jinx_oracle_script_context_reserve(
            jinx_oracle_script_context.count + 1u
        )) {
        free(normalized);
        return 0;
    }

    jinx_oracle_script_context.files[jinx_oracle_script_context.count++] =
        normalized;
    return 1;
}

int jinx_oracle_script_context_set_main(const char *path) {
    char *normalized = jinx_oracle_script_context_normalize(path);
    if (normalized == NULL) return 0;

    jinx_oracle_script_context_clear();
    jinx_oracle_script_context.main_path = normalized;

    if (!jinx_oracle_script_context_reserve(1u)) {
        jinx_oracle_script_context_clear();
        return 0;
    }

    jinx_oracle_script_context.files[0] =
        jinx_oracle_script_context_strdup(normalized);
    if (jinx_oracle_script_context.files[0] == NULL) {
        jinx_oracle_script_context_clear();
        return 0;
    }
    jinx_oracle_script_context.count = 1u;
    return 1;
}

const char *jinx_oracle_script_context_main(void) {
    return jinx_oracle_script_context.main_path;
}

size_t jinx_oracle_script_context_count(void) {
    return jinx_oracle_script_context.count;
}

const char *jinx_oracle_script_context_at(size_t index) {
    if (index >= jinx_oracle_script_context.count) return NULL;
    return jinx_oracle_script_context.files[index];
}
