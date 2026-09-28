#ifndef JINX_ORACLE_FTP_BUILTINS_H
#define JINX_ORACLE_FTP_BUILTINS_H

#include "jinx_oracle_asm_context.h"
#include <stddef.h>

JinxValue jinx_oracle_ftp_builtin(
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
);

JinxValue jinx_oracle_ftp_builtin_with_context(
    JinxOracleAsmContext *ctx,
    const char *name,
    JinxValue *args,
    size_t argc,
    int *handled
);

#endif
