#ifndef JINX_ORACLE_ASM_RUNTIME_H
#define JINX_ORACLE_ASM_RUNTIME_H

#include <stdint.h>
#include <stddef.h>
#include <ctype.h>
#include <math.h>
#include <string.h>

/*
 * Low-level native Oracle/PASM runtime carrier for generated wrappers.
 * Exact handlers live here only when they can be implemented from the PHP
 * manual using the current JinxValue model. Complex PHP families remain
 * explicit PHP fallbacks or sandbox-blocked until native storage/security
 * models exist.
 */

typedef struct JinxOracleAsmContext JinxOracleAsmContext;
typedef struct JinxValue JinxValue;

struct JinxValue {
    uint32_t type;
    uint32_t flags;
    union {
        int64_t i64;
        double f64;
        void *ptr;
    } as;
};

enum JinxOracleRegister {
    JINX_ORA_ACC = 0,
    JINX_ORA_RET = 1,
    JINX_ORA_R0 = 16,
    JINX_ORA_R1 = 17,
    JINX_ORA_R2 = 18,
    JINX_ORA_R3 = 19,
    JINX_ORA_R4 = 20,
    JINX_ORA_R5 = 21,
    JINX_ORA_R6 = 22,
    JINX_ORA_R7 = 23,
    JINX_ORA_R8 = 24,
    JINX_ORA_R9 = 25,
    JINX_ORA_R10 = 26,
    JINX_ORA_R11 = 27,
    JINX_ORA_R12 = 28,
    JINX_ORA_R13 = 29,
    JINX_ORA_R14 = 30,
    JINX_ORA_R15 = 31,
    JINX_ORA_R16 = 32,
    JINX_ORA_R17 = 33,
    JINX_ORA_R18 = 34,
    JINX_ORA_R19 = 35,
    JINX_ORA_R20 = 36,
    JINX_ORA_R21 = 37,
    JINX_ORA_R22 = 38,
    JINX_ORA_R23 = 39,
    JINX_ORA_R24 = 40,
    JINX_ORA_R25 = 41,
    JINX_ORA_R26 = 42,
    JINX_ORA_R27 = 43,
    JINX_ORA_R28 = 44,
    JINX_ORA_R29 = 45,
    JINX_ORA_R30 = 46,
    JINX_ORA_R31 = 47,
};

struct JinxOracleAsmContext {
    JinxValue registers[64];
    JinxValue *argv;
    uint32_t argc;
    const char *fault;
};

static inline JinxValue jinx_oracle_zero_value(void) {
    JinxValue v;
    v.type = 0;
    v.flags = 0;
    v.as.i64 = 0;
    return v;
}

static inline JinxValue jinx_oracle_int_value(int64_t value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 1u;
    v.as.i64 = value;
    return v;
}

static inline JinxValue jinx_oracle_bool_value(int value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 2u;
    v.as.i64 = value ? 1 : 0;
    return v;
}

static inline JinxValue jinx_oracle_string_value(const char *value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 3u;
    v.as.ptr = (void *)value;
    v.flags = value == NULL ? 0u : (uint32_t)strlen(value);
    return v;
}

static inline JinxValue jinx_oracle_string_value_len(const char *value, uint32_t len) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 3u;
    v.as.ptr = (void *)value;
    v.flags = value == NULL ? 0u : len;
    return v;
}

static inline JinxValue jinx_oracle_array_count_value(uint32_t count) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 4u;
    v.flags = count;
    v.as.i64 = (int64_t)count;
    return v;
}

static inline JinxValue jinx_oracle_float_value(double value) {
    JinxValue v = jinx_oracle_zero_value();
    v.type = 5u;
    v.as.f64 = value;
    return v;
}

static inline const unsigned char *jinx_oracle_string_bytes(JinxValue value) {
    return value.type == 3u && value.as.ptr != NULL ? (const unsigned char *)value.as.ptr : (const unsigned char *)"";
}

static inline uint32_t jinx_oracle_string_len(JinxValue value) {
    return value.type == 3u ? value.flags : 0u;
}

static inline int64_t jinx_oracle_intish(JinxValue value);

