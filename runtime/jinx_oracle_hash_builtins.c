#include "jinx_oracle_hash_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifdef JINX_HAVE_OPENSSL
#include <openssl/evp.h>
#include <openssl/hmac.h>

typedef struct JinxOracleHashContext {
    EVP_MD_CTX *ctx;
    const EVP_MD *md;
    int finalized;
} JinxOracleHashContext;

typedef struct JinxOracleHashStream {
    FILE *fp;
} JinxOracleHashStream;

static char *hash_dup_string(JinxValue value) {
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

static JinxValue hash_copy_bytes(const unsigned char *bytes, size_t len) {
    char *out;
    if (len > UINT32_MAX) return jinx_oracle_zero_value();
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u) memcpy(out, bytes, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

static JinxValue hash_hex_bytes(const unsigned char *bytes, size_t len) {
    static const char hex[] = "0123456789abcdef";
    char *out;
    if (len > UINT32_MAX / 2u) return jinx_oracle_zero_value();
    out = jinx_oracle_scratch_string((uint32_t)(len * 2u));
    for (size_t i = 0u; i < len; i++) {
        out[i * 2u] = hex[(bytes[i] >> 4) & 0x0f];
        out[i * 2u + 1u] = hex[bytes[i] & 0x0f];
    }
    return jinx_oracle_string_value_len(out, (uint32_t)(len * 2u));
}

static const EVP_MD *hash_md_from_value(JinxValue value) {
    char *name;
    const EVP_MD *md;
    if (value.type != 3u) return NULL;
    name = hash_dup_string(value);
    if (name == NULL) return NULL;
    md = EVP_get_digestbyname(name);
    free(name);
    return md;
}

static int hash_object_set_resource(
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

static void *hash_object_resource(
    JinxValue value,
    const char *class_name,
    const char *key
) {
    JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
    JinxZendValue *slot;
    if (object == NULL || object->class_name == NULL ||
        strcmp(object->class_name, class_name) != 0 ||
        object->properties == NULL) return NULL;
    slot = jinx_zend_array_find(object->properties, key, strlen(key));
    if (slot == NULL || slot->type != JINX_ZEND_RESOURCE) return NULL;
    return slot->value.ptr;
}

static JinxOracleHashContext *hash_context_from_value(JinxValue value) {
    return (JinxOracleHashContext *)hash_object_resource(
        value, "HashContext", "__hash"
    );
}

static FILE *hash_stream_file(JinxValue value) {
    JinxOracleHashStream *stream = (JinxOracleHashStream *)hash_object_resource(
        value, "stream", "__stream"
    );
    return stream != NULL ? stream->fp : NULL;
}

static JinxValue hash_new_context(const EVP_MD *md) {
    JinxOracleHashContext *context;
    JinxZendObject *object;
    if (md == NULL) return jinx_oracle_zero_value();
    context = (JinxOracleHashContext *)calloc(1u, sizeof(*context));
    if (context == NULL) return jinx_oracle_zero_value();
    context->ctx = EVP_MD_CTX_new();
    context->md = md;
    if (context->ctx == NULL ||
        EVP_DigestInit_ex(context->ctx, md, NULL) != 1) {
        EVP_MD_CTX_free(context->ctx);
        free(context);
        return jinx_oracle_zero_value();
    }
    object = jinx_zend_object_new("HashContext");
    if (object == NULL ||
        !hash_object_set_resource(object, "__hash", context)) {
        EVP_MD_CTX_free(context->ctx);
        free(context);
        jinx_zend_object_release(object);
        return jinx_oracle_zero_value();
    }
    return jinx_oracle_zend_object_value_owned(object);
}

static JinxValue hash_finish_bytes(
    const unsigned char *digest,
    unsigned int len,
    int binary
) {
    return binary
        ? hash_copy_bytes(digest, len)
        : hash_hex_bytes(digest, len);
}

static int hash_file_into_ctx(EVP_MD_CTX *ctx, const char *path) {
    FILE *fp;
    unsigned char buffer[16384];
    size_t n;
    int ok = 1;
    fp = fopen(path, "rb");
    if (fp == NULL) return 0;
    while ((n = fread(buffer, 1u, sizeof(buffer), fp)) != 0u) {
        if (EVP_DigestUpdate(ctx, buffer, n) != 1) {
            ok = 0;
            break;
        }
    }
    if (ferror(fp)) ok = 0;
    fclose(fp);
    return ok;
}

static int hash_hmac_file_bytes(
    const EVP_MD *md,
    const unsigned char *key,
    size_t key_len,
    const char *path,
    unsigned char *out,
    unsigned int *out_len
) {
    HMAC_CTX *ctx;
    FILE *fp;
    unsigned char buffer[16384];
    size_t n;
    int ok = 1;
    ctx = HMAC_CTX_new();
    if (ctx == NULL) return 0;
    if (HMAC_Init_ex(ctx, key, (int)key_len, md, NULL) != 1) {
        HMAC_CTX_free(ctx);
        return 0;
    }
    fp = fopen(path, "rb");
    if (fp == NULL) {
        HMAC_CTX_free(ctx);
        return 0;
    }
    while ((n = fread(buffer, 1u, sizeof(buffer), fp)) != 0u) {
        if (HMAC_Update(ctx, buffer, n) != 1) {
            ok = 0;
            break;
        }
    }
    if (ferror(fp)) ok = 0;
    fclose(fp);
    if (ok && HMAC_Final(ctx, out, out_len) != 1) ok = 0;
    HMAC_CTX_free(ctx);
    return ok;
}

static int hash_hkdf_bytes(
    const EVP_MD *md,
    const unsigned char *key,
    size_t key_len,
    const unsigned char *salt,
    size_t salt_len,
    const unsigned char *info,
    size_t info_len,
    unsigned char *out,
    size_t out_len
) {
    unsigned int hash_len = (unsigned int)EVP_MD_size(md);
    unsigned char prk[EVP_MAX_MD_SIZE];
    unsigned int prk_len = 0u;
    unsigned char previous[EVP_MAX_MD_SIZE];
    unsigned int previous_len = 0u;
    unsigned char zero_salt[EVP_MAX_MD_SIZE] = {0};
    size_t written = 0u;
    unsigned char counter = 1u;

    if (hash_len == 0u || out_len > (size_t)hash_len * 255u) return 0;
    if (salt_len == 0u) {
        salt = zero_salt;
        salt_len = hash_len;
    }
    if (HMAC(md, salt, (int)salt_len, key, key_len, prk, &prk_len) == NULL) {
        return 0;
    }

    while (written < out_len) {
        HMAC_CTX *ctx = HMAC_CTX_new();
        unsigned int block_len = 0u;
        size_t take;
        if (ctx == NULL) return 0;
        if (HMAC_Init_ex(ctx, prk, (int)prk_len, md, NULL) != 1 ||
            (previous_len != 0u &&
             HMAC_Update(ctx, previous, previous_len) != 1) ||
            (info_len != 0u &&
             HMAC_Update(ctx, info, info_len) != 1) ||
            HMAC_Update(ctx, &counter, 1u) != 1 ||
            HMAC_Final(ctx, previous, &block_len) != 1) {
            HMAC_CTX_free(ctx);
            return 0;
        }
        HMAC_CTX_free(ctx);
        previous_len = block_len;
        take = out_len - written;
        if (take > block_len) take = block_len;
        memcpy(out + written, previous, take);
        written += take;
        counter++;
    }
    return 1;
}

JinxValue jinx_oracle_hash_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();
    if (handled != NULL) *handled = 0;
    if (name == NULL) return result;

    if (strcmp(name, "hash") == 0) {
        const EVP_MD *md;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int binary;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        md = hash_md_from_value(args[0]);
        if (md == NULL) return result;
        if (EVP_Digest(
            jinx_oracle_string_bytes(args[1]),
            jinx_oracle_string_len(args[1]),
            digest,
            &digest_len,
            md,
            NULL
        ) != 1) return result;
        binary = argc >= 3u && jinx_oracle_boolish(args[2]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, binary);
    }

    if (strcmp(name, "hash_file") == 0) {
        const EVP_MD *md;
        EVP_MD_CTX *ctx;
        char *path;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int binary;
        int ok;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        md = hash_md_from_value(args[0]);
        path = hash_dup_string(args[1]);
        if (md == NULL || path == NULL) {
            free(path);
            return result;
        }
        ctx = EVP_MD_CTX_new();
        if (ctx == NULL || EVP_DigestInit_ex(ctx, md, NULL) != 1) {
            EVP_MD_CTX_free(ctx);
            free(path);
            return result;
        }
        ok = hash_file_into_ctx(ctx, path);
        free(path);
        if (!ok || EVP_DigestFinal_ex(ctx, digest, &digest_len) != 1) {
            EVP_MD_CTX_free(ctx);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        EVP_MD_CTX_free(ctx);
        binary = argc >= 3u && jinx_oracle_boolish(args[2]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, binary);
    }

    if (strcmp(name, "hash_init") == 0) {
        const EVP_MD *md;
        int64_t flags = argc >= 2u ? jinx_oracle_intish(args[1]) : 0;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (flags != 0) return result;
        md = hash_md_from_value(args[0]);
        if (md == NULL) return result;
        result = hash_new_context(md);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "hash_update") == 0) {
        JinxOracleHashContext *context;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        context = hash_context_from_value(args[0]);
        if (context == NULL || context->finalized) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(
            EVP_DigestUpdate(
                context->ctx,
                jinx_oracle_string_bytes(args[1]),
                jinx_oracle_string_len(args[1])
            ) == 1
        );
    }

    if (strcmp(name, "hash_final") == 0) {
        JinxOracleHashContext *context;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int binary;
        if (args == NULL || argc < 1u) return result;
        context = hash_context_from_value(args[0]);
        if (context == NULL || context->finalized) return result;
        if (EVP_DigestFinal_ex(context->ctx, digest, &digest_len) != 1) return result;
        context->finalized = 1;
        binary = argc >= 2u && jinx_oracle_boolish(args[1]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, binary);
    }

    if (strcmp(name, "hash_copy") == 0) {
        JinxOracleHashContext *source;
        JinxOracleHashContext *copy;
        JinxZendObject *object;
        if (args == NULL || argc < 1u) return result;
        source = hash_context_from_value(args[0]);
        if (source == NULL || source->finalized) return result;
        copy = (JinxOracleHashContext *)calloc(1u, sizeof(*copy));
        if (copy == NULL) return result;
        copy->ctx = EVP_MD_CTX_new();
        copy->md = source->md;
        if (copy->ctx == NULL ||
            EVP_MD_CTX_copy_ex(copy->ctx, source->ctx) != 1) {
            EVP_MD_CTX_free(copy->ctx);
            free(copy);
            return result;
        }
        object = jinx_zend_object_new("HashContext");
        if (object == NULL ||
            !hash_object_set_resource(object, "__hash", copy)) {
            EVP_MD_CTX_free(copy->ctx);
            free(copy);
            jinx_zend_object_release(object);
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_zend_object_value_owned(object);
    }

    if (strcmp(name, "hash_update_file") == 0) {
        JinxOracleHashContext *context;
        char *path;
        int ok;
        if (args == NULL || argc < 2u || args[1].type != 3u) return result;
        context = hash_context_from_value(args[0]);
        if (context == NULL || context->finalized) return result;
        path = hash_dup_string(args[1]);
        if (path == NULL) return result;
        ok = hash_file_into_ctx(context->ctx, path);
        free(path);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(ok);
    }

    if (strcmp(name, "hash_update_stream") == 0) {
        JinxOracleHashContext *context;
        FILE *fp;
        int64_t remaining = -1;
        unsigned char buffer[16384];
        size_t total = 0u;
        if (args == NULL || argc < 2u) return result;
        context = hash_context_from_value(args[0]);
        fp = hash_stream_file(args[1]);
        if (context == NULL || context->finalized || fp == NULL) return result;
        if (argc >= 3u && args[2].type != 0u) remaining = jinx_oracle_intish(args[2]);
        while (remaining != 0) {
            size_t take = sizeof(buffer);
            size_t n;
            if (remaining > 0 && (uint64_t)remaining < take) take = (size_t)remaining;
            n = fread(buffer, 1u, take, fp);
            if (n == 0u) break;
            if (EVP_DigestUpdate(context->ctx, buffer, n) != 1) return result;
            total += n;
            if (remaining > 0) remaining -= (int64_t)n;
        }
        if (ferror(fp)) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)total);
    }

    if (strcmp(name, "hash_hmac") == 0) {
        const EVP_MD *md;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int binary;
        if (args == NULL || argc < 3u ||
            args[0].type != 3u || args[1].type != 3u || args[2].type != 3u) return result;
        md = hash_md_from_value(args[0]);
        if (md == NULL) return result;
        if (HMAC(
            md,
            jinx_oracle_string_bytes(args[2]),
            (int)jinx_oracle_string_len(args[2]),
            jinx_oracle_string_bytes(args[1]),
            jinx_oracle_string_len(args[1]),
            digest,
            &digest_len
        ) == NULL) return result;
        binary = argc >= 4u && jinx_oracle_boolish(args[3]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, binary);
    }

    if (strcmp(name, "hash_hmac_file") == 0) {
        const EVP_MD *md;
        char *path;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int binary;
        int ok;
        if (args == NULL || argc < 3u ||
            args[0].type != 3u || args[1].type != 3u || args[2].type != 3u) return result;
        md = hash_md_from_value(args[0]);
        path = hash_dup_string(args[1]);
        if (md == NULL || path == NULL) {
            free(path);
            return result;
        }
        ok = hash_hmac_file_bytes(
            md,
            jinx_oracle_string_bytes(args[2]),
            jinx_oracle_string_len(args[2]),
            path,
            digest,
            &digest_len
        );
        free(path);
        if (!ok) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        binary = argc >= 4u && jinx_oracle_boolish(args[3]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, binary);
    }

    if (strcmp(name, "hash_pbkdf2") == 0) {
        const EVP_MD *md;
        int iterations;
        int64_t requested_len;
        int binary;
        int digest_size;
        size_t raw_len;
        unsigned char *out;
        JinxValue formatted;
        if (args == NULL || argc < 4u ||
            args[0].type != 3u || args[1].type != 3u || args[2].type != 3u) return result;
        md = hash_md_from_value(args[0]);
        if (md == NULL) return result;
        iterations = (int)jinx_oracle_intish(args[3]);
        requested_len = argc >= 5u ? jinx_oracle_intish(args[4]) : 0;
        binary = argc >= 6u && jinx_oracle_boolish(args[5]);
        if (iterations <= 0 || requested_len < 0) return result;
        digest_size = EVP_MD_size(md);
        if (digest_size <= 0) return result;
        if (requested_len == 0) {
            raw_len = (size_t)digest_size;
        } else {
            raw_len = binary
                ? (size_t)requested_len
                : ((size_t)requested_len + 1u) / 2u;
        }
        if (raw_len > INT_MAX) return result;
        out = (unsigned char *)malloc(raw_len == 0u ? 1u : raw_len);
        if (out == NULL) return result;
        if (PKCS5_PBKDF2_HMAC(
            (const char *)jinx_oracle_string_bytes(args[1]),
            (int)jinx_oracle_string_len(args[1]),
            jinx_oracle_string_bytes(args[2]),
            (int)jinx_oracle_string_len(args[2]),
            iterations,
            md,
            (int)raw_len,
            out
        ) != 1) {
            free(out);
            return result;
        }
        if (binary) {
            formatted = hash_copy_bytes(out, raw_len);
        } else {
            formatted = hash_hex_bytes(out, raw_len);
            if (requested_len > 0 &&
                formatted.type == 3u &&
                formatted.flags > (uint32_t)requested_len) {
                formatted.flags = (uint32_t)requested_len;
            }
        }
        free(out);
        if (formatted.type != 0u && handled != NULL) *handled = 1;
        return formatted;
    }

    if (strcmp(name, "hash_hkdf") == 0) {
        const EVP_MD *md;
        int64_t requested_len;
        int digest_size;
        size_t out_len;
        const unsigned char *info = (const unsigned char *)"";
        size_t info_len = 0u;
        const unsigned char *salt = (const unsigned char *)"";
        size_t salt_len = 0u;
        unsigned char *out;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        md = hash_md_from_value(args[0]);
        if (md == NULL) return result;
        requested_len = argc >= 3u ? jinx_oracle_intish(args[2]) : 0;
        if (requested_len < 0) return result;
        digest_size = EVP_MD_size(md);
        if (digest_size <= 0) return result;
        out_len = requested_len == 0 ? (size_t)digest_size : (size_t)requested_len;
        if (argc >= 4u && args[3].type != 0u) {
            if (args[3].type != 3u) return result;
            info = jinx_oracle_string_bytes(args[3]);
            info_len = jinx_oracle_string_len(args[3]);
        }
        if (argc >= 5u && args[4].type != 0u) {
            if (args[4].type != 3u) return result;
            salt = jinx_oracle_string_bytes(args[4]);
            salt_len = jinx_oracle_string_len(args[4]);
        }
        out = (unsigned char *)malloc(out_len == 0u ? 1u : out_len);
        if (out == NULL) return result;
        if (!hash_hkdf_bytes(
            md,
            jinx_oracle_string_bytes(args[1]),
            jinx_oracle_string_len(args[1]),
            salt,
            salt_len,
            info,
            info_len,
            out,
            out_len
        )) {
            free(out);
            return result;
        }
        result = hash_copy_bytes(out, out_len);
        free(out);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    return result;
}

#else

JinxValue jinx_oracle_hash_builtin(
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
