#include "jinx_builtin_dispatch.h"

#include <string.h>

static inline JinxValue jinx_ora_strlen(JinxOracleAsmContext *ctx) {
    JINX_ORA_LOAD_ARG(ctx, JINX_ORA_R0, 0);
    JINX_ORA_PUSH_ARG(ctx, JINX_ORA_R0);
    JINX_ORA_CALL_BUILTIN(ctx, "strlen", 1);
    JINX_ORA_MOV(ctx, JINX_ORA_ACC, JINX_ORA_RET);

    return ctx->reg[JINX_ORA_ACC];
}

static inline JinxValue jinx_ora_count(JinxOracleAsmContext *ctx) {
    JINX_ORA_LOAD_ARG(ctx, JINX_ORA_R0, 0);
    JINX_ORA_PUSH_ARG(ctx, JINX_ORA_R0);
    JINX_ORA_CALL_BUILTIN(ctx, "count", 1);
    JINX_ORA_MOV(ctx, JINX_ORA_ACC, JINX_ORA_RET);

    return ctx->reg[JINX_ORA_ACC];
}

static const JinxOracleDispatchEntry oracle_dispatch_table[] = {
    { "strlen", jinx_ora_strlen },
    { "count", jinx_ora_count },
    { NULL, NULL }
};

JinxOracleWrapper jinx_lookup_oracle_wrapper(const char *name) {
    for (size_t i = 0; oracle_dispatch_table[i].name != NULL; i++) {
        if (strcmp(oracle_dispatch_table[i].name, name) == 0) {
            return oracle_dispatch_table[i].wrapper;
        }
    }

    return NULL;
}

JinxValue jinx_call_builtin_through_oracle_checked(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *ok
) {
    if (ok != NULL) *ok = 0;

    JinxOracleWrapper wrapper = jinx_lookup_oracle_wrapper(name);

    if (wrapper == NULL) {
        return jinx_value_null();
    }

    JinxOracleAsmContext ctx;
    jinx_ora_context_init(&ctx, args, argc);

    JinxValue result = wrapper(&ctx);

    if (ctx.error) {
        return jinx_value_null();
    }

    if (ok != NULL) *ok = 1;
    return result;
}

JinxValue jinx_call_builtin_through_oracle(
    const char *name,
    JinxValue *args,
    size_t argc
) {
    int ok = 0;
    JinxValue result = jinx_call_builtin_through_oracle_checked(name, args, argc, &ok);
    return ok ? result : jinx_value_null();
}
