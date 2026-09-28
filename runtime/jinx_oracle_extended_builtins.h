#ifndef JINX_ORACLE_EXTENDED_BUILTINS_H
#define JINX_ORACLE_EXTENDED_BUILTINS_H

#include "jinx_oracle_asm_context.h"
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

JinxValue jinx_oracle_extended_builtin_with_context(
    JinxOracleAsmContext *ctx,
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
);

JinxValue jinx_oracle_extended_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
);

/* CLI/audit fixtures for stateful native values that cannot be expressed
 * as scalar typed arguments. */
JinxValue jinx_oracle_extended_fixture(const char *spec);

/* Shared mutable date/time runtime state for modular native date backends. */
const char *jinx_oracle_extended_default_timezone(void);

#ifdef __cplusplus
}
#endif

#endif
