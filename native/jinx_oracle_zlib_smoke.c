#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "../runtime/jinx_builtin_dispatch.h"
#include "../build/oracle-asm/jinx_oracle_asm_runtime.h"

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

static void print_hex_bytes(const unsigned char *bytes, uint32_t len) {
    static const char hex[] = "0123456789abcdef";
    for (uint32_t i = 0u; i < len; i++) {
        unsigned char b = bytes[i];
        putchar(hex[b >> 4]);
        putchar(hex[b & 0x0f]);
    }
}

static int expect_string_value(JinxValue value) {
    return value.type == 3u && value.as.ptr != NULL;
}

static int expect_false_value(JinxValue value) {
    return value.type == 2u && value.as.i64 == 0;
}

static unsigned char *copy_string_value(JinxValue value, uint32_t *len_out) {
    if (!expect_string_value(value)) return NULL;
    uint32_t len = value.flags;
    unsigned char *copy = (unsigned char *)malloc(len == 0u ? 1u : len);
    if (copy == NULL) return NULL;
    if (len != 0u) memcpy(copy, value.as.ptr, len);
    *len_out = len;
    return copy;
}

static JinxValue call_encode(
    const char *name,
    const char *payload,
    int have_encoding,
    int encoding,
    int have_level,
    int level
) {
    JinxValue args[3];
    args[0] = jinx_oracle_string_value(payload);
    size_t argc = 1u;

    if (strcmp(name, "zlib_encode") == 0) {
        args[1] = jinx_oracle_int_value(encoding);
        argc = 2u;
        if (have_level) {
            args[2] = jinx_oracle_int_value(level);
            argc = 3u;
        }
    } else {
        if (have_level) {
            args[1] = jinx_oracle_int_value(level);
            argc = 2u;
        }
        if (have_encoding) {
            if (!have_level) args[1] = jinx_oracle_int_value(-1);
            args[2] = jinx_oracle_int_value(encoding);
            argc = 3u;
        }
    }

    return jinx_call_builtin_through_oracle(name, args, argc);
}

static JinxValue call_decode(
    const char *name,
    const unsigned char *bytes,
    uint32_t len,
    int have_max,
    int64_t max_length
) {
    JinxValue args[2];
    args[0] = jinx_oracle_string_value_len((const char *)bytes, len);
    size_t argc = 1u;
    if (have_max) {
        args[1] = jinx_oracle_int_value(max_length);
        argc = 2u;
    }
    return jinx_call_builtin_through_oracle(name, args, argc);
}

