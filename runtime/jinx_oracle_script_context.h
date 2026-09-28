#ifndef JINX_ORACLE_SCRIPT_CONTEXT_H
#define JINX_ORACLE_SCRIPT_CONTEXT_H

#include <stddef.h>

void jinx_oracle_script_context_clear(void);
int jinx_oracle_script_context_set_main(const char *path);
int jinx_oracle_script_context_add_include(const char *path);
const char *jinx_oracle_script_context_main(void);
size_t jinx_oracle_script_context_count(void);
const char *jinx_oracle_script_context_at(size_t index);

#endif
