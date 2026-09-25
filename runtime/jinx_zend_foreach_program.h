#ifndef JINX_ZEND_FOREACH_PROGRAM_H
#define JINX_ZEND_FOREACH_PROGRAM_H

#include "jinx_zend_foreach_opcode.h"

/*
 * Minimal foreach opcode program runner.
 *
 * This is intentionally small: it proves the control-flow shape needed for
 * lowering PHP foreach before the full PHP parser/compiler exists.
 *
 * A normal foreach program looks like:
 *   FE_RESET
 *   FE_FETCH jump_to_end
 *   FE_BODY
 *   JMP fetch_pc
 *   HALT
 */

typedef enum JinxZendForeachProgramOpCode {
    JINX_ZEND_OP_FE_RESET = 1,
    JINX_ZEND_OP_FE_FETCH = 2,
    JINX_ZEND_OP_FE_BODY = 3,
    JINX_ZEND_OP_JMP = 4,
    JINX_ZEND_OP_HALT = 5
} JinxZendForeachProgramOpCode;

typedef struct JinxZendForeachProgramOp {
    JinxZendForeachProgramOpCode op;
    size_t target;
} JinxZendForeachProgramOp;

typedef struct JinxZendForeachProgramResult {
    int ok;
    size_t executed_ops;
    size_t body_calls;
    size_t final_pc;
    const char *fault;
} JinxZendForeachProgramResult;

typedef struct JinxZendForeachProgramState {
    JinxZendExecutor *executor;
    JinxZendForeachFrame frame;
    JinxZendValue source;
    JinxZendValue key_slot;
    JinxZendValue value_slot;
    JinxZendForeachBody body;
    void *user_data;
    size_t pc;
    size_t body_calls;
    const char *fault;
} JinxZendForeachProgramState;

static inline void jinx_zend_foreach_program_state_init(
    JinxZendForeachProgramState *state,
    JinxZendExecutor *executor,
    JinxZendValue source,
    JinxZendForeachBody body,
    void *user_data
) {
    if (state == 0) {
        return;
    }

    state->executor = executor;
    jinx_zend_fe_frame_init(&state->frame);
    state->source = source;
    state->key_slot = jinx_zend_null();
    state->value_slot = jinx_zend_null();
    state->body = body;
    state->user_data = user_data;
    state->pc = 0u;
    state->body_calls = 0u;
    state->fault = 0;
}

static inline JinxZendForeachProgramResult jinx_zend_foreach_program_result(
    const JinxZendForeachProgramState *state,
    int ok,
    const char *fault
) {
    JinxZendForeachProgramResult result;
    result.ok = ok;
    result.executed_ops = state != 0 && state->executor != 0 ? state->executor->executed_ops : 0u;
    result.body_calls = state != 0 ? state->body_calls : 0u;
    result.final_pc = state != 0 ? state->pc : 0u;
    result.fault = fault;
    return result;
}

static inline JinxZendForeachProgramResult jinx_zend_foreach_program_run(
    JinxZendForeachProgramState *state,
    const JinxZendForeachProgramOp *program,
    size_t program_len
) {
    size_t guard = 0u;

    if (state == 0 || state->executor == 0 || program == 0 || program_len == 0u) {
        return jinx_zend_foreach_program_result(state, 0, "invalid foreach program");
    }

    while (state->pc < program_len) {
        const JinxZendForeachProgramOp *op;

        if (++guard > program_len * 1024u) {
            state->fault = "foreach program guard tripped";
            return jinx_zend_foreach_program_result(state, 0, state->fault);
        }

        op = &program[state->pc];
        switch (op->op) {
            case JINX_ZEND_OP_FE_RESET: {
                JinxZendForeachOpcodeResult reset = jinx_zend_fe_reset(&state->frame, state->source);
                if (reset != JINX_ZEND_FE_OK) {
                    state->fault = "FE_RESET type error";
                    return jinx_zend_foreach_program_result(state, 0, state->fault);
                }
                state->executor->executed_ops++;
                state->pc++;
                break;
            }

            case JINX_ZEND_OP_FE_FETCH: {
                JinxZendForeachOpcodeResult fetched = jinx_zend_fe_fetch(
                    &state->frame,
                    &state->key_slot,
                    &state->value_slot
                );
                state->executor->executed_ops++;
                if (fetched == JINX_ZEND_FE_DONE) {
                    if (op->target >= program_len) {
                        state->fault = "FE_FETCH target out of range";
                        return jinx_zend_foreach_program_result(state, 0, state->fault);
                    }
                    state->pc = op->target;
                } else if (fetched == JINX_ZEND_FE_OK) {
                    state->pc++;
                } else {
                    state->fault = "FE_FETCH type error";
                    return jinx_zend_foreach_program_result(state, 0, state->fault);
                }
                break;
            }

            case JINX_ZEND_OP_FE_BODY:
                if (state->body == 0) {
                    state->fault = "FE_BODY missing callback";
                    return jinx_zend_foreach_program_result(state, 0, state->fault);
                }
                state->executor->executed_ops++;
                state->body_calls++;
                if (!state->body(state->executor, &state->frame.current, state->user_data)) {
                    return jinx_zend_foreach_program_result(state, 1, 0);
                }
                state->pc++;
                break;

            case JINX_ZEND_OP_JMP:
                if (op->target >= program_len) {
                    state->fault = "JMP target out of range";
                    return jinx_zend_foreach_program_result(state, 0, state->fault);
                }
                state->executor->executed_ops++;
                state->pc = op->target;
                break;

            case JINX_ZEND_OP_HALT:
                state->executor->executed_ops++;
                return jinx_zend_foreach_program_result(state, 1, 0);

            default:
                state->fault = "unknown foreach opcode";
                return jinx_zend_foreach_program_result(state, 0, state->fault);
        }
    }

    return jinx_zend_foreach_program_result(state, 1, 0);
}

#endif /* JINX_ZEND_FOREACH_PROGRAM_H */
