#include "jinx_oracle_hash_builtins.h"
#include "jinx_oracle_zend_array_builtins.h"
#include "jinx_native_core_metadata.generated.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifdef JINX_HAVE_OPENSSL
#include <openssl/evp.h>
#include <openssl/hmac.h>
#include <openssl/rand.h>
#include <openssl/err.h>

typedef struct JinxOracleHashContext {
    EVP_MD_CTX *ctx;
    const EVP_MD *md;
    int finalized;
} JinxOracleHashContext;

typedef struct JinxOracleHashStream {
    FILE *fp;
} JinxOracleHashStream;


typedef struct JinxMhashCompatEntry {
    const char *mhash_name;
    const char *hash_name;
} JinxMhashCompatEntry;

#define JINX_MHASH_NUM_ALGOS 42

static const JinxMhashCompatEntry jinx_mhash_to_hash[JINX_MHASH_NUM_ALGOS] = {
    {"CRC32", "crc32"},
    {"MD5", "md5"},
    {"SHA1", "sha1"},
    {"HAVAL256", "haval256,3"},
    {NULL, NULL},
    {"RIPEMD160", "ripemd160"},
    {NULL, NULL},
    {"TIGER", "tiger192,3"},
    {"GOST", "gost"},
    {"CRC32B", "crc32b"},
    {"HAVAL224", "haval224,3"},
    {"HAVAL192", "haval192,3"},
    {"HAVAL160", "haval160,3"},
    {"HAVAL128", "haval128,3"},
    {"TIGER128", "tiger128,3"},
    {"TIGER160", "tiger160,3"},
    {"MD4", "md4"},
    {"SHA256", "sha256"},
    {"ADLER32", "adler32"},
    {"SHA224", "sha224"},
    {"SHA512", "sha512"},
    {"SHA384", "sha384"},
    {"WHIRLPOOL", "whirlpool"},
    {"RIPEMD128", "ripemd128"},
    {"RIPEMD256", "ripemd256"},
    {"RIPEMD320", "ripemd320"},
    {NULL, NULL},
    {"SNEFRU256", "snefru256"},
    {"MD2", "md2"},
    {"FNV132", "fnv132"},
    {"FNV1A32", "fnv1a32"},
    {"FNV164", "fnv164"},
    {"FNV1A64", "fnv1a64"},
    {"JOAAT", "joaat"},
    {"CRC32C", "crc32c"},
    {"MURMUR3A", "murmur3a"},
    {"MURMUR3C", "murmur3c"},
    {"MURMUR3F", "murmur3f"},
    {"XXH32", "xxh32"},
    {"XXH64", "xxh64"},
    {"XXH3", "xxh3"},
    {"XXH128", "xxh128"},
};

static const JinxMhashCompatEntry *hash_mhash_entry(int64_t algorithm) {
    if (algorithm < 0 || algorithm >= JINX_MHASH_NUM_ALGOS) return NULL;
    if (jinx_mhash_to_hash[algorithm].mhash_name == NULL) return NULL;
    return &jinx_mhash_to_hash[algorithm];
}

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

