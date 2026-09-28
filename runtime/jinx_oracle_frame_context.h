#ifndef JINX_ORACLE_FRAME_CONTEXT_H
#define JINX_ORACLE_FRAME_CONTEXT_H

#include "jinx_zend_engine.h"

void jinx_oracle_set_caller_frame(JinxZendCallFrame *frame);
JinxZendCallFrame *jinx_oracle_get_caller_frame(void);

#endif
