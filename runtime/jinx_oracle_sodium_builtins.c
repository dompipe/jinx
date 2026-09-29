#include "jinx_oracle_sodium_builtins.h"

#include <limits.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

#ifdef JINX_HAVE_SODIUM
#include <sodium.h>

static int jinx_sodium_ready(void) {
    static int state = 0;
    if (state == 0) state = sodium_init() < 0 ? -1 : 1;
    return state > 0;
}

static int jinx_sodium_string(
    JinxValue value,
    const unsigned char **bytes,
    size_t *len
) {
    if (bytes != NULL) *bytes = NULL;
    if (len != NULL) *len = 0u;
    if (value.type != 3u || value.as.ptr == NULL) return 0;
    if (bytes != NULL) *bytes = jinx_oracle_string_bytes(value);
    if (len != NULL) *len = (size_t)jinx_oracle_string_len(value);
    return 1;
}

static JinxValue jinx_sodium_copy(const unsigned char *bytes, size_t len) {
    char *out;
    if (len > UINT32_MAX || (bytes == NULL && len != 0u)) {
        return jinx_oracle_zero_value();
    }
    out = jinx_oracle_scratch_string((uint32_t)len);
    if (len != 0u) memcpy(out, bytes, len);
    return jinx_oracle_string_value_len(out, (uint32_t)len);
}

static JinxValue jinx_sodium_alloc_result(size_t len, unsigned char **out) {
    char *scratch;
    if (out != NULL) *out = NULL;
    if (len > UINT32_MAX) return jinx_oracle_zero_value();
    scratch = jinx_oracle_scratch_string((uint32_t)len);
    if (out != NULL) *out = (unsigned char *)scratch;
    return jinx_oracle_string_value_len(scratch, (uint32_t)len);
}

static int jinx_sodium_exact_string(
    JinxValue value,
    size_t expected,
    const unsigned char **bytes
) {
    size_t len = 0u;
    return jinx_sodium_string(value, bytes, &len) && len == expected;
}

static JinxValue jinx_sodium_keypair_value(
    const unsigned char *secret,
    size_t secret_len,
    const unsigned char *public_key,
    size_t public_len
) {
    unsigned char *out;
    JinxValue result;
    if (secret_len > SIZE_MAX - public_len) return jinx_oracle_zero_value();
    result = jinx_sodium_alloc_result(secret_len + public_len, &out);
    if (result.type != 3u) return result;
    memcpy(out, secret, secret_len);
    memcpy(out + secret_len, public_key, public_len);
    return result;
}

static int jinx_sodium_box_keypair_parts(
    JinxValue value,
    const unsigned char **secret,
    const unsigned char **public_key
) {
    const unsigned char *bytes;
    size_t len;
    size_t expected = crypto_box_SECRETKEYBYTES + crypto_box_PUBLICKEYBYTES;

    if (secret != NULL) *secret = NULL;
    if (public_key != NULL) *public_key = NULL;
    if (!jinx_sodium_string(value, &bytes, &len) || len != expected) return 0;

    if (secret != NULL) *secret = bytes;
    if (public_key != NULL) *public_key = bytes + crypto_box_SECRETKEYBYTES;
    return 1;
}

typedef int (*JinxSodiumAeadEncryptFn)(
    unsigned char *,
    unsigned long long *,
    const unsigned char *,
    unsigned long long,
    const unsigned char *,
    unsigned long long,
    const unsigned char *,
    const unsigned char *,
    const unsigned char *
);

typedef int (*JinxSodiumAeadDecryptFn)(
    unsigned char *,
    unsigned long long *,
    unsigned char *,
    const unsigned char *,
    unsigned long long,
    const unsigned char *,
    unsigned long long,
    const unsigned char *,
    const unsigned char *
);

static JinxValue jinx_sodium_aead_encrypt(
    JinxValue *args,
    size_t argc,
    size_t nonce_bytes,
    size_t key_bytes,
    size_t auth_bytes,
    JinxSodiumAeadEncryptFn encrypt_fn,
    int *ok
) {
    const unsigned char *message;
    const unsigned char *aad;
    const unsigned char *nonce;
    const unsigned char *key;
    size_t message_len;
    size_t aad_len;
    unsigned long long ciphertext_len = 0u;
    unsigned char *out;
    JinxValue value = jinx_oracle_zero_value();

    if (ok != NULL) *ok = 0;
    if (args == NULL || argc != 4u ||
        encrypt_fn == NULL ||
        !jinx_sodium_string(args[0], &message, &message_len) ||
        !jinx_sodium_string(args[1], &aad, &aad_len) ||
        !jinx_sodium_exact_string(args[2], nonce_bytes, &nonce) ||
        !jinx_sodium_exact_string(args[3], key_bytes, &key) ||
        message_len > UINT32_MAX - auth_bytes) {
        return value;
    }

    value = jinx_sodium_alloc_result(message_len + auth_bytes, &out);
    if (value.type != 3u ||
        encrypt_fn(
            out,
            &ciphertext_len,
            message,
            (unsigned long long)message_len,
            aad,
            (unsigned long long)aad_len,
            NULL,
            nonce,
            key
        ) != 0 ||
        ciphertext_len != (unsigned long long)(message_len + auth_bytes)) {
        return jinx_oracle_zero_value();
    }

    if (ok != NULL) *ok = 1;
    return value;
}

