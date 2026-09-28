#include "jinx_oracle_method_dispatch.h"
#include "jinx_builtin_dispatch.h"

JinxValue jinx_call_method_through_oracle_checked(
    const char *name,
    JinxValue receiver,
    JinxValue *args,
    size_t argc,
    int *ok
) {
    JinxOracleWrapper wrapper;
    JinxOracleAsmContext ctx;
    JinxValue result;
    uint32_t required_args = 0u;
    uint32_t total_args = 0u;
    int variadic = 0;

    if (ok != NULL) *ok = 0;
    if (name == NULL || argc > 64u || (argc != 0u && args == NULL)) {
        return jinx_value_null();
    }
    if (!jinx_lookup_oracle_arity(name, &required_args, &total_args, &variadic) ||
        argc < required_args || (!variadic && argc > total_args)) {
        return jinx_value_null();
    }

    wrapper = jinx_lookup_oracle_wrapper(name);
    if (wrapper == NULL) return jinx_value_null();

    jinx_ora_context_init(&ctx, args, (uint32_t)argc);
    ctx.method_receiver = receiver;
    ctx.method_receiver_valid = 1u;
    result = wrapper(&ctx);
    if (ctx.fault != NULL) return jinx_value_null();

    if (ok != NULL) *ok = 1;
    return result;
}

JinxValue jinx_call_method_through_oracle(
    const char *name,
    JinxValue receiver,
    JinxValue *args,
    size_t argc
) {
    int ok = 0;
    JinxValue result = jinx_call_method_through_oracle_checked(
        name, receiver, args, argc, &ok
    );
    return ok ? result : jinx_value_null();
}
