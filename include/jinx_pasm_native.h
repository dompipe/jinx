#ifndef JINX_PASM_NATIVE_H
#define JINX_PASM_NATIVE_H

#include <stdint.h>
#include <stddef.h>

typedef enum JinxPasmOpcode {
    JINX_PASM_OP_SET_I64 = 1,
    JINX_PASM_OP_ADD = 2,
    JINX_PASM_OP_MUL = 3,
    JINX_PASM_OP_YIELD = 4,
    JINX_PASM_OP_SUB = 5,
    JINX_PASM_OP_END = 255
} JinxPasmOpcode;

typedef enum JinxPasmRegister {
    JINX_PASM_REG_NONE = 0,
    JINX_PASM_REG_ECX = 1,
    JINX_PASM_REG_AH = 2,
    JINX_PASM_REG_RDX = 3
} JinxPasmRegister;

typedef struct JinxPasmCommand {
    uint8_t opcode;
    uint8_t target;
    int64_t immediate;
} JinxPasmCommand;

typedef struct JinxPasmFrame {
    int64_t ecx;
    int64_t ah;
    int64_t rdx;
    int64_t yielded;
    uint8_t yielded_register;
} JinxPasmFrame;

static inline void jinx_pasm_set_register(JinxPasmFrame *frame, uint8_t target, int64_t value) {
    switch (target) {
        case JINX_PASM_REG_ECX: frame->ecx = value; break;
        case JINX_PASM_REG_AH: frame->ah = value; break;
        case JINX_PASM_REG_RDX: frame->rdx = value; break;
        default: break;
    }
}

static inline int64_t jinx_pasm_get_register(const JinxPasmFrame *frame, uint8_t target) {
    switch (target) {
        case JINX_PASM_REG_ECX: return frame->ecx;
        case JINX_PASM_REG_AH: return frame->ah;
        case JINX_PASM_REG_RDX: return frame->rdx;
        default: return 0;
    }
}

static inline int64_t jinx_pasm_run_struct_chain(const JinxPasmCommand *commands, size_t count, JinxPasmFrame *frame) {
    for (size_t i = 0; i < count; i++) {
        const JinxPasmCommand command = commands[i];
        switch (command.opcode) {
            case JINX_PASM_OP_SET_I64:
                jinx_pasm_set_register(frame, command.target, command.immediate);
                break;
            case JINX_PASM_OP_ADD:
                frame->rdx = frame->ecx + frame->ah;
                break;
            case JINX_PASM_OP_MUL:
                frame->ecx *= frame->ah;
                break;
            case JINX_PASM_OP_SUB:
                frame->rdx = frame->ecx - frame->ah;
                break;
            case JINX_PASM_OP_YIELD:
                frame->yielded_register = command.target;
                frame->yielded = jinx_pasm_get_register(frame, command.target);
                break;
            case JINX_PASM_OP_END:
                return frame->yielded;
            default:
                return frame->yielded;
        }
    }
    return frame->yielded;
}

#endif
