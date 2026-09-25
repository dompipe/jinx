#ifndef JINX_ORACLE_ASM_CONTEXT_BRIDGE_H
#define JINX_ORACLE_ASM_CONTEXT_BRIDGE_H

/*
 * Bridge header.
 *
 * The generated Oracle runtime owns:
 *   JinxValue
 *   JinxOracleAsmContext
 *   JINX_ORA_R*
 *   JINX_ORA_LOAD_ARG / PUSH_ARG / CALL_BUILTIN / MOV
 *
 * Do not redefine those here.
 */

#include "../build/oracle-asm/jinx_oracle_asm_runtime.h"

#ifdef __cplusplus
extern "C" {
#endif

void jinx_ora_context_init(
    JinxOracleAsmContext *ctx,
    JinxValue *args,
    uint32_t argc
);

JinxValue jinx_value_null(void);
JinxValue jinx_value_int(int64_t value);
JinxValue jinx_value_bool(int value);
JinxValue jinx_value_string(const char *ptr, uint32_t len);
JinxValue jinx_value_array_count(uint32_t count);

#ifdef __cplusplus
}
#endif

#endif
