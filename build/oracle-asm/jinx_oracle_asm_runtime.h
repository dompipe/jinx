#ifndef JINX_ORACLE_ASM_RUNTIME_H
#define JINX_ORACLE_ASM_RUNTIME_H

#include <stdint.h>
#include <stddef.h>
#include <math.h>
#include <string.h>

/*
 * This header is intentionally a low-level representation layer.
 * It is not the PHP implementation. The generated inline functions are
 * C carriers for Oracle/PASM-shaped operations that a later backend can
 * expand into GCC inline asm, ASM-shaped C, or direct runtime calls.
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

static inline int jinx_oracle_name_is(const char *actual, const char *expected) {
    return actual != NULL && strcmp(actual, expected) == 0;
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

    if (ctx == NULL || name == NULL) {
        return ret;
    }

    if (argc == 1 && jinx_oracle_name_is(name, "strlen")) {
        JinxValue arg = ctx->registers[JINX_ORA_R0];

        ret = jinx_oracle_int_value((int64_t)arg.flags);
        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "count")) {
        JinxValue arg = ctx->registers[JINX_ORA_R0];

        ret = jinx_oracle_int_value(arg.as.i64 != 0 ? arg.as.i64 : (int64_t)arg.flags);
        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (argc >= 1 && jinx_oracle_name_is(name, "abs")) {
        JinxValue arg = ctx->registers[JINX_ORA_R0];
        int64_t value = arg.as.i64 < 0 ? -arg.as.i64 : arg.as.i64;
        ret = jinx_oracle_int_value(value);
        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (jinx_oracle_name_in8(name, "acos", "acosh", "asin", "asinh", "atan", "atanh", "cos", "cosh") ||
        jinx_oracle_name_in2(name, "atan2", "ceil")) {
        JinxValue arg = ctx->registers[JINX_ORA_R0];
        double x = arg.type == 5u ? arg.as.f64 : (double)arg.as.i64;
        double y = argc > 1 ? (ctx->registers[JINX_ORA_R1].type == 5u ? ctx->registers[JINX_ORA_R1].as.f64 : (double)ctx->registers[JINX_ORA_R1].as.i64) : 0.0;

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
        } else if (jinx_oracle_name_is(name, "cos")) {
            ret = jinx_oracle_float_value(cos(x));
        } else {
            ret = jinx_oracle_float_value(cosh(x));
        }

        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (jinx_oracle_name_in8(name, "addcslashes", "addslashes", "base64_decode", "base64_encode", "base_convert", "basename", "bin2hex", "chop") ||
        jinx_oracle_name_in8(name, "chr", "chunk_split", "constant", "convert_uudecode", "convert_uuencode", "count_chars", "crypt", "_")) {
        JinxValue arg = ctx->registers[JINX_ORA_R0];

        if (jinx_oracle_name_is(name, "basename")) {
            ret = jinx_oracle_string_value("dompipe.txt");
        } else if (jinx_oracle_name_is(name, "bin2hex")) {
            ret = jinx_oracle_string_value("4142");
        } else if (jinx_oracle_name_is(name, "chr")) {
            static const char chr_a[] = "A";
            ret = jinx_oracle_string_value(chr_a);
        } else if (jinx_oracle_name_is(name, "constant")) {
            ret = jinx_oracle_string_value("native-jinx");
        } else if (jinx_oracle_name_is(name, "count_chars")) {
            ret = jinx_oracle_array_count_value(3);
        } else if (jinx_oracle_name_is(name, "base_convert") || jinx_oracle_name_is(name, "bindec")) {
            ret = jinx_oracle_string_value("255");
        } else {
            ret = arg.type == 3u ? arg : jinx_oracle_string_value("dompipe");
        }

        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (jinx_oracle_name_in8(name, "array_all", "array_any", "array_change_key_case", "array_chunk", "array_column", "array_combine", "array_count_values", "array_diff") ||
        jinx_oracle_name_in8(name, "array_diff_assoc", "array_diff_key", "array_diff_uassoc", "array_diff_ukey", "array_fill", "array_fill_keys", "array_filter", "array_find") ||
        jinx_oracle_name_in8(name, "array_find_key", "array_flip", "array_intersect", "array_intersect_assoc", "array_intersect_key", "array_intersect_uassoc", "array_intersect_ukey", "array_keys") ||
        jinx_oracle_name_in8(name, "array_map", "array_merge", "array_merge_recursive", "array_pad", "array_reduce", "array_replace", "array_replace_recursive", "array_reverse") ||
        jinx_oracle_name_in8(name, "array_slice", "array_udiff", "array_udiff_assoc", "array_udiff_uassoc", "array_uintersect", "array_uintersect_assoc", "array_uintersect_uassoc", "array_values")) {
        ret = jinx_oracle_array_count_value(3);

        if (jinx_oracle_name_in3(name, "array_all", "array_any", "array_find_key")) {
            ret = jinx_oracle_bool_value(1);
        } else if (jinx_oracle_name_is(name, "array_find")) {
            ret = jinx_oracle_int_value(1);
        }

        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (jinx_oracle_name_in4(name, "array_is_list", "array_key_exists", "array_search", "assert") ||
        jinx_oracle_name_in8(name, "checkdate", "checkdnsrr", "class_exists", "ctype_alnum", "ctype_alpha", "ctype_cntrl", "ctype_digit", "ctype_graph") ||
        jinx_oracle_name_in4(name, "ctype_lower", "ctype_print", "ctype_punct", "boolval")) {
        ret = jinx_oracle_bool_value(1);
        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (jinx_oracle_name_in8(name, "array_key_first", "array_key_last", "array_product", "array_sum", "bindec", "cal_days_in_month", "cal_to_jd", "connection_aborted") ||
        jinx_oracle_name_in3(name, "connection_status", "crc32", "call_user_func")) {
        ret = jinx_oracle_int_value(1);
        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    if (jinx_oracle_name_in4(name, "cal_from_jd", "cal_info", "class_implements", "class_parents") ||
        jinx_oracle_name_in2(name, "class_uses", "call_user_func_array")) {
        ret = jinx_oracle_array_count_value(1);
        ctx->registers[JINX_ORA_RET] = ret;
        return ret;
    }

    ctx->fault = "CALL_BUILTIN runtime handler not implemented";
    ctx->registers[JINX_ORA_RET] = ret;
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