static JinxValue jinx_sodium_aead_decrypt(
    JinxValue *args,
    size_t argc,
    size_t nonce_bytes,
    size_t key_bytes,
    size_t auth_bytes,
    JinxSodiumAeadDecryptFn decrypt_fn,
    int *ok
) {
    const unsigned char *ciphertext;
    const unsigned char *aad;
    const unsigned char *nonce;
    const unsigned char *key;
    size_t ciphertext_len;
    size_t aad_len;
    size_t message_capacity;
    unsigned long long message_len = 0u;
    unsigned char *out;
    JinxValue value = jinx_oracle_zero_value();

    if (ok != NULL) *ok = 0;
    if (args == NULL || argc != 4u ||
        decrypt_fn == NULL ||
        !jinx_sodium_string(args[0], &ciphertext, &ciphertext_len) ||
        ciphertext_len < auth_bytes ||
        !jinx_sodium_string(args[1], &aad, &aad_len) ||
        !jinx_sodium_exact_string(args[2], nonce_bytes, &nonce) ||
        !jinx_sodium_exact_string(args[3], key_bytes, &key)) {
        return value;
    }

    message_capacity = ciphertext_len - auth_bytes;
    value = jinx_sodium_alloc_result(message_capacity, &out);
    if (value.type != 3u) return jinx_oracle_zero_value();

    if (decrypt_fn(
            out,
            &message_len,
            NULL,
            ciphertext,
            (unsigned long long)ciphertext_len,
            aad,
            (unsigned long long)aad_len,
            nonce,
            key
        ) != 0) {
        if (ok != NULL) *ok = 1;
        return jinx_oracle_bool_value(0);
    }

    if (message_len > UINT32_MAX || message_len > message_capacity) {
        return jinx_oracle_zero_value();
    }
    value.flags = (uint32_t)message_len;
    if (ok != NULL) *ok = 1;
    return value;
}