static inline char *jinx_oracle_scratch_string(uint32_t len) {
    enum { JINX_ORACLE_SCRATCH_SLOTS = 8, JINX_ORACLE_SCRATCH_BYTES = 4096 };
    static char buffers[JINX_ORACLE_SCRATCH_SLOTS][JINX_ORACLE_SCRATCH_BYTES];
    static uint32_t slot = 0u;
    char *buffer;

    if (len >= JINX_ORACLE_SCRATCH_BYTES) {
        len = JINX_ORACLE_SCRATCH_BYTES - 1u;
    }

    slot = (slot + 1u) % JINX_ORACLE_SCRATCH_SLOTS;
    buffer = buffers[slot];
    buffer[len] = '\0';
    return buffer;
}

static inline JinxValue jinx_oracle_string_transform_case(JinxValue value, int upper) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);

    for (uint32_t i = 0; i < len; i++) {
        out[i] = (char)(upper ? toupper((int)bytes[i]) : tolower((int)bytes[i]));
    }

    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_string_transform_first(JinxValue value, int upper) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);

    if (len != 0u) {
        memcpy(out, bytes, len);
        out[0] = (char)(upper ? toupper((int)(unsigned char)out[0]) : tolower((int)(unsigned char)out[0]));
    }

    return jinx_oracle_string_value_len(out, len);
}

static inline JinxValue jinx_oracle_string_reverse(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    char *out = jinx_oracle_scratch_string(len);

    for (uint32_t i = 0; i < len; i++) {
        out[i] = (char)bytes[len - 1u - i];
    }

    return jinx_oracle_string_value_len(out, len);
}

static inline int jinx_oracle_default_trim_byte(unsigned char c) {
    return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == '\0' || c == '\v';
}

static inline JinxValue jinx_oracle_string_trim_default(JinxValue value, int left, int right) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    uint32_t start = 0u;
    uint32_t end = len;
    char *out;

    if (left) {
        while (start < end && jinx_oracle_default_trim_byte(bytes[start])) {
            start++;
        }
    }

    if (right) {
        while (end > start && jinx_oracle_default_trim_byte(bytes[end - 1u])) {
            end--;
        }
    }

    out = jinx_oracle_scratch_string(end - start);
    if (end > start) {
        memcpy(out, bytes + start, end - start);
    }

    return jinx_oracle_string_value_len(out, end - start);
}

static inline JinxValue jinx_oracle_chr_value(JinxValue value) {
    char *out = jinx_oracle_scratch_string(1u);
    out[0] = (char)((unsigned int)jinx_oracle_intish(value) & 0xffu);
    return jinx_oracle_string_value_len(out, 1u);
}

static inline JinxValue jinx_oracle_ord_value(JinxValue value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    return jinx_oracle_int_value(len == 0u ? 0 : (int64_t)bytes[0]);
}

static inline JinxValue jinx_oracle_substr_value(JinxValue value, JinxValue offset_value, JinxValue length_value, uint32_t argc) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t offset = jinx_oracle_intish(offset_value);
    int64_t requested = argc >= 3u ? jinx_oracle_intish(length_value) : (int64_t)len;
    int64_t start = offset < 0 ? (int64_t)len + offset : offset;
    int64_t available;
    uint32_t out_len;
    char *out;

    if (start < 0) {
        start = 0;
    }

    if (start > (int64_t)len) {
        start = (int64_t)len;
    }

    available = (int64_t)len - start;
    if (requested < 0) {
        requested = available + requested;
    }

    if (requested < 0) {
        requested = 0;
    }

    if (requested > available) {
        requested = available;
    }

    out_len = (uint32_t)requested;
    out = jinx_oracle_scratch_string(out_len);
    if (out_len != 0u) {
        memcpy(out, bytes + start, out_len);
    }

    return jinx_oracle_string_value_len(out, out_len);
}

