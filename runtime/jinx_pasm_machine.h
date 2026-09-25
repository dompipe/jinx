#ifndef JINX_PASM_MACHINE_H
#define JINX_PASM_MACHINE_H

#include "jinx_builtin_dispatch.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef enum JinxPasmOpCode {
    JINX_PASM_PUSH_VALUE = 1,
    JINX_PASM_CALL_BUILTIN = 2,
    JINX_PASM_ADD = 3,
    JINX_PASM_STORE_LOCAL = 4,
    JINX_PASM_LOAD_LOCAL = 5,
    JINX_PASM_RETURN_VALUE = 6,
    JINX_PASM_HALT = 7
} JinxPasmOpCode;

typedef struct JinxPasmOp {
    JinxPasmOpCode op;
    const char *name;
    JinxValue value;
    uint32_t argc;
} JinxPasmOp;

typedef struct JinxPasmLocal {
    const char *name;
    JinxValue value;
    int initialized;
} JinxPasmLocal;

typedef struct JinxPasmMachine {
    JinxValue stack[256];
    uint32_t sp;

    JinxPasmLocal locals[256];
    uint32_t local_count;

    const char *fault;
} JinxPasmMachine;

void jinx_pasm_machine_init(JinxPasmMachine *machine);

int jinx_pasm_push(JinxPasmMachine *machine, JinxValue value);

int jinx_pasm_call_builtin(
    JinxPasmMachine *machine,
    const char *name,
    uint32_t argc
);

int jinx_pasm_add(JinxPasmMachine *machine);

int jinx_pasm_store_local(JinxPasmMachine *machine, const char *name);

int jinx_pasm_load_local(JinxPasmMachine *machine, const char *name);

int jinx_pasm_return_value(JinxPasmMachine *machine, JinxValue *out);

int jinx_pasm_run(
    JinxPasmMachine *machine,
    const JinxPasmOp *program,
    JinxValue *out
);

#ifdef __cplusplus
}
#endif

#endif
