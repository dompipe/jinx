#ifndef JINX_BUILTIN_DISPATCH_H
#define JINX_BUILTIN_DISPATCH_H

#include "jinx_oracle_asm_context.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef struct JinxZendCallFrame JinxZendCallFrame;

typedef JinxValue (*JinxOracleWrapper)(JinxOracleAsmContext *ctx);

typedef struct JinxOracleDispatchEntry {
    const char *name;
    JinxOracleWrapper wrapper;
    uint32_t required_args;
    uint32_t total_args;
    int variadic;
} JinxOracleDispatchEntry;

JinxOracleWrapper jinx_lookup_oracle_wrapper(const char *name);
int jinx_lookup_oracle_arity(
    const char *name,
    uint32_t *required_args,
    uint32_t *total_args,
    int *variadic
);

void jinx_oracle_set_caller_frame(JinxZendCallFrame *frame);
JinxZendCallFrame *jinx_oracle_get_caller_frame(void);

JinxValue jinx_call_builtin_through_oracle_checked(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *ok
);

JinxValue jinx_call_builtin_through_oracle(
    const char *name,
    JinxValue *args,
    size_t argc
);

#ifdef __cplusplus
}
#endif

#endif
