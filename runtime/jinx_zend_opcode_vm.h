#ifndef JINX_ZEND_OPCODE_VM_H
#define JINX_ZEND_OPCODE_VM_H

#include "jinx_zend_foreach_opcode.h"
#include "jinx_zend_method_opcode.h"
#include "jinx_zend_throw_opcode.h"

/*
 * Combined minimal opcode VM for the JINX-owned Zend-shaped runtime.
 *
 * This is intentionally small and register-based. It sequences the already
 * proven primitive layers in one instruction stream:
 *   - foreach FE_RESET / FE_FETCH
 *   - method INIT/SEND/DO lowering
 *   - throw / catch / clear-exception lowering
 *   - register copy / assignment slots
 *   - jumps and halt
 *
 * It is not a PHP parser or full Zend VM yet. It is the first combined control
 * flow substrate that later compiler lowering can target.
 */

#define JINX_ZEND_VM_MAX_REGISTERS 16u
#define JINX_ZEND_VM_MAX_ARGS 8u

typedef enum JinxZendVmOpcode {
    JINX_ZEND_VM_LOAD_CONST = 0,
    JINX_ZEND_VM_FE_RESET = 1,
    JINX_ZEND_VM_FE_FETCH = 2,
    JINX_ZEND_VM_METHOD_CALL = 3,
    JINX_ZEND_VM_THROW = 4,
    JINX_ZEND_VM_CATCH = 5,
    JINX_ZEND_VM_CLEAR_EXCEPTION = 6,
    JINX_ZEND_VM_JMP = 7,
    JINX_ZEND_VM_JMP_IF_EXCEPTION = 8,
    JINX_ZEND_VM_HALT = 9,
    JINX_ZEND_VM_COPY = 10
} JinxZendVmOpcode;

typedef enum JinxZendVmResult {
    JINX_ZEND_VM_OK = 0,
    JINX_ZEND_VM_HALTED = 1,
    JINX_ZEND_VM_BAD_REGISTER = 2,
    JINX_ZEND_VM_BAD_JUMP = 3,
    JINX_ZEND_VM_TYPE_ERROR = 4,
    JINX_ZEND_VM_METHOD_ERROR = 5,
    JINX_ZEND_VM_UNCAUGHT_EXCEPTION = 6
} JinxZendVmResult;

typedef struct JinxZendVmOp {
    JinxZendVmOpcode op;
    int dst;
    int src;
    int key_dst;
    int value_dst;
    int arg_start;
    size_t argc;
    size_t target;
    const char *name;
    JinxZendValue value;
} JinxZendVmOp;

typedef struct JinxZendVmState {
    JinxZendValue registers[JINX_ZEND_VM_MAX_REGISTERS];
    JinxZendForeachFrame foreach_frame;
    JinxZendMethodCallFrame method_frame;
    JinxZendCatchFrame catch_frame;
    JinxZendErrorState error_state;
    const JinxZendClassTable *class_table;
    size_t pc;
    size_t steps;
    int halted;
} JinxZendVmState;

static inline int jinx_zend_vm_reg_ok(int reg) {
    return reg >= 0 && (size_t)reg < JINX_ZEND_VM_MAX_REGISTERS;
}

static inline void jinx_zend_vm_state_init(JinxZendVmState *state, const JinxZendClassTable *class_table) {
    if (state == 0) {
        return;
    }

    for (size_t i = 0; i < JINX_ZEND_VM_MAX_REGISTERS; i++) {
        state->registers[i] = jinx_zend_null();
    }
    jinx_zend_fe_frame_init(&state->foreach_frame);
    jinx_zend_method_frame_init(&state->method_frame);
    jinx_zend_catch_frame_init(&state->catch_frame, 0);
    jinx_zend_error_state_init(&state->error_state);
    state->class_table = class_table;
    state->pc = 0u;
    state->steps = 0u;
    state->halted = 0;
}

static inline JinxZendVmResult jinx_zend_vm_jump(JinxZendVmState *state, size_t target, size_t program_len) {
    if (state == 0 || target >= program_len) {
        return JINX_ZEND_VM_BAD_JUMP;
    }
    state->pc = target;
    return JINX_ZEND_VM_OK;
}

