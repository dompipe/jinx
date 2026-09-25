#include "jinx_pasm_machine.h"

#include <stddef.h>
#include <string.h>

void jinx_pasm_machine_init(JinxPasmMachine *machine) {
    memset(machine, 0, sizeof(*machine));
    machine->fault = NULL;
}

int jinx_pasm_push(JinxPasmMachine *machine, JinxValue value) {
    if (machine->sp >= 256) {
        machine->fault = "PASM stack overflow";
        return 0;
    }

    machine->stack[machine->sp++] = value;
    return 1;
}

int jinx_pasm_call_builtin(
    JinxPasmMachine *machine,
    const char *name,
    uint32_t argc
) {
    if (argc > machine->sp) {
        machine->fault = "PASM CALL_BUILTIN stack underflow";
        return 0;
    }

    JinxValue *args = &machine->stack[machine->sp - argc];

    JinxValue result = jinx_call_builtin_through_oracle(
        name,
        args,
        argc
    );

    machine->sp -= argc;

    return jinx_pasm_push(machine, result);
}



static int jinx_pasm_find_local(JinxPasmMachine *machine, const char *name) {
    for (uint32_t i = 0; i < machine->local_count; i++) {
        if (machine->locals[i].name != NULL &&
            name != NULL &&
            strcmp(machine->locals[i].name, name) == 0) {
            return (int) i;
        }
    }

    return -1;
}

static int jinx_pasm_ensure_local(JinxPasmMachine *machine, const char *name) {
    int existing = jinx_pasm_find_local(machine, name);

    if (existing >= 0) {
        return existing;
    }

    if (machine->local_count >= 256) {
        machine->fault = "PASM local table overflow";
        return -1;
    }

    uint32_t index = machine->local_count++;
    machine->locals[index].name = name;
    machine->locals[index].value = jinx_value_null();
    machine->locals[index].initialized = 0;

    return (int) index;
}

int jinx_pasm_store_local(JinxPasmMachine *machine, const char *name) {
    if (machine->sp == 0) {
        machine->fault = "PASM STORE_LOCAL stack underflow";
        return 0;
    }

    int index = jinx_pasm_ensure_local(machine, name);

    if (index < 0) {
        return 0;
    }

    machine->locals[index].value = machine->stack[--machine->sp];
    machine->locals[index].initialized = 1;

    return 1;
}

int jinx_pasm_load_local(JinxPasmMachine *machine, const char *name) {
    int index = jinx_pasm_find_local(machine, name);

    if (index < 0 || !machine->locals[index].initialized) {
        machine->fault = "PASM LOAD_LOCAL uninitialized local";
        return 0;
    }

    return jinx_pasm_push(machine, machine->locals[index].value);
}

int jinx_pasm_add(JinxPasmMachine *machine) {
    if (machine->sp < 2) {
        machine->fault = "PASM ADD stack underflow";
        return 0;
    }

    JinxValue rhs = machine->stack[--machine->sp];
    JinxValue lhs = machine->stack[--machine->sp];

    /*
     * First arithmetic target: integer addition.
     * PHP-compatible coercion can be added after the execution ladder exists.
     */
    JinxValue result = jinx_value_int(lhs.as.i64 + rhs.as.i64);

    return jinx_pasm_push(machine, result);
}

int jinx_pasm_return_value(JinxPasmMachine *machine, JinxValue *out) {
    if (machine->sp == 0) {
        machine->fault = "PASM RETURN_VALUE with empty stack";
        return 0;
    }

    *out = machine->stack[machine->sp - 1];
    return 1;
}

int jinx_pasm_run(
    JinxPasmMachine *machine,
    const JinxPasmOp *program,
    JinxValue *out
) {
    for (uint32_t pc = 0;; pc++) {
        const JinxPasmOp *op = &program[pc];

        switch (op->op) {
            case JINX_PASM_PUSH_VALUE:
                if (!jinx_pasm_push(machine, op->value)) {
                    return 0;
                }
                break;

            case JINX_PASM_CALL_BUILTIN:
                if (!jinx_pasm_call_builtin(machine, op->name, op->argc)) {
                    return 0;
                }
                break;

            case JINX_PASM_ADD:
                if (!jinx_pasm_add(machine)) {
                    return 0;
                }
                break;

            case JINX_PASM_STORE_LOCAL:
                if (!jinx_pasm_store_local(machine, op->name)) {
                    return 0;
                }
                break;

            case JINX_PASM_LOAD_LOCAL:
                if (!jinx_pasm_load_local(machine, op->name)) {
                    return 0;
                }
                break;

            case JINX_PASM_RETURN_VALUE:
                return jinx_pasm_return_value(machine, out);

            case JINX_PASM_HALT:
                if (machine->sp == 0) {
                    machine->fault = "PASM halted with empty stack";
                    return 0;
                }

                *out = machine->stack[machine->sp - 1];
                return 1;

            default:
                machine->fault = "Unknown PASM opcode";
                return 0;
        }
    }
}