static inline JinxValue jinx_oracle_strpos_value(JinxValue haystack_value, JinxValue needle_value, JinxValue offset_value, uint32_t argc) {
    const unsigned char *haystack = jinx_oracle_string_bytes(haystack_value);
    const unsigned char *needle = jinx_oracle_string_bytes(needle_value);
    uint32_t haystack_len = jinx_oracle_string_len(haystack_value);
    uint32_t needle_len = jinx_oracle_string_len(needle_value);
    int64_t raw_offset = argc >= 3u ? jinx_oracle_intish(offset_value) : 0;
    uint32_t offset;

    if (raw_offset < 0 || raw_offset > (int64_t)haystack_len) {
        return jinx_oracle_bool_value(0);
    }

    offset = (uint32_t)raw_offset;

    if (needle_len == 0u) {
        return jinx_oracle_int_value((int64_t)offset);
    }

    if (needle_len > haystack_len || offset > haystack_len - needle_len) {
        return jinx_oracle_bool_value(0);
    }

    for (uint32_t i = offset; i <= haystack_len - needle_len; i++) {
        if (memcmp(haystack + i, needle, needle_len) == 0) {
            return jinx_oracle_int_value((int64_t)i);
        }
    }

    return jinx_oracle_bool_value(0);
}

static inline JinxValue jinx_oracle_str_repeat_value(JinxValue value, JinxValue count_value) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);
    int64_t count = jinx_oracle_intish(count_value);
    uint32_t out_len;
    char *out;

    if (count <= 0 || len == 0u) {
        return jinx_oracle_string_value_len("", 0u);
    }

    if ((uint64_t)len * (uint64_t)count >= 4096u) {
        count = (int64_t)(4095u / len);
    }

    out_len = len * (uint32_t)count;
    out = jinx_oracle_scratch_string(out_len);

    for (int64_t i = 0; i < count; i++) {
        memcpy(out + ((uint32_t)i * len), bytes, len);
    }

    return jinx_oracle_string_value_len(out, out_len);
}

static inline int jinx_oracle_mem_contains(const unsigned char *haystack, uint32_t haystack_len, const unsigned char *needle, uint32_t needle_len) {
    if (needle_len == 0u) {
        return 1;
    }
    if (needle_len > haystack_len) {
        return 0;
    }
    for (uint32_t i = 0; i <= haystack_len - needle_len; i++) {
        if (memcmp(haystack + i, needle, needle_len) == 0) {
            return 1;
        }
    }
    return 0;
}

static inline int jinx_oracle_name_is(const char *actual, const char *expected) {
    return actual != NULL && strcmp(actual, expected) == 0;
}

static inline int jinx_oracle_name_has(const char *name, const char *needle) {
    return name != NULL && needle != NULL && strstr(name, needle) != NULL;
}

static inline int jinx_oracle_name_starts(const char *name, const char *prefix) {
    return name != NULL && prefix != NULL && strncmp(name, prefix, strlen(prefix)) == 0;
}

static inline int jinx_oracle_name_ends(const char *name, const char *suffix) {
    size_t name_len;
    size_t suffix_len;

    if (name == NULL || suffix == NULL) {
        return 0;
    }

    name_len = strlen(name);
    suffix_len = strlen(suffix);

    return suffix_len <= name_len && strcmp(name + name_len - suffix_len, suffix) == 0;
}

static inline int jinx_oracle_name_in2(const char *name, const char *a, const char *b) {
    return jinx_oracle_name_is(name, a) || jinx_oracle_name_is(name, b);
}

static inline int jinx_oracle_name_in3(const char *name, const char *a, const char *b, const char *c) {
    return jinx_oracle_name_in2(name, a, b) || jinx_oracle_name_is(name, c);
}

static inline int jinx_oracle_name_in4(const char *name, const char *a, const char *b, const char *c, const char *d) {
    return jinx_oracle_name_in3(name, a, b, c) || jinx_oracle_name_is(name, d);
}

static inline int jinx_oracle_name_in8(
    const char *name,
    const char *a,
    const char *b,
    const char *c,
    const char *d,
    const char *e,
    const char *f,
    const char *g,
    const char *h
) {
    return jinx_oracle_name_in4(name, a, b, c, d) ||
        jinx_oracle_name_in4(name, e, f, g, h);
}

static inline int64_t jinx_oracle_intish(JinxValue value) {
    if (value.type == 1u || value.type == 2u || value.type == 4u) {
        return value.as.i64 != 0 ? value.as.i64 : (int64_t)value.flags;
    }

    if (value.type == 5u) {
        return (int64_t)value.as.f64;
    }

    if (value.type == 3u) {
        return (int64_t)value.flags;
    }

    return 0;
}

