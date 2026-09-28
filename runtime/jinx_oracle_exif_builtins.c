#include "jinx_oracle_exif_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"

#include <stdlib.h>
#include <string.h>

#ifdef JINX_HAVE_LIBEXIF
#include <libexif/exif-data.h>

static char *exif_dup_value(JinxValue value) {
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

static JinxValue exif_copy_bytes(
    const unsigned char *bytes,
    size_t len
) {
    char *out;
    if (bytes == NULL || len > UINT32_MAX) {
        return jinx_oracle_zero_value();
    }
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u) memcpy(out, bytes, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

JinxValue jinx_oracle_exif_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();

    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "exif_thumbnail") == 0) {
        char *path;
        ExifData *data;

        /*
         * PHP's optional width/height/type outputs are references. The direct
         * native builtin bridge does not yet carry by-ref output slots, so
         * support the exact one-argument return form and fail closed otherwise.
         */
        if (args == NULL || argc != 1u || args[0].type != 3u) {
            return result;
        }

        path = exif_dup_value(args[0]);
        if (path == NULL || path[0] == '\0') {
            free(path);
            return result;
        }

        data = exif_data_new_from_file(path);
        free(path);
        if (handled != NULL) *handled = 1;

        if (data == NULL) {
            return jinx_oracle_bool_value(0);
        }

        if (data->data == NULL || data->size == 0u) {
            exif_data_unref(data);
            return jinx_oracle_bool_value(0);
        }

        result = exif_copy_bytes(data->data, data->size);
        exif_data_unref(data);
        return result;
    }

    return result;
}

#else

JinxValue jinx_oracle_exif_builtin(
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