static JinxValue hash_string_array_value(
    const char *const *items,
    size_t count
) {
    JinxZendArray *array = jinx_zend_array_new_packed(count == 0u ? 1u : count);
    if (array == NULL) return jinx_oracle_zero_value();
    for (size_t i = 0u; i < count; i++) {
        JinxZendString *string;
        if (items[i] == NULL) continue;
        string = jinx_zend_string_new(items[i], strlen(items[i]));
        if (string == NULL ||
            !jinx_zend_array_append(array, jinx_zend_string_value(string))) {
            jinx_zend_string_release(string);
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
        jinx_zend_string_release(string);
    }
    return jinx_oracle_zend_array_value_owned(array);
}

static JinxValue hash_string_pair_array_value(
    const JinxNativeStringPair *items,
    size_t count
) {
    JinxZendArray *array = jinx_zend_array_new_packed(count == 0u ? 1u : count);
    if (array == NULL) return jinx_oracle_zero_value();
    for (size_t i = 0u; i < count; i++) {
        JinxZendString *value;
        if (items[i].name == NULL || items[i].value == NULL) continue;
        value = jinx_zend_string_new(items[i].value, strlen(items[i].value));
        if (value == NULL ||
            !jinx_zend_array_add_assoc(
                array,
                items[i].name,
                strlen(items[i].name),
                jinx_zend_string_value(value)
            )) {
            jinx_zend_string_release(value);
            jinx_zend_array_release(array);
            return jinx_oracle_zero_value();
        }
        jinx_zend_string_release(value);
    }
    return jinx_oracle_zend_array_value_owned(array);
}

static JinxValue hash_base64_encode(const unsigned char *bytes, size_t len) {
    size_t out_len;
    unsigned char *tmp;
    int written;
    JinxValue out;
    if (len > (size_t)INT_MAX) return jinx_oracle_zero_value();
    out_len = 4u * ((len + 2u) / 3u);
    tmp = (unsigned char *)malloc(out_len + 1u);
    if (tmp == NULL) return jinx_oracle_zero_value();
    written = EVP_EncodeBlock(tmp, bytes, (int)len);
    if (written < 0) {
        free(tmp);
        return jinx_oracle_zero_value();
    }
    out = hash_copy_bytes(tmp, (size_t)written);
    free(tmp);
    return out;
}

static unsigned char *hash_base64_decode(
    const unsigned char *bytes,
    size_t len,
    size_t *out_len
) {
    unsigned char *clean = NULL;
    unsigned char *out = NULL;
    size_t clean_len = 0u;
    size_t padding = 0u;
    int decoded;

    if (out_len != NULL) *out_len = 0u;
    if (bytes == NULL && len != 0u) return NULL;

    clean = (unsigned char *)malloc(len + 1u);
    if (clean == NULL) return NULL;
    for (size_t i = 0u; i < len; i++) {
        unsigned char ch = bytes[i];
        if (ch == ' ' || ch == '\t' || ch == '\r' || ch == '\n') continue;
        clean[clean_len++] = ch;
    }
    clean[clean_len] = '\0';

    if (clean_len == 0u) {
        free(clean);
        out = (unsigned char *)malloc(1u);
        if (out != NULL && out_len != NULL) *out_len = 0u;
        return out;
    }
    if ((clean_len % 4u) != 0u || clean_len > (size_t)INT_MAX) {
        free(clean);
        return NULL;
    }
    if (clean_len >= 1u && clean[clean_len - 1u] == '=') padding++;
    if (clean_len >= 2u && clean[clean_len - 2u] == '=') padding++;

    out = (unsigned char *)malloc((clean_len / 4u) * 3u + 1u);
    if (out == NULL) {
        free(clean);
        return NULL;
    }
    decoded = EVP_DecodeBlock(out, clean, (int)clean_len);
    free(clean);
    if (decoded < 0 || (size_t)decoded < padding) {
        free(out);
        return NULL;
    }
    if (out_len != NULL) *out_len = (size_t)decoded - padding;
    return out;
}

static int hash_cipher_is_aead(const EVP_CIPHER *cipher) {
    int mode;
    if (cipher == NULL) return 0;
    mode = EVP_CIPHER_mode(cipher);
#ifdef EVP_CIPH_GCM_MODE
    if (mode == EVP_CIPH_GCM_MODE) return 1;
#endif
#ifdef EVP_CIPH_CCM_MODE
    if (mode == EVP_CIPH_CCM_MODE) return 1;
#endif
#ifdef EVP_CIPH_OCB_MODE
    if (mode == EVP_CIPH_OCB_MODE) return 1;
#endif
#ifdef NID_chacha20_poly1305
    if (EVP_CIPHER_nid(cipher) == NID_chacha20_poly1305) return 1;
#endif
    return 0;
}

static JinxValue hash_openssl_cipher_crypt(
    int encrypting,
    JinxValue data_value,
    JinxValue method_value,
    JinxValue password_value,
    int64_t options,
    JinxValue iv_value,
    int *supported
) {
    const EVP_CIPHER *cipher;
    EVP_CIPHER_CTX *ctx = NULL;
    char *method = NULL;
    const unsigned char *input;
    size_t input_len;
    unsigned char *decoded_input = NULL;
    unsigned char *key_buf = NULL;
    unsigned char *iv_buf = NULL;
    const unsigned char *key;
    const unsigned char *iv = NULL;
    size_t password_len;
    size_t iv_len;
    int key_len;
    int required_iv_len;
    int block_size;
    unsigned char *out = NULL;
    int out1 = 0;
    int out2 = 0;
    JinxValue result = jinx_oracle_zero_value();

    if (supported != NULL) *supported = 0;
    if (data_value.type != 3u || method_value.type != 3u ||
        password_value.type != 3u || iv_value.type != 3u) {
        return result;
    }

    method = hash_dup_string(method_value);
    if (method == NULL) return result;
    cipher = EVP_get_cipherbyname(method);
    free(method);
    if (cipher == NULL) {
        if (supported != NULL) *supported = 1;
        return jinx_oracle_bool_value(0);
    }
    if (hash_cipher_is_aead(cipher)) {
        return result;
    }
    if (supported != NULL) *supported = 1;

    input = jinx_oracle_string_bytes(data_value);
    input_len = jinx_oracle_string_len(data_value);
    if (!encrypting && (options & 1LL) == 0) {
        decoded_input = hash_base64_decode(input, input_len, &input_len);
        if (decoded_input == NULL) return jinx_oracle_bool_value(0);
        input = decoded_input;
    }
    if (input_len > (size_t)INT_MAX) goto fail;

    password_len = jinx_oracle_string_len(password_value);
    iv_len = jinx_oracle_string_len(iv_value);
    key_len = EVP_CIPHER_key_length(cipher);
    required_iv_len = EVP_CIPHER_iv_length(cipher);
    block_size = EVP_CIPHER_block_size(cipher);
    if (key_len < 0 || required_iv_len < 0 || block_size <= 0) goto fail;

    ctx = EVP_CIPHER_CTX_new();
    if (ctx == NULL ||
        EVP_CipherInit_ex(ctx, cipher, NULL, NULL, NULL, encrypting) != 1) {
        goto fail;
    }

    if (required_iv_len > 0) {
        iv_buf = (unsigned char *)calloc((size_t)required_iv_len, 1u);
        if (iv_buf == NULL) goto fail;
        if (iv_len != 0u) {
            size_t copy_len = iv_len < (size_t)required_iv_len
                ? iv_len
                : (size_t)required_iv_len;
            memcpy(iv_buf, jinx_oracle_string_bytes(iv_value), copy_len);
        }
        iv = iv_buf;
    }

    key = jinx_oracle_string_bytes(password_value);
    if (password_len < (size_t)key_len) {
        if ((options & 4LL) != 0) {
            if (password_len > (size_t)INT_MAX ||
                EVP_CIPHER_CTX_set_key_length(ctx, (int)password_len) != 1) {
                goto fail;
            }
        } else {
            key_buf = (unsigned char *)calloc((size_t)key_len, 1u);
            if (key_buf == NULL) goto fail;
            if (password_len != 0u) {
                memcpy(key_buf, key, password_len);
            }
            key = key_buf;
        }
    } else if (password_len > (size_t)key_len) {
        if (password_len <= (size_t)INT_MAX) {
            (void)EVP_CIPHER_CTX_set_key_length(ctx, (int)password_len);
        }
    }

    if (EVP_CipherInit_ex(ctx, NULL, NULL, key, iv, encrypting) != 1) {
        goto fail;
    }
    if ((options & 2LL) != 0) {
        (void)EVP_CIPHER_CTX_set_padding(ctx, 0);
    }

    out = (unsigned char *)malloc(input_len + (size_t)block_size + 1u);
    if (out == NULL) goto fail;
    if (EVP_CipherUpdate(
            ctx,
            out,
            &out1,
            input,
            (int)input_len
        ) != 1 ||
        EVP_CipherFinal_ex(ctx, out + out1, &out2) != 1) {
        result = jinx_oracle_bool_value(0);
        goto cleanup;
    }

    if (encrypting && (options & 1LL) == 0) {
        result = hash_base64_encode(out, (size_t)(out1 + out2));
    } else {
        result = hash_copy_bytes(out, (size_t)(out1 + out2));
    }
    goto cleanup;

fail:
    result = jinx_oracle_bool_value(0);

cleanup:
    free(decoded_input);
    free(key_buf);
    free(iv_buf);
    free(out);
    EVP_CIPHER_CTX_free(ctx);
    return result;
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

    if (strcmp(name, "mhash_count") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value(JINX_MHASH_NUM_ALGOS - 1);
    }

    if (strcmp(name, "mhash_get_hash_name") == 0) {
        const JinxMhashCompatEntry *entry;
        if (args == NULL || argc != 1u) return result;
        entry = hash_mhash_entry(jinx_oracle_intish(args[0]));
        if (handled != NULL) *handled = 1;
        return entry != NULL
            ? hash_copy_bytes(
                (const unsigned char *)entry->mhash_name,
                strlen(entry->mhash_name)
            )
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "mhash_get_block_size") == 0) {
        const JinxMhashCompatEntry *entry;
        const EVP_MD *md;
        int size;
        if (args == NULL || argc != 1u) return result;
        entry = hash_mhash_entry(jinx_oracle_intish(args[0]));
        if (entry == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        md = EVP_get_digestbyname(entry->hash_name);
        if (handled != NULL) *handled = 1;
        if (md == NULL) return jinx_oracle_bool_value(0);
        size = EVP_MD_size(md);
        return size > 0
            ? jinx_oracle_int_value((int64_t)size)
            : jinx_oracle_bool_value(0);
    }

    if (strcmp(name, "mhash_keygen_s2k") == 0) {
        const JinxMhashCompatEntry *entry;
        const EVP_MD *md;
        int64_t requested;
        int digest_size;
        unsigned char padded_salt[8] = {0};
        size_t salt_len;
        unsigned char *key;
        size_t written = 0u;
        size_t block_index = 0u;

        if (args == NULL || argc != 4u ||
            args[1].type != 3u || args[2].type != 3u) {
            return result;
        }
        requested = jinx_oracle_intish(args[3]);
        if (requested <= 0 || (uint64_t)requested > SIZE_MAX) return result;

        entry = hash_mhash_entry(jinx_oracle_intish(args[0]));
        if (entry == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        md = EVP_get_digestbyname(entry->hash_name);
        if (md == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        digest_size = EVP_MD_size(md);
        if (digest_size <= 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        salt_len = jinx_oracle_string_len(args[2]);
        if (salt_len > sizeof(padded_salt)) salt_len = sizeof(padded_salt);
        if (salt_len != 0u) {
            memcpy(
                padded_salt,
                jinx_oracle_string_bytes(args[2]),
                salt_len
            );
        }

        key = (unsigned char *)malloc((size_t)requested);
        if (key == NULL) return result;

        while (written < (size_t)requested) {
            EVP_MD_CTX *ctx = EVP_MD_CTX_new();
            unsigned char digest[EVP_MAX_MD_SIZE];
            unsigned int digest_len = 0u;
            unsigned char zero = 0u;
            size_t take;

            if (ctx == NULL || EVP_DigestInit_ex(ctx, md, NULL) != 1) {
                EVP_MD_CTX_free(ctx);
                free(key);
                return result;
            }
            for (size_t j = 0u; j < block_index; j++) {
                if (EVP_DigestUpdate(ctx, &zero, 1u) != 1) {
                    EVP_MD_CTX_free(ctx);
                    free(key);
                    return result;
                }
            }
            if (EVP_DigestUpdate(ctx, padded_salt, sizeof(padded_salt)) != 1 ||
                EVP_DigestUpdate(
                    ctx,
                    jinx_oracle_string_bytes(args[1]),
                    jinx_oracle_string_len(args[1])
                ) != 1 ||
                EVP_DigestFinal_ex(ctx, digest, &digest_len) != 1) {
                EVP_MD_CTX_free(ctx);
                free(key);
                return result;
            }
            EVP_MD_CTX_free(ctx);

            take = (size_t)requested - written;
            if (take > digest_len) take = digest_len;
            memcpy(key + written, digest, take);
            written += take;
            block_index++;
        }

        result = hash_copy_bytes(key, (size_t)requested);
        memset(key, 0, (size_t)requested);
        free(key);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "mhash") == 0) {
        const JinxMhashCompatEntry *entry;
        const EVP_MD *md;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int64_t algorithm;

        if (args == NULL || argc < 2u || argc > 3u ||
            args[1].type != 3u) {
            return result;
        }
        algorithm = jinx_oracle_intish(args[0]);
        entry = hash_mhash_entry(algorithm);
        if (entry == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        md = EVP_get_digestbyname(entry->hash_name);
        if (md == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (argc == 3u && args[2].type != 0u) {
            if (args[2].type != 3u ||
                jinx_oracle_string_len(args[2]) > (uint32_t)INT_MAX) {
                return result;
            }
            if (HMAC(
                    md,
                    jinx_oracle_string_bytes(args[2]),
                    (int)jinx_oracle_string_len(args[2]),
                    jinx_oracle_string_bytes(args[1]),
                    jinx_oracle_string_len(args[1]),
                    digest,
                    &digest_len
                ) == NULL) {
                if (handled != NULL) *handled = 1;
                return jinx_oracle_bool_value(0);
            }
        } else if (EVP_Digest(
                jinx_oracle_string_bytes(args[1]),
                jinx_oracle_string_len(args[1]),
                digest,
                &digest_len,
                md,
                NULL
            ) != 1) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (handled != NULL) *handled = 1;
        return hash_copy_bytes(digest, digest_len);
    }

    if (strcmp(name, "openssl_get_cert_locations") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return hash_string_pair_array_value(
            jinx_native_openssl_cert_locations,
            jinx_native_openssl_cert_locations_count
        );
    }

    if (strcmp(name, "openssl_error_string") == 0) {
        unsigned long error_code;
        char buffer[256];
        if (argc != 0u) return result;
        error_code = ERR_get_error();
        if (handled != NULL) *handled = 1;
        if (error_code == 0ul) return jinx_oracle_bool_value(0);
        ERR_error_string_n(error_code, buffer, sizeof(buffer));
        return hash_copy_bytes(
            (const unsigned char *)buffer,
            strlen(buffer)
        );
    }

    if (strcmp(name, "openssl_pbkdf2") == 0) {
        const EVP_MD *md = EVP_sha1();
        int64_t key_length;
        int64_t iterations;
        unsigned char *out;
        if (args == NULL || argc < 4u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        key_length = jinx_oracle_intish(args[2]);
        iterations = jinx_oracle_intish(args[3]);
        if (key_length <= 0 || key_length > INT_MAX ||
            iterations < INT_MIN || iterations > INT_MAX) {
            return result;
        }
        if (argc >= 5u) {
            if (args[4].type != 3u) return result;
            if (jinx_oracle_string_len(args[4]) != 0u) {
                md = hash_md_from_value(args[4]);
                if (md == NULL) {
                    if (handled != NULL) *handled = 1;
                    return jinx_oracle_bool_value(0);
                }
            }
        }
        if (argc > 5u ||
            jinx_oracle_string_len(args[0]) > (uint32_t)INT_MAX ||
            jinx_oracle_string_len(args[1]) > (uint32_t)INT_MAX) {
            return result;
        }
        out = (unsigned char *)malloc((size_t)key_length);
        if (out == NULL) return result;
        if (PKCS5_PBKDF2_HMAC(
                (const char *)jinx_oracle_string_bytes(args[0]),
                (int)jinx_oracle_string_len(args[0]),
                jinx_oracle_string_bytes(args[1]),
                (int)jinx_oracle_string_len(args[1]),
                (int)iterations,
                md,
                (int)key_length,
                out
            ) != 1) {
            free(out);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        result = hash_copy_bytes(out, (size_t)key_length);
        free(out);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "openssl_get_cipher_methods") == 0) {
        int aliases = args != NULL && argc >= 1u
            ? jinx_oracle_boolish(args[0])
            : 0;
        if (argc > 1u) return result;
        if (handled != NULL) *handled = 1;
        return aliases
            ? hash_string_array_value(
                jinx_native_openssl_cipher_methods_aliases,
                jinx_native_openssl_cipher_methods_aliases_count
            )
            : hash_string_array_value(
                jinx_native_openssl_cipher_methods,
                jinx_native_openssl_cipher_methods_count
            );
    }

    if (strcmp(name, "openssl_get_md_methods") == 0) {
        int aliases = args != NULL && argc >= 1u
            ? jinx_oracle_boolish(args[0])
            : 0;
        if (argc > 1u) return result;
        if (handled != NULL) *handled = 1;
        return aliases
            ? hash_string_array_value(
                jinx_native_openssl_md_methods_aliases,
                jinx_native_openssl_md_methods_aliases_count
            )
            : hash_string_array_value(
                jinx_native_openssl_md_methods,
                jinx_native_openssl_md_methods_count
            );
    }

    if (strcmp(name, "openssl_get_curve_names") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return hash_string_array_value(
            jinx_native_openssl_curve_names,
            jinx_native_openssl_curve_names_count
        );
    }

    if (strcmp(name, "openssl_random_pseudo_bytes") == 0) {
        int64_t length;
        unsigned char *bytes;
        if (args == NULL || argc != 1u) return result;
        length = jinx_oracle_intish(args[0]);
        if (length <= 0 || length > INT_MAX) return result;
        bytes = (unsigned char *)malloc((size_t)length);
        if (bytes == NULL) return result;
        if (RAND_bytes(bytes, (int)length) <= 0) {
            free(bytes);
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        result = hash_copy_bytes(bytes, (size_t)length);
        free(bytes);
        if (result.type != 0u && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "openssl_digest") == 0) {
        const EVP_MD *md;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int raw_output;
        if (args == NULL || argc < 2u ||
            args[0].type != 3u || args[1].type != 3u) return result;
        md = hash_md_from_value(args[1]);
        if (md == NULL) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (EVP_Digest(
                jinx_oracle_string_bytes(args[0]),
                jinx_oracle_string_len(args[0]),
                digest,
                &digest_len,
                md,
                NULL
            ) != 1) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        raw_output = argc >= 3u && jinx_oracle_boolish(args[2]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, raw_output);
    }

    if (strcmp(name, "openssl_cipher_iv_length") == 0 ||
        strcmp(name, "openssl_cipher_key_length") == 0) {
        char *method;
        const EVP_CIPHER *cipher;
        int length;
        if (args == NULL || argc != 1u || args[0].type != 3u) return result;
        if (jinx_oracle_string_len(args[0]) == 0u) return result;
        method = hash_dup_string(args[0]);
        if (method == NULL) return result;
        cipher = EVP_get_cipherbyname(method);
        free(method);
        if (handled != NULL) *handled = 1;
        if (cipher == NULL) return jinx_oracle_bool_value(0);
        length = strcmp(name, "openssl_cipher_iv_length") == 0
            ? EVP_CIPHER_iv_length(cipher)
            : EVP_CIPHER_key_length(cipher);
        return length < 0
            ? jinx_oracle_bool_value(0)
            : jinx_oracle_int_value((int64_t)length);
    }

    if (strcmp(name, "openssl_encrypt") == 0 ||
        strcmp(name, "openssl_decrypt") == 0) {
        int supported = 0;
        int encrypting = strcmp(name, "openssl_encrypt") == 0;
        int64_t options = argc >= 4u ? jinx_oracle_intish(args[3]) : 0;
        JinxValue iv = argc >= 5u
            ? args[4]
            : jinx_oracle_string_value("");
        if (args == NULL || argc < 3u ||
            args[0].type != 3u || args[1].type != 3u ||
            args[2].type != 3u || iv.type != 3u) return result;

        /* PHP's AEAD path needs a tag reference on encryption and a tag value
         * on decryption. Leave those modes unhandled until ref semantics are
         * represented end-to-end by the native call bridge. */
        result = hash_openssl_cipher_crypt(
            encrypting,
            args[0],
            args[1],
            args[2],
            options,
            iv,
            &supported
        );
        if (supported && handled != NULL) *handled = 1;
        return result;
    }

    if (strcmp(name, "md5") == 0 || strcmp(name, "sha1") == 0) {
        const EVP_MD *md = strcmp(name, "md5") == 0 ? EVP_md5() : EVP_sha1();
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int binary;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        if (EVP_Digest(
            jinx_oracle_string_bytes(args[0]),
            jinx_oracle_string_len(args[0]),
            digest,
            &digest_len,
            md,
            NULL
        ) != 1) return result;
        binary = argc >= 2u && jinx_oracle_boolish(args[1]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, binary);
    }

    if (strcmp(name, "md5_file") == 0 || strcmp(name, "sha1_file") == 0) {
        const EVP_MD *md = strcmp(name, "md5_file") == 0 ? EVP_md5() : EVP_sha1();
        EVP_MD_CTX *ctx;
        char *path;
        unsigned char digest[EVP_MAX_MD_SIZE];
        unsigned int digest_len = 0u;
        int binary;
        int ok;
        if (args == NULL || argc < 1u || args[0].type != 3u) return result;
        path = hash_dup_string(args[0]);
        if (path == NULL) return result;
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
        binary = argc >= 2u && jinx_oracle_boolish(args[1]);
        if (handled != NULL) *handled = 1;
        return hash_finish_bytes(digest, digest_len, binary);
    }

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
