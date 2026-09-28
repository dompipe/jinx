#ifndef JINX_ORACLE_HASH_BUILTINS_H
#define JINX_ORACLE_HASH_BUILTINS_H
#include "jinx_oracle_asm_context.h"
#include <stddef.h>
JinxValue jinx_oracle_hash_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
);
#endif