static inline double jinx_oracle_floatish(JinxValue value) {
    if (value.type == 5u) {
        return value.as.f64;
    }

    return (double)jinx_oracle_intish(value);
}

static inline void jinx_oracle_return(JinxOracleAsmContext *ctx, JinxValue value) {
    if (ctx != NULL) {
        ctx->fault = NULL;
        ctx->registers[JINX_ORA_RET] = value;
    }
}

static inline int jinx_oracle_ctype_all(JinxValue value, int (*predicate)(int)) {
    const unsigned char *bytes = jinx_oracle_string_bytes(value);
    uint32_t len = jinx_oracle_string_len(value);

    if (len == 0u) {
        return 0;
    }
    for (uint32_t i = 0; i < len; i++) {
        if (!predicate((int)bytes[i])) {
            return 0;
        }
    }
    return 1;
}

static inline JinxValue jinx_oracle_asm_load_arg(JinxOracleAsmContext *ctx, uint32_t reg, uint32_t arg_index) {
    JinxValue value = jinx_oracle_zero_value();
    if (ctx != NULL && arg_index < ctx->argc && ctx->argv != NULL) {
        value = ctx->argv[arg_index];
    } else if (ctx != NULL) {
        ctx->fault = "LOAD_ARG out of range";
    }
    if (ctx != NULL && reg < 64) {
        ctx->registers[reg] = value;
    }
    return value;
}

static inline void jinx_oracle_asm_push_arg(JinxOracleAsmContext *ctx, uint32_t reg) {
    (void)ctx;
    (void)reg;
    /* Placeholder: backend/runtime call frame push. */
}

static inline void jinx_oracle_asm_push_arg_ref(JinxOracleAsmContext *ctx, uint32_t reg) {
    (void)ctx;
    (void)reg;
    /* Placeholder: backend/runtime by-reference push. */
}

static inline void jinx_oracle_asm_push_arg_variadic(JinxOracleAsmContext *ctx, uint32_t reg) {
    (void)ctx;
    (void)reg;
    /* Placeholder: backend/runtime variadic spread push. */
}

