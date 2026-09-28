#ifndef JINX_ORACLE_BATCH2_BUILTINS_H
#define JINX_ORACLE_BATCH2_BUILTINS_H

#include "jinx_oracle_asm_context.h"
#include <stddef.h>

JinxValue jinx_oracle_batch2_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
);

#endif
