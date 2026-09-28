#include "jinx_oracle_frame_context.h"

static _Thread_local JinxZendCallFrame *jinx_oracle_caller_frame = NULL;
static _Thread_local JinxZendExecutor *jinx_oracle_executor = NULL;

void jinx_oracle_set_caller_frame(JinxZendCallFrame *frame) {
    jinx_oracle_caller_frame = frame;
}

JinxZendCallFrame *jinx_oracle_get_caller_frame(void) {
    return jinx_oracle_caller_frame;
}

void jinx_oracle_set_executor(JinxZendExecutor *executor) {
    jinx_oracle_executor = executor;
}

JinxZendExecutor *jinx_oracle_get_executor(void) {
    return jinx_oracle_executor;
}