JinxValue jinx_oracle_sodium_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
) {
    JinxValue result = jinx_oracle_zero_value();

    if (handled != NULL) *handled = 0;
    if (name == NULL || !jinx_sodium_ready()) return result;

    if (strcmp(name, "sodium_bin2hex") == 0) {
        const unsigned char *input;
        size_t input_len;
        size_t out_len;
        char *out;

        if (args == NULL || argc != 1u ||
            !jinx_sodium_string(args[0], &input, &input_len) ||
            input_len > (SIZE_MAX - 1u) / 2u) return result;

        out_len = input_len * 2u;
        if (out_len > UINT32_MAX) return result;
        out = jinx_oracle_scratch_string((uint32_t)out_len);
        if (sodium_bin2hex(out, out_len + 1u, input, input_len) == NULL) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value_len(out, (uint32_t)out_len);
    }

    if (strcmp(name, "sodium_hex2bin") == 0) {
        const unsigned char *hex;
        const unsigned char *ignore = (const unsigned char *)"";
        size_t hex_len;
        size_t ignore_len = 0u;
        char *ignore_owned = NULL;
        unsigned char *out;
        size_t out_len = 0u;
        JinxValue value;

        if (args == NULL || argc < 1u || argc > 2u ||
            !jinx_sodium_string(args[0], &hex, &hex_len)) return result;

        if (argc == 2u) {
            const unsigned char *ignore_bytes;
            if (!jinx_sodium_string(args[1], &ignore_bytes, &ignore_len)) return result;
            ignore_owned = (char *)malloc(ignore_len + 1u);
            if (ignore_owned == NULL) return result;
            if (ignore_len != 0u) memcpy(ignore_owned, ignore_bytes, ignore_len);
            ignore_owned[ignore_len] = '\0';
            ignore = (const unsigned char *)ignore_owned;
        }

        value = jinx_sodium_alloc_result(hex_len / 2u + 1u, &out);
        if (value.type != 3u) {
            free(ignore_owned);
            return result;
        }
        if (sodium_hex2bin(
                out,
                hex_len / 2u + 1u,
                (const char *)hex,
                hex_len,
                (const char *)ignore,
                &out_len,
                NULL
            ) != 0 || out_len > UINT32_MAX) {
            free(ignore_owned);
            return result;
        }
        free(ignore_owned);
        if (handled != NULL) *handled = 1;
        value.flags = (uint32_t)out_len;
        return value;
    }

    if (strcmp(name, "sodium_bin2base64") == 0) {
        const unsigned char *input;
        size_t input_len;
        int variant;
        size_t buffer_len;
        size_t text_len;
        char *out;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &input, &input_len)) return result;
        variant = (int)jinx_oracle_intish(args[1]);
        buffer_len = sodium_base64_encoded_len(input_len, variant);
        if (buffer_len == 0u || buffer_len - 1u > UINT32_MAX) return result;
        out = jinx_oracle_scratch_string((uint32_t)(buffer_len - 1u));
        if (sodium_bin2base64(
                out, buffer_len, input, input_len, variant
            ) == NULL) return result;
        text_len = strlen(out);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_string_value_len(out, (uint32_t)text_len);
    }

    if (strcmp(name, "sodium_base642bin") == 0) {
        const unsigned char *input;
        const unsigned char *ignore = (const unsigned char *)"";
        size_t input_len;
        size_t ignore_len = 0u;
        char *ignore_owned = NULL;
        int variant;
        unsigned char *out;
        size_t out_len = 0u;
        JinxValue value;

        if (args == NULL || argc < 2u || argc > 3u ||
            !jinx_sodium_string(args[0], &input, &input_len)) return result;
        variant = (int)jinx_oracle_intish(args[1]);

        if (argc == 3u) {
            const unsigned char *ignore_bytes;
            if (!jinx_sodium_string(args[2], &ignore_bytes, &ignore_len)) return result;
            ignore_owned = (char *)malloc(ignore_len + 1u);
            if (ignore_owned == NULL) return result;
            if (ignore_len != 0u) memcpy(ignore_owned, ignore_bytes, ignore_len);
            ignore_owned[ignore_len] = '\0';
            ignore = (const unsigned char *)ignore_owned;
        }

        value = jinx_sodium_alloc_result(input_len + 1u, &out);
        if (value.type != 3u) {
            free(ignore_owned);
            return result;
        }
        if (sodium_base642bin(
                out,
                input_len + 1u,
                (const char *)input,
                input_len,
                (const char *)ignore,
                &out_len,
                NULL,
                variant
            ) != 0 || out_len > UINT32_MAX) {
            free(ignore_owned);
            return result;
        }
        free(ignore_owned);
        if (handled != NULL) *handled = 1;
        value.flags = (uint32_t)out_len;
        return value;
    }

    if (strcmp(name, "sodium_compare") == 0 ||
        strcmp(name, "sodium_memcmp") == 0) {
        const unsigned char *left;
        const unsigned char *right;
        size_t left_len;
        size_t right_len;
        int comparison;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &left, &left_len) ||
            !jinx_sodium_string(args[1], &right, &right_len) ||
            left_len != right_len) return result;

        comparison = strcmp(name, "sodium_compare") == 0
            ? sodium_compare(left, right, left_len)
            : sodium_memcmp(left, right, left_len);
        if (handled != NULL) *handled = 1;
        return jinx_oracle_int_value((int64_t)comparison);
    }

    if (strcmp(name, "sodium_pad") == 0) {
        const unsigned char *input;
        size_t input_len;
        int64_t block_i;
        size_t block;
        size_t max_len;
        size_t padded_len = 0u;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &input, &input_len)) return result;
        block_i = jinx_oracle_intish(args[1]);
        if (block_i <= 0) return result;
        block = (size_t)block_i;
        if (input_len > SIZE_MAX - block) return result;
        max_len = input_len + block;

        value = jinx_sodium_alloc_result(max_len, &out);
        if (value.type != 3u) return result;
        if (input_len != 0u) memcpy(out, input, input_len);
        if (sodium_pad(
                &padded_len,
                out,
                input_len,
                block,
                max_len
            ) != 0 || padded_len > UINT32_MAX) return result;

        value.flags = (uint32_t)padded_len;
        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_unpad") == 0) {
        const unsigned char *input;
        size_t input_len;
        int64_t block_i;
        size_t unpadded_len = 0u;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &input, &input_len)) return result;
        block_i = jinx_oracle_intish(args[1]);
        if (block_i <= 0 ||
            sodium_unpad(
                &unpadded_len,
                input,
                input_len,
                (size_t)block_i
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(input, unpadded_len);
    }

    if (strcmp(name, "sodium_crypto_auth_keygen") == 0) {
        unsigned char key[crypto_auth_KEYBYTES];
        if (argc != 0u) return result;
        crypto_auth_keygen(key);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_auth") == 0) {
        const unsigned char *message;
        const unsigned char *key;
        size_t message_len;
        unsigned char mac[crypto_auth_BYTES];

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_auth_KEYBYTES, &key)) {
            return result;
        }

        if (crypto_auth(mac, message, (unsigned long long)message_len, key) != 0) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(mac, sizeof(mac));
    }

    if (strcmp(name, "sodium_crypto_auth_verify") == 0) {
        const unsigned char *mac;
        const unsigned char *message;
        const unsigned char *key;
        size_t message_len;
        int verified;

        if (args == NULL || argc != 3u ||
            !jinx_sodium_exact_string(args[0], crypto_auth_BYTES, &mac) ||
            !jinx_sodium_string(args[1], &message, &message_len) ||
            !jinx_sodium_exact_string(args[2], crypto_auth_KEYBYTES, &key)) {
            return result;
        }

        verified = crypto_auth_verify(
            mac, message, (unsigned long long)message_len, key
        ) == 0;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(verified);
    }

    if (strcmp(name, "sodium_crypto_generichash_keygen") == 0) {
        unsigned char key[crypto_generichash_KEYBYTES];
        if (argc != 0u) return result;
        crypto_generichash_keygen(key);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_generichash") == 0) {
        const unsigned char *message;
        const unsigned char *key = NULL;
        size_t message_len;
        size_t key_len = 0u;
        size_t out_len = crypto_generichash_BYTES;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc < 1u || argc > 3u ||
            !jinx_sodium_string(args[0], &message, &message_len)) return result;

        if (argc >= 2u) {
            if (!jinx_sodium_string(args[1], &key, &key_len)) return result;
            if (key_len != 0u &&
                (key_len < crypto_generichash_KEYBYTES_MIN ||
                 key_len > crypto_generichash_KEYBYTES_MAX)) return result;
        }
        if (argc >= 3u) {
            int64_t requested = jinx_oracle_intish(args[2]);
            if (requested < crypto_generichash_BYTES_MIN ||
                requested > crypto_generichash_BYTES_MAX) return result;
            out_len = (size_t)requested;
        }

        value = jinx_sodium_alloc_result(out_len, &out);
        if (value.type != 3u) return result;
        if (crypto_generichash(
                out,
                out_len,
                message,
                (unsigned long long)message_len,
                key_len == 0u ? NULL : key,
                key_len
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_aead_chacha20poly1305_keygen") == 0) {
        unsigned char key[crypto_aead_chacha20poly1305_KEYBYTES];
        if (argc != 0u) return result;
        randombytes_buf(key, sizeof(key));
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_aead_chacha20poly1305_encrypt") == 0 ||
        strcmp(name, "sodium_crypto_aead_chacha20poly1305_decrypt") == 0) {
        int aead_ok = 0;
        JinxValue value =
            strcmp(name, "sodium_crypto_aead_chacha20poly1305_encrypt") == 0
            ? jinx_sodium_aead_encrypt(
                args,
                argc,
                crypto_aead_chacha20poly1305_NPUBBYTES,
                crypto_aead_chacha20poly1305_KEYBYTES,
                crypto_aead_chacha20poly1305_ABYTES,
                crypto_aead_chacha20poly1305_encrypt,
                &aead_ok
            )
            : jinx_sodium_aead_decrypt(
                args,
                argc,
                crypto_aead_chacha20poly1305_NPUBBYTES,
                crypto_aead_chacha20poly1305_KEYBYTES,
                crypto_aead_chacha20poly1305_ABYTES,
                crypto_aead_chacha20poly1305_decrypt,
                &aead_ok
            );
        if (!aead_ok) return result;
        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_aead_chacha20poly1305_ietf_keygen") == 0) {
        unsigned char key[crypto_aead_chacha20poly1305_ietf_KEYBYTES];
        if (argc != 0u) return result;
        randombytes_buf(key, sizeof(key));
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_aead_chacha20poly1305_ietf_encrypt") == 0 ||
        strcmp(name, "sodium_crypto_aead_chacha20poly1305_ietf_decrypt") == 0) {
        int aead_ok = 0;
        JinxValue value =
            strcmp(name, "sodium_crypto_aead_chacha20poly1305_ietf_encrypt") == 0
            ? jinx_sodium_aead_encrypt(
                args,
                argc,
                crypto_aead_chacha20poly1305_ietf_NPUBBYTES,
                crypto_aead_chacha20poly1305_ietf_KEYBYTES,
                crypto_aead_chacha20poly1305_ietf_ABYTES,
                crypto_aead_chacha20poly1305_ietf_encrypt,
                &aead_ok
            )
            : jinx_sodium_aead_decrypt(
                args,
                argc,
                crypto_aead_chacha20poly1305_ietf_NPUBBYTES,
                crypto_aead_chacha20poly1305_ietf_KEYBYTES,
                crypto_aead_chacha20poly1305_ietf_ABYTES,
                crypto_aead_chacha20poly1305_ietf_decrypt,
                &aead_ok
            );
        if (!aead_ok) return result;
        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_aead_xchacha20poly1305_ietf_keygen") == 0) {
        unsigned char key[crypto_aead_xchacha20poly1305_ietf_KEYBYTES];
        if (argc != 0u) return result;
        randombytes_buf(key, sizeof(key));
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") == 0 ||
        strcmp(name, "sodium_crypto_aead_xchacha20poly1305_ietf_decrypt") == 0) {
        int aead_ok = 0;
        JinxValue value =
            strcmp(name, "sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") == 0
            ? jinx_sodium_aead_encrypt(
                args,
                argc,
                crypto_aead_xchacha20poly1305_ietf_NPUBBYTES,
                crypto_aead_xchacha20poly1305_ietf_KEYBYTES,
                crypto_aead_xchacha20poly1305_ietf_ABYTES,
                crypto_aead_xchacha20poly1305_ietf_encrypt,
                &aead_ok
            )
            : jinx_sodium_aead_decrypt(
                args,
                argc,
                crypto_aead_xchacha20poly1305_ietf_NPUBBYTES,
                crypto_aead_xchacha20poly1305_ietf_KEYBYTES,
                crypto_aead_xchacha20poly1305_ietf_ABYTES,
                crypto_aead_xchacha20poly1305_ietf_decrypt,
                &aead_ok
            );
        if (!aead_ok) return result;
        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_aead_aes256gcm_is_available") == 0) {
        if (argc != 0u) return result;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(
            crypto_aead_aes256gcm_is_available() != 0
        );
    }

    if (strcmp(name, "sodium_crypto_aead_aes256gcm_keygen") == 0) {
        unsigned char key[crypto_aead_aes256gcm_KEYBYTES];
        if (argc != 0u) return result;
        randombytes_buf(key, sizeof(key));
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_aead_aes256gcm_encrypt") == 0 ||
        strcmp(name, "sodium_crypto_aead_aes256gcm_decrypt") == 0) {
        int aead_ok = 0;
        JinxValue value;
        if (crypto_aead_aes256gcm_is_available() == 0) return result;
        value = strcmp(name, "sodium_crypto_aead_aes256gcm_encrypt") == 0
            ? jinx_sodium_aead_encrypt(
                args,
                argc,
                crypto_aead_aes256gcm_NPUBBYTES,
                crypto_aead_aes256gcm_KEYBYTES,
                crypto_aead_aes256gcm_ABYTES,
                crypto_aead_aes256gcm_encrypt,
                &aead_ok
            )
            : jinx_sodium_aead_decrypt(
                args,
                argc,
                crypto_aead_aes256gcm_NPUBBYTES,
                crypto_aead_aes256gcm_KEYBYTES,
                crypto_aead_aes256gcm_ABYTES,
                crypto_aead_aes256gcm_decrypt,
                &aead_ok
            );
        if (!aead_ok) return result;
        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_stream_keygen") == 0) {
        unsigned char key[crypto_stream_KEYBYTES];
        if (argc != 0u) return result;
        randombytes_buf(key, sizeof(key));
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_stream") == 0) {
        int64_t requested;
        const unsigned char *nonce;
        const unsigned char *key;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 3u) return result;
        requested = jinx_oracle_intish(args[0]);
        if (requested < 0 || (uint64_t)requested > UINT32_MAX ||
            !jinx_sodium_exact_string(args[1], crypto_stream_NONCEBYTES, &nonce) ||
            !jinx_sodium_exact_string(args[2], crypto_stream_KEYBYTES, &key)) {
            return result;
        }

        value = jinx_sodium_alloc_result((size_t)requested, &out);
        if (value.type != 3u ||
            crypto_stream(
                out,
                (unsigned long long)requested,
                nonce,
                key
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_stream_xor") == 0) {
        const unsigned char *message;
        const unsigned char *nonce;
        const unsigned char *key;
        size_t message_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 3u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_stream_NONCEBYTES, &nonce) ||
            !jinx_sodium_exact_string(args[2], crypto_stream_KEYBYTES, &key)) {
            return result;
        }

        value = jinx_sodium_alloc_result(message_len, &out);
        if (value.type != 3u ||
            crypto_stream_xor(
                out,
                message,
                (unsigned long long)message_len,
                nonce,
                key
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return value;
    }

#ifdef crypto_stream_xchacha20_KEYBYTES
    if (strcmp(name, "sodium_crypto_stream_xchacha20_keygen") == 0) {
        unsigned char key[crypto_stream_xchacha20_KEYBYTES];
        if (argc != 0u) return result;
        randombytes_buf(key, sizeof(key));
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_stream_xchacha20") == 0) {
        int64_t requested;
        const unsigned char *nonce;
        const unsigned char *key;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 3u) return result;
        requested = jinx_oracle_intish(args[0]);
        if (requested < 0 || (uint64_t)requested > UINT32_MAX ||
            !jinx_sodium_exact_string(
                args[1], crypto_stream_xchacha20_NONCEBYTES, &nonce
            ) ||
            !jinx_sodium_exact_string(
                args[2], crypto_stream_xchacha20_KEYBYTES, &key
            )) {
            return result;
        }

        value = jinx_sodium_alloc_result((size_t)requested, &out);
        if (value.type != 3u ||
            crypto_stream_xchacha20(
                out,
                (unsigned long long)requested,
                nonce,
                key
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_stream_xchacha20_xor") == 0 ||
        strcmp(name, "sodium_crypto_stream_xchacha20_xor_ic") == 0) {
        const unsigned char *message;
        const unsigned char *nonce;
        const unsigned char *key;
        size_t message_len;
        uint64_t counter = 0u;
        unsigned char *out;
        JinxValue value;
        int with_counter =
            strcmp(name, "sodium_crypto_stream_xchacha20_xor_ic") == 0;

        if (args == NULL ||
            argc != (with_counter ? 4u : 3u) ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(
                args[1], crypto_stream_xchacha20_NONCEBYTES, &nonce
            )) {
            return result;
        }

        if (with_counter) {
            int64_t counter_i = jinx_oracle_intish(args[2]);
            if (counter_i < 0 ||
                !jinx_sodium_exact_string(
                    args[3], crypto_stream_xchacha20_KEYBYTES, &key
                )) {
                return result;
            }
            counter = (uint64_t)counter_i;
        } else if (!jinx_sodium_exact_string(
                args[2], crypto_stream_xchacha20_KEYBYTES, &key
            )) {
            return result;
        }

        value = jinx_sodium_alloc_result(message_len, &out);
        if (value.type != 3u) return result;

        if (with_counter) {
            if (crypto_stream_xchacha20_xor_ic(
                    out,
                    message,
                    (unsigned long long)message_len,
                    nonce,
                    counter,
                    key
                ) != 0) return result;
        } else if (crypto_stream_xchacha20_xor(
                out,
                message,
                (unsigned long long)message_len,
                nonce,
                key
            ) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return value;
    }
#endif

    if (strcmp(name, "sodium_crypto_secretbox_keygen") == 0) {
        unsigned char key[crypto_secretbox_KEYBYTES];
        if (argc != 0u) return result;
        crypto_secretbox_keygen(key);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_secretbox") == 0) {
        const unsigned char *message;
        const unsigned char *nonce;
        const unsigned char *key;
        size_t message_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 3u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_secretbox_NONCEBYTES, &nonce) ||
            !jinx_sodium_exact_string(args[2], crypto_secretbox_KEYBYTES, &key) ||
            message_len > UINT32_MAX - crypto_secretbox_MACBYTES) return result;

        value = jinx_sodium_alloc_result(
            message_len + crypto_secretbox_MACBYTES, &out
        );
        if (value.type != 3u) return result;
        if (crypto_secretbox_easy(
                out, message, (unsigned long long)message_len, nonce, key
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_secretbox_open") == 0) {
        const unsigned char *ciphertext;
        const unsigned char *nonce;
        const unsigned char *key;
        size_t ciphertext_len;
        size_t message_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 3u ||
            !jinx_sodium_string(args[0], &ciphertext, &ciphertext_len) ||
            ciphertext_len < crypto_secretbox_MACBYTES ||
            !jinx_sodium_exact_string(args[1], crypto_secretbox_NONCEBYTES, &nonce) ||
            !jinx_sodium_exact_string(args[2], crypto_secretbox_KEYBYTES, &key)) {
            return result;
        }

        message_len = ciphertext_len - crypto_secretbox_MACBYTES;
        value = jinx_sodium_alloc_result(message_len, &out);
        if (value.type != 3u) return result;
        if (crypto_secretbox_open_easy(
                out,
                ciphertext,
                (unsigned long long)ciphertext_len,
                nonce,
                key
            ) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_shorthash_keygen") == 0) {
        unsigned char key[crypto_shorthash_KEYBYTES];
        if (argc != 0u) return result;
        crypto_shorthash_keygen(key);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_shorthash") == 0) {
        const unsigned char *message;
        const unsigned char *key;
        size_t message_len;
        unsigned char out[crypto_shorthash_BYTES];

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_shorthash_KEYBYTES, &key)) {
            return result;
        }
        if (crypto_shorthash(
                out, message, (unsigned long long)message_len, key
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_box_keypair") == 0) {
        unsigned char pk[crypto_box_PUBLICKEYBYTES];
        unsigned char sk[crypto_box_SECRETKEYBYTES];
        if (argc != 0u || crypto_box_keypair(pk, sk) != 0) return result;
        if (handled != NULL) *handled = 1;
        return jinx_sodium_keypair_value(sk, sizeof(sk), pk, sizeof(pk));
    }

    if (strcmp(name, "sodium_crypto_box_seed_keypair") == 0) {
        const unsigned char *seed;
        unsigned char pk[crypto_box_PUBLICKEYBYTES];
        unsigned char sk[crypto_box_SECRETKEYBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(args[0], crypto_box_SEEDBYTES, &seed) ||
            crypto_box_seed_keypair(pk, sk, seed) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_keypair_value(sk, sizeof(sk), pk, sizeof(pk));
    }

    if (strcmp(name, "sodium_crypto_box_keypair_from_secretkey_and_publickey") == 0) {
        const unsigned char *sk;
        const unsigned char *pk;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_exact_string(args[0], crypto_box_SECRETKEYBYTES, &sk) ||
            !jinx_sodium_exact_string(args[1], crypto_box_PUBLICKEYBYTES, &pk)) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_keypair_value(
            sk, crypto_box_SECRETKEYBYTES,
            pk, crypto_box_PUBLICKEYBYTES
        );
    }

    if (strcmp(name, "sodium_crypto_box_secretkey") == 0 ||
        strcmp(name, "sodium_crypto_box_publickey") == 0) {
        const unsigned char *sk;
        const unsigned char *pk;
        if (args == NULL || argc != 1u ||
            !jinx_sodium_box_keypair_parts(args[0], &sk, &pk)) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return strcmp(name, "sodium_crypto_box_secretkey") == 0
            ? jinx_sodium_copy(sk, crypto_box_SECRETKEYBYTES)
            : jinx_sodium_copy(pk, crypto_box_PUBLICKEYBYTES);
    }

    if (strcmp(name, "sodium_crypto_box_publickey_from_secretkey") == 0) {
        const unsigned char *sk;
        unsigned char pk[crypto_box_PUBLICKEYBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(args[0], crypto_box_SECRETKEYBYTES, &sk) ||
            crypto_scalarmult_base(pk, sk) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(pk, sizeof(pk));
    }

    if (strcmp(name, "sodium_crypto_box") == 0) {
        const unsigned char *message;
        const unsigned char *nonce;
        const unsigned char *sk;
        const unsigned char *pk;
        size_t message_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 3u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_box_NONCEBYTES, &nonce) ||
            !jinx_sodium_box_keypair_parts(args[2], &sk, &pk) ||
            message_len > UINT32_MAX - crypto_box_MACBYTES) {
            return result;
        }

        value = jinx_sodium_alloc_result(
            message_len + crypto_box_MACBYTES, &out
        );
        if (value.type != 3u ||
            crypto_box_easy(
                out,
                message,
                (unsigned long long)message_len,
                nonce,
                pk,
                sk
            ) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_box_open") == 0) {
        const unsigned char *ciphertext;
        const unsigned char *nonce;
        const unsigned char *sk;
        const unsigned char *pk;
        size_t ciphertext_len;
        size_t message_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 3u ||
            !jinx_sodium_string(args[0], &ciphertext, &ciphertext_len) ||
            ciphertext_len < crypto_box_MACBYTES ||
            !jinx_sodium_exact_string(args[1], crypto_box_NONCEBYTES, &nonce) ||
            !jinx_sodium_box_keypair_parts(args[2], &sk, &pk)) {
            return result;
        }

        message_len = ciphertext_len - crypto_box_MACBYTES;
        value = jinx_sodium_alloc_result(message_len, &out);
        if (value.type != 3u) return result;

        if (crypto_box_open_easy(
                out,
                ciphertext,
                (unsigned long long)ciphertext_len,
                nonce,
                pk,
                sk
            ) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_box_seal") == 0) {
        const unsigned char *message;
        const unsigned char *pk;
        size_t message_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_box_PUBLICKEYBYTES, &pk) ||
            message_len > UINT32_MAX - crypto_box_SEALBYTES) {
            return result;
        }

        value = jinx_sodium_alloc_result(
            message_len + crypto_box_SEALBYTES, &out
        );
        if (value.type != 3u ||
            crypto_box_seal(
                out,
                message,
                (unsigned long long)message_len,
                pk
            ) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_box_seal_open") == 0) {
        const unsigned char *ciphertext;
        const unsigned char *sk;
        const unsigned char *pk;
        size_t ciphertext_len;
        size_t message_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &ciphertext, &ciphertext_len) ||
            ciphertext_len < crypto_box_SEALBYTES ||
            !jinx_sodium_box_keypair_parts(args[1], &sk, &pk)) {
            return result;
        }

        message_len = ciphertext_len - crypto_box_SEALBYTES;
        value = jinx_sodium_alloc_result(message_len, &out);
        if (value.type != 3u) return result;

        if (crypto_box_seal_open(
                out,
                ciphertext,
                (unsigned long long)ciphertext_len,
                pk,
                sk
            ) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_add") == 0 ||
        strcmp(name, "sodium_crypto_core_ristretto255_sub") == 0) {
        const unsigned char *left;
        const unsigned char *right;
        unsigned char out[crypto_core_ristretto255_BYTES];
        int rc;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_exact_string(args[0], crypto_core_ristretto255_BYTES, &left) ||
            !jinx_sodium_exact_string(args[1], crypto_core_ristretto255_BYTES, &right)) {
            return result;
        }

        rc = strcmp(name, "sodium_crypto_core_ristretto255_add") == 0
            ? crypto_core_ristretto255_add(out, left, right)
            : crypto_core_ristretto255_sub(out, left, right);
        if (rc != 0) return result;
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_from_hash") == 0) {
        const unsigned char *hash;
        unsigned char out[crypto_core_ristretto255_BYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(
                args[0], crypto_core_ristretto255_HASHBYTES, &hash
            ) ||
            crypto_core_ristretto255_from_hash(out, hash) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_is_valid_point") == 0) {
        const unsigned char *point;
        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(
                args[0], crypto_core_ristretto255_BYTES, &point
            )) {
            return result;
        }
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(
            crypto_core_ristretto255_is_valid_point(point) == 1
        );
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_random") == 0) {
        unsigned char out[crypto_core_ristretto255_BYTES];
        if (argc != 0u) return result;
        crypto_core_ristretto255_random(out);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_add") == 0 ||
        strcmp(name, "sodium_crypto_core_ristretto255_scalar_sub") == 0 ||
        strcmp(name, "sodium_crypto_core_ristretto255_scalar_mul") == 0) {
        const unsigned char *left;
        const unsigned char *right;
        unsigned char out[crypto_core_ristretto255_SCALARBYTES];

        if (args == NULL || argc != 2u ||
            !jinx_sodium_exact_string(
                args[0], crypto_core_ristretto255_SCALARBYTES, &left
            ) ||
            !jinx_sodium_exact_string(
                args[1], crypto_core_ristretto255_SCALARBYTES, &right
            )) {
            return result;
        }

        if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_add") == 0) {
            crypto_core_ristretto255_scalar_add(out, left, right);
        } else if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_sub") == 0) {
            crypto_core_ristretto255_scalar_sub(out, left, right);
        } else {
            crypto_core_ristretto255_scalar_mul(out, left, right);
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_complement") == 0 ||
        strcmp(name, "sodium_crypto_core_ristretto255_scalar_negate") == 0) {
        const unsigned char *scalar;
        unsigned char out[crypto_core_ristretto255_SCALARBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(
                args[0], crypto_core_ristretto255_SCALARBYTES, &scalar
            )) {
            return result;
        }

        if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_complement") == 0) {
            crypto_core_ristretto255_scalar_complement(out, scalar);
        } else {
            crypto_core_ristretto255_scalar_negate(out, scalar);
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_invert") == 0) {
        const unsigned char *scalar;
        unsigned char out[crypto_core_ristretto255_SCALARBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(
                args[0], crypto_core_ristretto255_SCALARBYTES, &scalar
            ) ||
            crypto_core_ristretto255_scalar_invert(out, scalar) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_random") == 0) {
        unsigned char out[crypto_core_ristretto255_SCALARBYTES];
        if (argc != 0u) return result;
        crypto_core_ristretto255_scalar_random(out);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_core_ristretto255_scalar_reduce") == 0) {
        const unsigned char *wide;
        unsigned char out[crypto_core_ristretto255_SCALARBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(
                args[0], crypto_core_ristretto255_NONREDUCEDSCALARBYTES, &wide
            )) {
            return result;
        }

        crypto_core_ristretto255_scalar_reduce(out, wide);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_scalarmult_ristretto255") == 0) {
        const unsigned char *scalar;
        const unsigned char *point;
        unsigned char out[crypto_scalarmult_ristretto255_BYTES];

        if (args == NULL || argc != 2u ||
            !jinx_sodium_exact_string(
                args[0], crypto_scalarmult_ristretto255_SCALARBYTES, &scalar
            ) ||
            !jinx_sodium_exact_string(
                args[1], crypto_scalarmult_ristretto255_BYTES, &point
            )) {
            return result;
        }

        if (crypto_scalarmult_ristretto255(out, scalar, point) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_scalarmult_ristretto255_base") == 0) {
        const unsigned char *scalar;
        unsigned char out[crypto_scalarmult_ristretto255_BYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(
                args[0], crypto_scalarmult_ristretto255_SCALARBYTES, &scalar
            ) ||
            crypto_scalarmult_ristretto255_base(out, scalar) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_sign_ed25519_pk_to_curve25519") == 0) {
        const unsigned char *pk;
        unsigned char out[crypto_box_PUBLICKEYBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(args[0], crypto_sign_PUBLICKEYBYTES, &pk) ||
            crypto_sign_ed25519_pk_to_curve25519(out, pk) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_sign_ed25519_sk_to_curve25519") == 0) {
        const unsigned char *sk;
        unsigned char out[crypto_box_SECRETKEYBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(args[0], crypto_sign_SECRETKEYBYTES, &sk) ||
            crypto_sign_ed25519_sk_to_curve25519(out, sk) != 0) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_sign_keypair") == 0) {
        unsigned char pk[crypto_sign_PUBLICKEYBYTES];
        unsigned char sk[crypto_sign_SECRETKEYBYTES];
        if (argc != 0u || crypto_sign_keypair(pk, sk) != 0) return result;
        if (handled != NULL) *handled = 1;
        return jinx_sodium_keypair_value(sk, sizeof(sk), pk, sizeof(pk));
    }

    if (strcmp(name, "sodium_crypto_sign_seed_keypair") == 0) {
        const unsigned char *seed;
        unsigned char pk[crypto_sign_PUBLICKEYBYTES];
        unsigned char sk[crypto_sign_SECRETKEYBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(args[0], crypto_sign_SEEDBYTES, &seed) ||
            crypto_sign_seed_keypair(pk, sk, seed) != 0) return result;

        if (handled != NULL) *handled = 1;
        return jinx_sodium_keypair_value(sk, sizeof(sk), pk, sizeof(pk));
    }

    if (strcmp(name, "sodium_crypto_sign_keypair_from_secretkey_and_publickey") == 0) {
        const unsigned char *sk;
        const unsigned char *pk;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_exact_string(args[0], crypto_sign_SECRETKEYBYTES, &sk) ||
            !jinx_sodium_exact_string(args[1], crypto_sign_PUBLICKEYBYTES, &pk)) {
            return result;
        }

        if (handled != NULL) *handled = 1;
        return jinx_sodium_keypair_value(
            sk, crypto_sign_SECRETKEYBYTES,
            pk, crypto_sign_PUBLICKEYBYTES
        );
    }

    if (strcmp(name, "sodium_crypto_sign_secretkey") == 0 ||
        strcmp(name, "sodium_crypto_sign_publickey") == 0) {
        const unsigned char *keypair;
        size_t keypair_len;
        size_t expected = crypto_sign_SECRETKEYBYTES + crypto_sign_PUBLICKEYBYTES;

        if (args == NULL || argc != 1u ||
            !jinx_sodium_string(args[0], &keypair, &keypair_len) ||
            keypair_len != expected) return result;

        if (handled != NULL) *handled = 1;
        if (strcmp(name, "sodium_crypto_sign_secretkey") == 0) {
            return jinx_sodium_copy(keypair, crypto_sign_SECRETKEYBYTES);
        }
        return jinx_sodium_copy(
            keypair + crypto_sign_SECRETKEYBYTES,
            crypto_sign_PUBLICKEYBYTES
        );
    }

    if (strcmp(name, "sodium_crypto_sign_publickey_from_secretkey") == 0) {
        const unsigned char *sk;
        unsigned char pk[crypto_sign_PUBLICKEYBYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(args[0], crypto_sign_SECRETKEYBYTES, &sk) ||
            crypto_sign_ed25519_sk_to_pk(pk, sk) != 0) return result;

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(pk, sizeof(pk));
    }

    if (strcmp(name, "sodium_crypto_sign_detached") == 0) {
        const unsigned char *message;
        const unsigned char *sk;
        size_t message_len;
        unsigned char signature[crypto_sign_BYTES];
        unsigned long long signature_len = 0u;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_sign_SECRETKEYBYTES, &sk) ||
            crypto_sign_detached(
                signature,
                &signature_len,
                message,
                (unsigned long long)message_len,
                sk
            ) != 0 ||
            signature_len != crypto_sign_BYTES) return result;

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(signature, (size_t)signature_len);
    }

    if (strcmp(name, "sodium_crypto_sign_verify_detached") == 0) {
        const unsigned char *signature;
        const unsigned char *message;
        const unsigned char *pk;
        size_t message_len;
        int verified;

        if (args == NULL || argc != 3u ||
            !jinx_sodium_exact_string(args[0], crypto_sign_BYTES, &signature) ||
            !jinx_sodium_string(args[1], &message, &message_len) ||
            !jinx_sodium_exact_string(args[2], crypto_sign_PUBLICKEYBYTES, &pk)) {
            return result;
        }

        verified = crypto_sign_verify_detached(
            signature, message, (unsigned long long)message_len, pk
        ) == 0;
        if (handled != NULL) *handled = 1;
        return jinx_oracle_bool_value(verified);
    }

    if (strcmp(name, "sodium_crypto_sign") == 0) {
        const unsigned char *message;
        const unsigned char *sk;
        size_t message_len;
        unsigned char *signed_message;
        unsigned long long signed_len = 0u;
        JinxValue value;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &message, &message_len) ||
            !jinx_sodium_exact_string(args[1], crypto_sign_SECRETKEYBYTES, &sk) ||
            message_len > UINT32_MAX - crypto_sign_BYTES) return result;

        value = jinx_sodium_alloc_result(message_len + crypto_sign_BYTES, &signed_message);
        if (value.type != 3u ||
            crypto_sign(
                signed_message,
                &signed_len,
                message,
                (unsigned long long)message_len,
                sk
            ) != 0 ||
            signed_len > UINT32_MAX) return result;

        value.flags = (uint32_t)signed_len;
        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_sign_open") == 0) {
        const unsigned char *signed_message;
        const unsigned char *pk;
        size_t signed_len;
        unsigned char *message;
        unsigned long long message_len = 0u;
        JinxValue value;

        if (args == NULL || argc != 2u ||
            !jinx_sodium_string(args[0], &signed_message, &signed_len) ||
            signed_len < crypto_sign_BYTES ||
            !jinx_sodium_exact_string(args[1], crypto_sign_PUBLICKEYBYTES, &pk)) {
            return result;
        }

        value = jinx_sodium_alloc_result(signed_len - crypto_sign_BYTES, &message);
        if (value.type != 3u) return result;
        if (crypto_sign_open(
                message,
                &message_len,
                signed_message,
                (unsigned long long)signed_len,
                pk
            ) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }

        if (message_len > UINT32_MAX) return result;
        value.flags = (uint32_t)message_len;
        if (handled != NULL) *handled = 1;
        return value;
    }

    if (strcmp(name, "sodium_crypto_scalarmult") == 0) {
        const unsigned char *scalar;
        const unsigned char *point;
        unsigned char out[crypto_scalarmult_BYTES];

        if (args == NULL || argc != 2u ||
            !jinx_sodium_exact_string(args[0], crypto_scalarmult_SCALARBYTES, &scalar) ||
            !jinx_sodium_exact_string(args[1], crypto_scalarmult_BYTES, &point)) {
            return result;
        }
        if (crypto_scalarmult(out, scalar, point) != 0) {
            if (handled != NULL) *handled = 1;
            return jinx_oracle_bool_value(0);
        }
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_scalarmult_base") == 0) {
        const unsigned char *scalar;
        unsigned char out[crypto_scalarmult_BYTES];

        if (args == NULL || argc != 1u ||
            !jinx_sodium_exact_string(args[0], crypto_scalarmult_SCALARBYTES, &scalar) ||
            crypto_scalarmult_base(out, scalar) != 0) return result;

        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(out, sizeof(out));
    }

    if (strcmp(name, "sodium_crypto_kdf_keygen") == 0) {
        unsigned char key[crypto_kdf_KEYBYTES];
        if (argc != 0u) return result;
        crypto_kdf_keygen(key);
        if (handled != NULL) *handled = 1;
        return jinx_sodium_copy(key, sizeof(key));
    }

    if (strcmp(name, "sodium_crypto_kdf_derive_from_key") == 0) {
        int64_t subkey_len_i;
        int64_t subkey_id_i;
        const unsigned char *context;
        const unsigned char *key;
        size_t context_len;
        unsigned char *out;
        JinxValue value;

        if (args == NULL || argc != 4u) return result;
        subkey_len_i = jinx_oracle_intish(args[0]);
        subkey_id_i = jinx_oracle_intish(args[1]);
        if (subkey_len_i < crypto_kdf_BYTES_MIN ||
            subkey_len_i > crypto_kdf_BYTES_MAX ||
            subkey_id_i < 0 ||
            !jinx_sodium_string(args[2], &context, &context_len) ||
            context_len != crypto_kdf_CONTEXTBYTES ||
            !jinx_sodium_exact_string(args[3], crypto_kdf_KEYBYTES, &key)) {
            return result;
        }

        value = jinx_sodium_alloc_result((size_t)subkey_len_i, &out);
        if (value.type != 3u ||
            crypto_kdf_derive_from_key(
                out,
                (size_t)subkey_len_i,
                (uint64_t)subkey_id_i,
                (const char *)context,
                key
            ) != 0) return result;

        if (handled != NULL) *handled = 1;
        return value;
    }

    return result;
}

#else

JinxValue jinx_oracle_sodium_builtin(
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
