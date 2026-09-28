#ifndef JINX_ORACLE_METHOD_DISPATCH_H
#define JINX_ORACLE_METHOD_DISPATCH_H

#include "jinx_oracle_asm_context.h"
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

JinxValue jinx_call_method_through_oracle_checked(
    const char *name,
    JinxValue receiver,
    JinxValue *args,
    size_t argc,
    int *ok
);

JinxValue jinx_call_method_through_oracle(
    const char *name,
    JinxValue receiver,
    JinxValue *args,
    size_t argc
);

#ifdef __cplusplus
}
#endif

#endif
