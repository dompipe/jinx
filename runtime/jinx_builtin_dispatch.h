#ifndef JINX_BUILTIN_DISPATCH_H
#define JINX_BUILTIN_DISPATCH_H

#include "jinx_oracle_asm_context.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef JinxValue (*JinxOracleWrapper)(JinxOracleAsmContext *ctx);

typedef struct JinxOracleDispatchEntry {
    const char *name;
    JinxOracleWrapper wrapper;
} JinxOracleDispatchEntry;

JinxOracleWrapper jinx_lookup_oracle_wrapper(const char *name);

JinxValue jinx_call_builtin_through_oracle(
    const char *name,
    JinxValue *args,
    size_t argc
);

#ifdef __cplusplus
}
#endif

#endif
