#ifndef JINX_BUILTIN_DISPATCH_H
#define JINX_BUILTIN_DISPATCH_H

#include "jinx_oracle_asm_context.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef struct JinxZendCallFrame JinxZendCallFrame;

typedef JinxValue (*JinxOracleWrapper)(JinxOracleAsmContext *ctx);

typedef uint16_t JinxBuiltinId;

#define JINX_BUILTIN_ID_INVALID ((JinxBuiltinId)0xffffu)
#define JINX_BUILTIN_HOT_ID_LIMIT ((JinxBuiltinId)128u)

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

JinxBuiltinId jinx_resolve_builtin_id(const char *name);
const char *jinx_builtin_name_from_id(JinxBuiltinId id);
size_t jinx_encode_builtin_id(JinxBuiltinId id, uint8_t out[2]);
int jinx_decode_builtin_id(
    const uint8_t *bytes,
    size_t length,
    JinxBuiltinId *id,
    size_t *consumed
);

JinxValue jinx_call_builtin_id_checked(
    JinxBuiltinId id,
    JinxValue *args,
    size_t argc,
    int *ok
);

JinxValue jinx_call_builtin_id(
    JinxBuiltinId id,
    JinxValue *args,
    size_t argc
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
