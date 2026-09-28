#include "jinx_oracle_finfo_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"

#include <stdlib.h>
#include <string.h>

#ifdef JINX_HAVE_LIBMAGIC
#include <magic.h>

typedef struct JinxOracleFinfo {
    magic_t cookie;
    int flags;
} JinxOracleFinfo;

static int finfo_object_set_resource(
    JinxZendObject *object,
    const char *key,
    void *ptr
) {
    JinxZendValue value = jinx_zend_null();
    if (object == NULL || object->properties == NULL) return 0;
    value.type = JINX_ZEND_RESOURCE;
    value.value.ptr = ptr;
    return jinx_zend_array_add_assoc(
        object->properties, key, strlen(key), value
    );
}

static JinxOracleFinfo *finfo_from_value(JinxValue value) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *slot;
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, "finfo") != 0 ||
        object->properties == NULL) return NULL;
    slot = jinx_zend_array_find(object->properties, "__finfo", 7u);
    if (slot == NULL || slot->type != JINX_ZEND_RESOURCE) return NULL;
    return (JinxOracleFinfo *)slot->value.ptr;
}

static char *finfo_dup_string(JinxValue value) {
    uint32_t len;
    char *out;
    if (value.type != 3u || value.as.ptr == NULL) return NULL;
    len = jinx_oracle_string_len(value);
    out = (char *)malloc((size_t)len + 1u);
    if (out == NULL) return NULL;
    if (len != 0u) memcpy(out, jinx_oracle_string_bytes(value), len);
    out[len] = '\0';
    return out;
}

static JinxValue finfo_copy_string(const char *text) {
    char *out;
    size_t len;
    if (text == NULL) return jinx_oracle_bool_value(0);
    len = strlen(text);
    if (len > UINT32_MAX) return jinx_oracle_zero_value();
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u) memcpy(out, text, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

JinxValue jinx_oracle_finfo_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "finfo_open") == 0) {
        int flags = argc >= 1u ? (int)jinx_oracle_intish(args[0]) : 0;
        char *database = NULL;
        JinxOracleFinfo *ctx;
        JinxZendObject *object;
        if (argc >= 2u && args[1].type != 0u) {
            if (args[1].type != 3u) return result;
            database = finfo_dup_string(args[1]);
            if (database == NULL) return result;
        }
        ctx = (JinxOracleFinfo *)calloc(1u, sizeof(*ctx));
        if (ctx == NULL) {
            free(database);
            return result;
        }
        ctx->cookie = magic_open(flags);
        ctx->flags = flags;
        if (ctx->cookie == NULL ||
            magic_load(ctx->cookie, database) != 0) {
            if (ctx->cookie != NULL) magic_close(ctx->cookie);
            free(ctx);
            free(database);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        free(database);
        object = jinx_zend_object_new("finfo");
        if (object == NULL ||
            !finfo_object_set_resource(object, "__finfo", ctx)) {
            magic_close(ctx->cookie);
            free(ctx);
            jinx_zend_object_release(object);
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_object_value_owned(object);
    }

    if (strcmp(name, "finfo_buffer") == 0 ||
        strcmp(name, "finfo_file") == 0 ||
        strcmp(name, "finfo_set_flags") == 0 ||
        strcmp(name, "finfo_close") == 0) {
        JinxOracleFinfo *ctx;
        if (args == NULL || argc < 1u) return result;
        ctx = finfo_from_value(args[0]);
        if (ctx == NULL || ctx->cookie == NULL) return result;

        if (strcmp(name, "finfo_set_flags") == 0) {
            int flags;
            if (argc < 2u) return result;
            flags = (int)jinx_oracle_intish(args[1]);
            if (handled != NULL) *handled = 1;
            if (magic_setflags(ctx->cookie, flags) != 0) {
                return jinx_oracle_bool_value(0);
            }
            ctx->flags = flags;
            return jinx_oracle_bool_value(1);
        }

        if (strcmp(name, "finfo_close") == 0) {
            magic_close(ctx->cookie);
            ctx->cookie = NULL;
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(1);
        }

        if (argc < 2u || args[1].type != 3u) return result;

        if (strcmp(name, "finfo_buffer") == 0) {
            int previous = ctx->flags;
            int flags = argc >= 3u ? (int)jinx_oracle_intish(args[2]) : previous;
            const char *out;
            if (flags != previous && magic_setflags(ctx->cookie, flags) != 0) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            out = magic_buffer(
                ctx->cookie,
                jinx_oracle_string_bytes(args[1]),
                jinx_oracle_string_len(args[1])
            );
            if (flags != previous) (void)magic_setflags(ctx->cookie, previous);
            if (handled != NULL) *handled = 1;
            return finfo_copy_string(out);
        }

        if (strcmp(name, "finfo_file") == 0) {
            char *path = finfo_dup_string(args[1]);
            int previous = ctx->flags;
            int flags = argc >= 3u ? (int)jinx_oracle_intish(args[2]) : previous;
            const char *out;
            if (path == NULL) return result;
            if (flags != previous && magic_setflags(ctx->cookie, flags) != 0) {
                free(path);
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
            out = magic_file(ctx->cookie, path);
            if (flags != previous) (void)magic_setflags(ctx->cookie, previous);
            free(path);
            if (handled != NULL) *handled = 1;
            return finfo_copy_string(out);
        }
    }

    return result;
}

#else

JinxValue jinx_oracle_finfo_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    (void)name;
    (void)args;
    (void)argc;
    if (handled != NULL) *handled = 0;
    return jinx_oracle_zero_value();
}
#endif