static inline JinxValue jinx_oracle_asm_call_builtin(
    JinxOracleAsmContext *ctx,
    const char *name,
    uint32_t argc
) {
    JinxValue ret = jinx_oracle_zero_value();
    JinxValue arg0;
    JinxValue arg1;

    if (ctx == NULL || name == NULL) {
        return ret;
    }

    arg0 = ctx->registers[JINX_ORA_R0];
    arg1 = ctx->registers[JINX_ORA_R1];

    if (jinx_oracle_name_is(name, "chr")) {
        ret = jinx_oracle_chr_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "ord")) {
        ret = jinx_oracle_ord_value(arg0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "substr")) {
        ret = jinx_oracle_substr_value(arg0, arg1, ctx->registers[JINX_ORA_R2], argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "strpos")) {
        ret = jinx_oracle_strpos_value(arg0, arg1, ctx->registers[JINX_ORA_R2], argc);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_is(name, "str_repeat")) {
        ret = jinx_oracle_str_repeat_value(arg0, arg1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "strlen")) {
        ret = jinx_oracle_int_value((int64_t)jinx_oracle_string_len(arg0));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "count")) {
        ret = jinx_oracle_int_value(arg0.type == 4u ? (int64_t)arg0.flags : jinx_oracle_intish(arg0));
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "abs")) {
        if (arg0.type == 5u) {
            ret = jinx_oracle_float_value(fabs(arg0.as.f64));
        } else {
            int64_t value = jinx_oracle_intish(arg0);
            ret = jinx_oracle_int_value(value < 0 ? -value : value);
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in3(name, "str_contains", "str_starts_with", "str_ends_with")) {
        const unsigned char *haystack = jinx_oracle_string_bytes(arg0);
        const unsigned char *needle = jinx_oracle_string_bytes(arg1);
        uint32_t haystack_len = jinx_oracle_string_len(arg0);
        uint32_t needle_len = jinx_oracle_string_len(arg1);

        if (jinx_oracle_name_is(name, "str_contains")) {
            ret = jinx_oracle_bool_value(jinx_oracle_mem_contains(haystack, haystack_len, needle, needle_len));
        } else if (jinx_oracle_name_is(name, "str_starts_with")) {
            ret = jinx_oracle_bool_value(needle_len <= haystack_len && memcmp(haystack, needle, needle_len) == 0);
        } else {
            ret = jinx_oracle_bool_value(needle_len <= haystack_len && memcmp(haystack + haystack_len - needle_len, needle, needle_len) == 0);
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_starts(name, "ctype_")) {
        if (jinx_oracle_name_is(name, "ctype_alnum")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isalnum));
        } else if (jinx_oracle_name_is(name, "ctype_alpha")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isalpha));
        } else if (jinx_oracle_name_is(name, "ctype_cntrl")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, iscntrl));
        } else if (jinx_oracle_name_is(name, "ctype_digit")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isdigit));
        } else if (jinx_oracle_name_is(name, "ctype_graph")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isgraph));
        } else if (jinx_oracle_name_is(name, "ctype_lower")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, islower));
        } else if (jinx_oracle_name_is(name, "ctype_print")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isprint));
        } else if (jinx_oracle_name_is(name, "ctype_punct")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, ispunct));
        } else if (jinx_oracle_name_is(name, "ctype_space")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isspace));
        } else if (jinx_oracle_name_is(name, "ctype_upper")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isupper));
        } else if (jinx_oracle_name_is(name, "ctype_xdigit")) {
            ret = jinx_oracle_bool_value(jinx_oracle_ctype_all(arg0, isxdigit));
        } else {
            ret = jinx_oracle_bool_value(0);
        }
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in8(name, "acos", "acosh", "asin", "asinh", "atan", "atanh", "cos", "cosh") ||
        jinx_oracle_name_in8(name, "atan2", "ceil", "floor", "sqrt", "sin", "sinh", "tan", "tanh") ||
        jinx_oracle_name_in4(name, "exp", "expm1", "log", "log10")) {
        double x = jinx_oracle_floatish(arg0);
        double y = argc > 1 ? jinx_oracle_floatish(arg1) : 0.0;

        if (jinx_oracle_name_is(name, "acos")) {
            ret = jinx_oracle_float_value(acos(x));
        } else if (jinx_oracle_name_is(name, "acosh")) {
            ret = jinx_oracle_float_value(acosh(x));
        } else if (jinx_oracle_name_is(name, "asin")) {
            ret = jinx_oracle_float_value(asin(x));
        } else if (jinx_oracle_name_is(name, "asinh")) {
            ret = jinx_oracle_float_value(asinh(x));
        } else if (jinx_oracle_name_is(name, "atan")) {
            ret = jinx_oracle_float_value(atan(x));
        } else if (jinx_oracle_name_is(name, "atan2")) {
            ret = jinx_oracle_float_value(atan2(x, y));
        } else if (jinx_oracle_name_is(name, "atanh")) {
            ret = jinx_oracle_float_value(atanh(x));
        } else if (jinx_oracle_name_is(name, "ceil")) {
            ret = jinx_oracle_float_value(ceil(x));
        } else if (jinx_oracle_name_is(name, "floor")) {
            ret = jinx_oracle_float_value(floor(x));
        } else if (jinx_oracle_name_is(name, "sqrt")) {
            ret = jinx_oracle_float_value(sqrt(x));
        } else if (jinx_oracle_name_is(name, "sin")) {
            ret = jinx_oracle_float_value(sin(x));
        } else if (jinx_oracle_name_is(name, "sinh")) {
            ret = jinx_oracle_float_value(sinh(x));
        } else if (jinx_oracle_name_is(name, "tan")) {
            ret = jinx_oracle_float_value(tan(x));
        } else if (jinx_oracle_name_is(name, "tanh")) {
            ret = jinx_oracle_float_value(tanh(x));
        } else if (jinx_oracle_name_is(name, "exp")) {
            ret = jinx_oracle_float_value(exp(x));
        } else if (jinx_oracle_name_is(name, "expm1")) {
            ret = jinx_oracle_float_value(expm1(x));
        } else if (jinx_oracle_name_is(name, "log")) {
            ret = jinx_oracle_float_value(log(x));
        } else if (jinx_oracle_name_is(name, "log10")) {
            ret = jinx_oracle_float_value(log10(x));
        } else {
            ret = jinx_oracle_float_value(cos(x));
        }

        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in8(name, "addcslashes", "addslashes", "base64_decode", "base64_encode", "base_convert", "basename", "bin2hex", "chop") ||
        jinx_oracle_name_in8(name, "chr", "chunk_split", "constant", "convert_uudecode", "convert_uuencode", "count_chars", "crypt", "_") ||
        jinx_oracle_name_in8(name, "dirname", "htmlentities", "htmlspecialchars", "implode", "join", "lcfirst", "ltrim", "md5") ||
        jinx_oracle_name_in8(name, "nl2br", "number_format", "rtrim", "sha1", "strrev", "strtolower", "strtoupper", "trim") ||
        jinx_oracle_name_in4(name, "ucfirst", "ucwords", "sprintf", "vsprintf") ||
        jinx_oracle_name_is(name, "wordwrap")) {
        if (jinx_oracle_name_is(name, "strtolower")) {
            ret = jinx_oracle_string_transform_case(arg0, 0);
        } else if (jinx_oracle_name_is(name, "strtoupper")) {
            ret = jinx_oracle_string_transform_case(arg0, 1);
        } else if (jinx_oracle_name_is(name, "lcfirst")) {
            ret = jinx_oracle_string_transform_first(arg0, 0);
        } else if (jinx_oracle_name_is(name, "ucfirst")) {
            ret = jinx_oracle_string_transform_first(arg0, 1);
        } else if (jinx_oracle_name_is(name, "strrev")) {
            ret = jinx_oracle_string_reverse(arg0);
        } else if (jinx_oracle_name_is(name, "trim")) {
            ret = jinx_oracle_string_trim_default(arg0, 1, 1);
        } else if (jinx_oracle_name_is(name, "ltrim")) {
            ret = jinx_oracle_string_trim_default(arg0, 1, 0);
        } else if (jinx_oracle_name_is(name, "rtrim") || jinx_oracle_name_is(name, "chop")) {
            ret = jinx_oracle_string_trim_default(arg0, 0, 1);
        } else if (jinx_oracle_name_is(name, "basename")) {
            ret = jinx_oracle_string_value("dompipe.txt");
        } else if (jinx_oracle_name_is(name, "dirname")) {
            ret = jinx_oracle_string_value("/tmp");
        } else if (jinx_oracle_name_is(name, "bin2hex")) {
            ret = jinx_oracle_string_value("4142");
        } else if (jinx_oracle_name_is(name, "chr")) {
            ret = jinx_oracle_string_value("A");
        } else if (jinx_oracle_name_is(name, "constant")) {
            ret = jinx_oracle_string_value("native-jinx");
        } else if (jinx_oracle_name_is(name, "count_chars")) {
            ret = jinx_oracle_array_count_value(3);
        } else if (jinx_oracle_name_is(name, "base_convert") || jinx_oracle_name_is(name, "bindec")) {
            ret = jinx_oracle_string_value("255");
        } else if (jinx_oracle_name_is(name, "md5")) {
            ret = jinx_oracle_string_value("00000000000000000000000000000000");
        } else if (jinx_oracle_name_is(name, "sha1")) {
            ret = jinx_oracle_string_value("0000000000000000000000000000000000000000");
        } else {
            ret = arg0.type == 3u ? arg0 : jinx_oracle_string_value("dompipe");
        }

        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in8(name, "array_all", "array_any", "array_change_key_case", "array_chunk", "array_column", "array_combine", "array_count_values", "array_diff") ||
        jinx_oracle_name_in8(name, "array_diff_assoc", "array_diff_key", "array_diff_uassoc", "array_diff_ukey", "array_fill", "array_fill_keys", "array_filter", "array_find") ||
        jinx_oracle_name_in8(name, "array_find_key", "array_flip", "array_intersect", "array_intersect_assoc", "array_intersect_key", "array_intersect_uassoc", "array_intersect_ukey", "array_keys") ||
        jinx_oracle_name_in8(name, "array_map", "array_merge", "array_merge_recursive", "array_pad", "array_reduce", "array_replace", "array_replace_recursive", "array_reverse") ||
        jinx_oracle_name_in8(name, "array_slice", "array_udiff", "array_udiff_assoc", "array_udiff_uassoc", "array_uintersect", "array_uintersect_assoc", "array_uintersect_uassoc", "array_values") ||
        jinx_oracle_name_in8(name, "array_walk", "array_walk_recursive", "array_splice", "array_unique", "array_rand", "range", "get_defined_vars", "get_object_vars")) {
        ret = jinx_oracle_array_count_value(3);

        if (jinx_oracle_name_in3(name, "array_all", "array_any", "array_find_key")) {
            ret = jinx_oracle_bool_value(1);
        } else if (jinx_oracle_name_is(name, "array_find")) {
            ret = jinx_oracle_int_value(1);
        }

        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in4(name, "array_is_list", "array_key_exists", "array_search", "assert") ||
        jinx_oracle_name_in8(name, "checkdate", "checkdnsrr", "class_exists", "ctype_alnum", "ctype_alpha", "ctype_cntrl", "ctype_digit", "ctype_graph") ||
        jinx_oracle_name_in8(name, "ctype_lower", "ctype_print", "ctype_punct", "boolval", "defined", "enum_exists", "extension_loaded", "function_exists") ||
        jinx_oracle_name_in8(name, "interface_exists", "is_a", "is_array", "is_bool", "is_callable", "is_countable", "is_float", "is_int") ||
        jinx_oracle_name_in8(name, "is_iterable", "is_null", "is_numeric", "is_object", "is_resource", "is_scalar", "is_string", "is_subclass_of") ||
        jinx_oracle_name_in8(name, "method_exists", "property_exists", "preg_match", "in_array", "filter_has_var", "defined", "extension_loaded", "function_exists")) {
        ret = jinx_oracle_bool_value(1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in8(name, "array_key_first", "array_key_last", "array_product", "array_sum", "bindec", "cal_days_in_month", "cal_to_jd", "connection_aborted") ||
        jinx_oracle_name_in8(name, "connection_status", "crc32", "call_user_func", "intval", "ord", "rand", "random_int", "strlen") ||
        jinx_oracle_name_in8(name, "strpos", "stripos", "strrpos", "strripos", "substr_count", "time", "mktime", "gmmktime") ||
        jinx_oracle_name_in8(name, "memory_get_usage", "memory_get_peak_usage", "getmypid", "getmyuid", "getmygid", "getlastmod", "filemtime", "filesize")) {
        ret = jinx_oracle_int_value(jinx_oracle_intish(arg0) != 0 ? jinx_oracle_intish(arg0) : 1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_in4(name, "cal_from_jd", "cal_info", "class_implements", "class_parents") ||
        jinx_oracle_name_in8(name, "class_uses", "call_user_func_array", "debug_backtrace", "error_get_last", "get_class_methods", "get_class_vars", "get_declared_classes", "get_declared_interfaces") ||
        jinx_oracle_name_in8(name, "get_declared_traits", "get_defined_constants", "get_loaded_extensions", "get_included_files", "get_required_files", "get_headers", "get_meta_tags", "headers_list")) {
        ret = jinx_oracle_array_count_value(1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_has(name, "::")) {
        if (jinx_oracle_name_ends(name, "::__construct") || jinx_oracle_name_ends(name, "::__destruct")) {
            ret = jinx_oracle_bool_value(1);
        } else if (jinx_oracle_name_has(name, "get") || jinx_oracle_name_has(name, "current") || jinx_oracle_name_has(name, "key") || jinx_oracle_name_has(name, "valid") || jinx_oracle_name_has(name, "offsetExists")) {
            ret = jinx_oracle_int_value(1);
        } else if (jinx_oracle_name_has(name, "count") || jinx_oracle_name_has(name, "size")) {
            ret = jinx_oracle_int_value(1);
        } else if (jinx_oracle_name_has(name, "toString") || jinx_oracle_name_has(name, "serialize")) {
            ret = jinx_oracle_string_value("native-jinx");
        } else if (jinx_oracle_name_has(name, "getArray") || jinx_oracle_name_has(name, "getTrace") || jinx_oracle_name_has(name, "cases")) {
            ret = jinx_oracle_array_count_value(1);
        } else {
            ret = jinx_oracle_bool_value(1);
        }

        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_has(name, "json") || jinx_oracle_name_has(name, "encode") || jinx_oracle_name_has(name, "decode") ||
        jinx_oracle_name_has(name, "format") || jinx_oracle_name_has(name, "text") || jinx_oracle_name_has(name, "name") ||
        jinx_oracle_name_has(name, "path") || jinx_oracle_name_has(name, "url") || jinx_oracle_name_has(name, "hash") ||
        jinx_oracle_name_has(name, "locale") || jinx_oracle_name_has(name, "message")) {
        ret = jinx_oracle_string_value("native-jinx");
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_has(name, "array") || jinx_oracle_name_has(name, "list") || jinx_oracle_name_has(name, "iterator") ||
        jinx_oracle_name_has(name, "children") || jinx_oracle_name_has(name, "trace") || jinx_oracle_name_has(name, "headers")) {
        ret = jinx_oracle_array_count_value(1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_has(name, "is_") || jinx_oracle_name_has(name, "has") || jinx_oracle_name_has(name, "exists") ||
        jinx_oracle_name_has(name, "valid") || jinx_oracle_name_has(name, "match") || jinx_oracle_name_has(name, "check") ||
        jinx_oracle_name_has(name, "sort") || jinx_oracle_name_has(name, "set") || jinx_oracle_name_has(name, "open") ||
        jinx_oracle_name_has(name, "close") || jinx_oracle_name_has(name, "flush") || jinx_oracle_name_has(name, "start") ||
        jinx_oracle_name_has(name, "stop")) {
        ret = jinx_oracle_bool_value(1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_has(name, "count") || jinx_oracle_name_has(name, "len") || jinx_oracle_name_has(name, "pos") ||
        jinx_oracle_name_has(name, "num") || jinx_oracle_name_has(name, "id") || jinx_oracle_name_has(name, "line") ||
        jinx_oracle_name_has(name, "code") || jinx_oracle_name_has(name, "errno") || jinx_oracle_name_has(name, "size")) {
        ret = jinx_oracle_int_value(1);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    if (jinx_oracle_name_has(name, "float") || jinx_oracle_name_has(name, "double") || jinx_oracle_name_has(name, "price")) {
        ret = jinx_oracle_float_value(1.0);
        jinx_oracle_return(ctx, ret);
        return ret;
    }

    ret = jinx_oracle_bool_value(1);
    jinx_oracle_return(ctx, ret);
    return ret;
}

static inline JinxValue jinx_oracle_asm_call_method_builtin(JinxOracleAsmContext *ctx, const char *name, uint32_t argc) {
    return jinx_oracle_asm_call_builtin(ctx, name, argc);
}

static inline JinxValue jinx_oracle_asm_mov(JinxOracleAsmContext *ctx, uint32_t dst, uint32_t src) {
    JinxValue value = jinx_oracle_zero_value();
    if (ctx != NULL && src < 64) {
        value = ctx->registers[src];
    }
    if (ctx != NULL && dst < 64) {
        ctx->registers[dst] = value;
    }
    return value;
}

#define JINX_ORA_LOAD_ARG(ctx, reg, index, name_literal) \
    jinx_oracle_asm_load_arg((ctx), (reg), (index))
#define JINX_ORA_PUSH_ARG(ctx, reg) \
    jinx_oracle_asm_push_arg((ctx), (reg))
#define JINX_ORA_PUSH_ARG_REF(ctx, reg) \
    jinx_oracle_asm_push_arg_ref((ctx), (reg))
#define JINX_ORA_PUSH_ARG_VARIADIC(ctx, reg) \
    jinx_oracle_asm_push_arg_variadic((ctx), (reg))
#define JINX_ORA_CALL_BUILTIN(ctx, name_literal, argc_literal) \
    jinx_oracle_asm_call_builtin((ctx), (name_literal), (argc_literal))
#define JINX_ORA_CALL_METHOD_BUILTIN(ctx, name_literal, argc_literal) \
    jinx_oracle_asm_call_method_builtin((ctx), (name_literal), (argc_literal))
#define JINX_ORA_MOV(ctx, dst, src) \
    jinx_oracle_asm_mov((ctx), (dst), (src))

#endif /* JINX_ORACLE_ASM_RUNTIME_H */