int main(void) {
    const char *payload = "JINX zlib parity 123123123";
    const uint32_t payload_len = (uint32_t)strlen(payload);

    JinxValue gzcompress_v = call_encode("gzcompress", payload, 0, 0, 0, 0);
    uint32_t gzcompress_len = 0u;
    unsigned char *gzcompress_copy = copy_string_value(gzcompress_v, &gzcompress_len);
    if (gzcompress_copy == NULL) return fail("gzcompress");
    printf("ZLIB:gzcompress=");
    print_hex_bytes(gzcompress_copy, gzcompress_len);

    JinxValue gzuncompress_v = call_decode("gzuncompress", gzcompress_copy, gzcompress_len, 0, 0);
    if (!expect_string_value(gzuncompress_v) || gzuncompress_v.flags != payload_len ||
        memcmp(gzuncompress_v.as.ptr, payload, payload_len) != 0) {
        free(gzcompress_copy);
        return fail("gzuncompress");
    }
    printf(";gzuncompress=");
    print_hex_bytes((const unsigned char *)gzuncompress_v.as.ptr, gzuncompress_v.flags);

    JinxValue gzdeflate_v = call_encode("gzdeflate", payload, 0, 0, 1, 6);
    uint32_t gzdeflate_len = 0u;
    unsigned char *gzdeflate_copy = copy_string_value(gzdeflate_v, &gzdeflate_len);
    if (gzdeflate_copy == NULL) {
        free(gzcompress_copy);
        return fail("gzdeflate");
    }
    printf(";gzdeflate=");
    print_hex_bytes(gzdeflate_copy, gzdeflate_len);

    JinxValue gzinflate_v = call_decode("gzinflate", gzdeflate_copy, gzdeflate_len, 0, 0);
    if (!expect_string_value(gzinflate_v) || gzinflate_v.flags != payload_len ||
        memcmp(gzinflate_v.as.ptr, payload, payload_len) != 0) {
        free(gzcompress_copy);
        free(gzdeflate_copy);
        return fail("gzinflate");
    }
    printf(";gzinflate=");
    print_hex_bytes((const unsigned char *)gzinflate_v.as.ptr, gzinflate_v.flags);

    JinxValue gzencode_v = call_encode("gzencode", payload, 0, 0, 1, 6);
    uint32_t gzencode_len = 0u;
    unsigned char *gzencode_copy = copy_string_value(gzencode_v, &gzencode_len);
    if (gzencode_copy == NULL) {
        free(gzcompress_copy);
        free(gzdeflate_copy);
        return fail("gzencode");
    }
    printf(";gzencode=");
    print_hex_bytes(gzencode_copy, gzencode_len);

    JinxValue gzdecode_v = call_decode("gzdecode", gzencode_copy, gzencode_len, 0, 0);
    if (!expect_string_value(gzdecode_v) || gzdecode_v.flags != payload_len ||
        memcmp(gzdecode_v.as.ptr, payload, payload_len) != 0) {
        free(gzcompress_copy);
        free(gzdeflate_copy);
        free(gzencode_copy);
        return fail("gzdecode");
    }
    printf(";gzdecode=");
    print_hex_bytes((const unsigned char *)gzdecode_v.as.ptr, gzdecode_v.flags);

    JinxValue zlib_v = call_encode(
        "zlib_encode",
        payload,
        1,
        JINX_PHP_ZLIB_ENCODING_DEFLATE,
        1,
        6
    );
    uint32_t zlib_len = 0u;
    unsigned char *zlib_copy = copy_string_value(zlib_v, &zlib_len);
    if (zlib_copy == NULL) {
        free(gzcompress_copy);
        free(gzdeflate_copy);
        free(gzencode_copy);
        return fail("zlib_encode");
    }
    printf(";zlib=");
    print_hex_bytes(zlib_copy, zlib_len);

    JinxValue zlib_decode_gzip_v = call_decode("zlib_decode", gzencode_copy, gzencode_len, 0, 0);
    if (!expect_string_value(zlib_decode_gzip_v) || zlib_decode_gzip_v.flags != payload_len ||
        memcmp(zlib_decode_gzip_v.as.ptr, payload, payload_len) != 0) {
        free(gzcompress_copy);
        free(gzdeflate_copy);
        free(gzencode_copy);
        free(zlib_copy);
        return fail("zlib_decode gzip");
    }
    printf(";zlibdecode_gzip=");
    print_hex_bytes((const unsigned char *)zlib_decode_gzip_v.as.ptr, zlib_decode_gzip_v.flags);

    JinxValue zlib_decode_raw_v = call_decode("zlib_decode", gzdeflate_copy, gzdeflate_len, 0, 0);
    if (!expect_string_value(zlib_decode_raw_v) || zlib_decode_raw_v.flags != payload_len ||
        memcmp(zlib_decode_raw_v.as.ptr, payload, payload_len) != 0) {
        free(gzcompress_copy);
        free(gzdeflate_copy);
        free(gzencode_copy);
        free(zlib_copy);
        return fail("zlib_decode raw");
    }
    printf(";zlibdecode_raw=");
    print_hex_bytes((const unsigned char *)zlib_decode_raw_v.as.ptr, zlib_decode_raw_v.flags);

    JinxValue max_ok_v = call_decode("gzuncompress", gzcompress_copy, gzcompress_len, 1, payload_len);
    if (!expect_string_value(max_ok_v) || max_ok_v.flags != payload_len ||
        memcmp(max_ok_v.as.ptr, payload, payload_len) != 0) {
        free(gzcompress_copy);
        free(gzdeflate_copy);
        free(gzencode_copy);
        free(zlib_copy);
        return fail("max_length exact");
    }
    printf(";maxok=");
    print_hex_bytes((const unsigned char *)max_ok_v.as.ptr, max_ok_v.flags);

    JinxValue max_fail_v = call_decode("gzuncompress", gzcompress_copy, gzcompress_len, 1, 4);
    printf(";maxfail=%s\n", expect_false_value(max_fail_v) ? "false" : "unexpected");

    free(gzcompress_copy);
    free(gzdeflate_copy);
    free(gzencode_copy);
    free(zlib_copy);

    puts("PASS: native Oracle zlib helpers passed");
    return expect_false_value(max_fail_v) ? 0 : 1;
}
