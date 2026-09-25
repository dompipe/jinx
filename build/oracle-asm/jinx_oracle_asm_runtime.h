#ifndef JINX_ORACLE_ASM_RUNTIME_H
#define JINX_ORACLE_ASM_RUNTIME_H

#include <stdint.h>
#include <stddef.h>

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

static inline JinxValue jinx_oracle_asm_call_builtin(JinxOracleAsmContext *ctx, const char *name, uint32_t argc) {
    (void)name;
    (void)argc;
    /* Placeholder: runtime dispatch table or intrinsic backend. */
    JinxValue ret = jinx_oracle_zero_value();
    if (ctx != NULL) {
        ctx->registers[JINX_ORA_RET] = ret;
    }
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