static inline JinxZendVmResult jinx_zend_vm_run(
    JinxZendExecutor *executor,
    JinxZendVmState *state,
    const JinxZendVmOp *program,
    size_t program_len
) {
    if (executor == 0 || state == 0 || program == 0) {
        return JINX_ZEND_VM_TYPE_ERROR;
    }

    while (!state->halted && state->pc < program_len) {
        const JinxZendVmOp *op = &program[state->pc];
        state->steps++;
        executor->executed_ops++;

        switch (op->op) {
            case JINX_ZEND_VM_LOAD_CONST:
                if (!jinx_zend_vm_reg_ok(op->dst)) {
                    return JINX_ZEND_VM_BAD_REGISTER;
                }
                state->registers[op->dst] = op->value;
                state->pc++;
                break;

            case JINX_ZEND_VM_COPY:
                if (!jinx_zend_vm_reg_ok(op->dst) || !jinx_zend_vm_reg_ok(op->src)) {
                    return JINX_ZEND_VM_BAD_REGISTER;
                }
                state->registers[op->dst] = state->registers[op->src];
                state->pc++;
                break;

            case JINX_ZEND_VM_FE_RESET:
                if (!jinx_zend_vm_reg_ok(op->src)) {
                    return JINX_ZEND_VM_BAD_REGISTER;
                }
                if (jinx_zend_fe_reset(&state->foreach_frame, state->registers[op->src]) != JINX_ZEND_FE_OK) {
                    return JINX_ZEND_VM_TYPE_ERROR;
                }
                state->pc++;
                break;

            case JINX_ZEND_VM_FE_FETCH: {
                JinxZendValue key = jinx_zend_null();
                JinxZendValue value = jinx_zend_null();
                JinxZendForeachOpcodeResult fetched;

                if (!jinx_zend_vm_reg_ok(op->key_dst) || !jinx_zend_vm_reg_ok(op->value_dst)) {
                    return JINX_ZEND_VM_BAD_REGISTER;
                }

                fetched = jinx_zend_fe_fetch(&state->foreach_frame, &key, &value);
                if (fetched == JINX_ZEND_FE_DONE) {
                    JinxZendVmResult jumped = jinx_zend_vm_jump(state, op->target, program_len);
                    if (jumped != JINX_ZEND_VM_OK) {
                        return jumped;
                    }
                    break;
                }
                if (fetched != JINX_ZEND_FE_OK) {
                    return JINX_ZEND_VM_TYPE_ERROR;
                }

                state->registers[op->key_dst] = key;
                state->registers[op->value_dst] = value;
                state->pc++;
                break;
            }

            case JINX_ZEND_VM_METHOD_CALL: {
                JinxZendValue args[JINX_ZEND_VM_MAX_ARGS];
                JinxZendValue ret = jinx_zend_null();
                JinxZendMethodOpcodeResult result;

                if (!jinx_zend_vm_reg_ok(op->src) || !jinx_zend_vm_reg_ok(op->dst) || op->argc > JINX_ZEND_VM_MAX_ARGS) {
                    return JINX_ZEND_VM_BAD_REGISTER;
                }
                if (op->argc != 0u && (!jinx_zend_vm_reg_ok(op->arg_start) || !jinx_zend_vm_reg_ok(op->arg_start + (int)op->argc - 1))) {
                    return JINX_ZEND_VM_BAD_REGISTER;
                }

                for (size_t i = 0; i < op->argc; i++) {
                    args[i] = state->registers[op->arg_start + (int)i];
                }

                result = jinx_zend_init_method_call(&state->method_frame, state->registers[op->src], op->name);
                if (result != JINX_ZEND_METHOD_OK) {
                    return JINX_ZEND_VM_METHOD_ERROR;
                }
                result = jinx_zend_send_method_args(&state->method_frame, args, op->argc);
                if (result != JINX_ZEND_METHOD_OK) {
                    return JINX_ZEND_VM_METHOD_ERROR;
                }
                result = jinx_zend_do_method_call(executor, state->class_table, &state->method_frame, &ret);
                if (result != JINX_ZEND_METHOD_OK) {
                    return JINX_ZEND_VM_METHOD_ERROR;
                }

                state->registers[op->dst] = ret;
                state->pc++;
                break;
            }

            case JINX_ZEND_VM_THROW:
                if (!jinx_zend_vm_reg_ok(op->src)) {
                    return JINX_ZEND_VM_BAD_REGISTER;
                }
                if (jinx_zend_throw_value(&state->error_state, executor, state->registers[op->src], op->name, 0u) != JINX_ZEND_THROW_OK) {
                    return JINX_ZEND_VM_TYPE_ERROR;
                }
                state->pc++;
                break;

            case JINX_ZEND_VM_CATCH: {
                JinxZendThrowOpcodeResult caught;
                jinx_zend_catch_frame_init(&state->catch_frame, op->name);
                caught = jinx_zend_catch_exception(&state->error_state, &state->catch_frame);
                if (caught == JINX_ZEND_CATCH_MATCH) {
                    if (jinx_zend_vm_reg_ok(op->dst)) {
                        state->registers[op->dst] = jinx_zend_object_value(state->catch_frame.caught);
                    }
                    state->pc++;
                    break;
                }
                if (caught == JINX_ZEND_CATCH_MISS) {
                    JinxZendVmResult jumped = jinx_zend_vm_jump(state, op->target, program_len);
                    if (jumped != JINX_ZEND_VM_OK) {
                        return jumped;
                    }
                    break;
                }
                return JINX_ZEND_VM_UNCAUGHT_EXCEPTION;
            }

            case JINX_ZEND_VM_CLEAR_EXCEPTION:
                jinx_zend_clear_exception(&state->error_state, executor);
                state->pc++;
                break;

            case JINX_ZEND_VM_JMP: {
                JinxZendVmResult jumped = jinx_zend_vm_jump(state, op->target, program_len);
                if (jumped != JINX_ZEND_VM_OK) {
                    return jumped;
                }
                break;
            }

            case JINX_ZEND_VM_JMP_IF_EXCEPTION:
                if (jinx_zend_error_state_has_throwable(&state->error_state)) {
                    JinxZendVmResult jumped = jinx_zend_vm_jump(state, op->target, program_len);
                    if (jumped != JINX_ZEND_VM_OK) {
                        return jumped;
                    }
                } else {
                    state->pc++;
                }
                break;

            case JINX_ZEND_VM_HALT:
                state->halted = 1;
                state->pc++;
                return JINX_ZEND_VM_HALTED;

            default:
                return JINX_ZEND_VM_TYPE_ERROR;
        }
    }

    return state->halted ? JINX_ZEND_VM_HALTED : JINX_ZEND_VM_OK;
}

#endif /* JINX_ZEND_OPCODE_VM_H */