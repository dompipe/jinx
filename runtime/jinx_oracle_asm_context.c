#include "jinx_oracle_asm_context.h"

#include <string.h>

#ifndef JINX_VALUE_NULL
#define JINX_VALUE_NULL 0u
#endif

#ifndef JINX_VALUE_INT
#define JINX_VALUE_INT 1u
#endif

#ifndef JINX_VALUE_BOOL
#define JINX_VALUE_BOOL 2u
#endif

#ifndef JINX_VALUE_STRING
#define JINX_VALUE_STRING 3u
#endif

#ifndef JINX_VALUE_ARRAY
#define JINX_VALUE_ARRAY 4u
#endif

void jinx_ora_context_init(
    JinxOracleAsmContext *ctx,
    JinxValue *args,
    uint32_t argc
) {
    memset(ctx, 0, sizeof(*ctx));
    ctx->argv = args;
    ctx->argc = argc;
    ctx->fault = NULL;
}

JinxValue jinx_value_null(void) {
    JinxValue v;
    memset(&v, 0, sizeof(v));
    v.type = JINX_VALUE_NULL;
    v.flags = 0;
    return v;
}

JinxValue jinx_value_int(int64_t value) {
    JinxValue v = jinx_value_null();
    v.type = JINX_VALUE_INT;
    v.as.i64 = value;
    return v;
}

JinxValue jinx_value_bool(int value) {
    JinxValue v = jinx_value_null();
    v.type = JINX_VALUE_BOOL;
    v.as.i64 = value ? 1 : 0;
    return v;
}

JinxValue jinx_value_string(const char *ptr, uint32_t len) {
    JinxValue v = jinx_value_null();
    v.type = JINX_VALUE_STRING;
    v.as.ptr = (void *) ptr;
    v.flags = len;
    return v;
}

JinxValue jinx_value_array_count(uint32_t count) {
    JinxValue v = jinx_value_null();
    v.type = JINX_VALUE_ARRAY;
    v.as.i64 = (int64_t) count;
    return v;
}
