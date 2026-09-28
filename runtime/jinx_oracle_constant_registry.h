#ifndef JINX_ORACLE_CONSTANT_REGISTRY_H
#define JINX_ORACLE_CONSTANT_REGISTRY_H

#include "jinx_oracle_asm_context.h"
#include "jinx_oracle_zend_array_carrier.h"
#include <stddef.h>

int jinx_oracle_constant_registry_define(const char *name, JinxValue value);
int jinx_oracle_constant_registry_defined(const char *name);
int jinx_oracle_constant_registry_get(const char *name, JinxValue *out);
size_t jinx_oracle_constant_registry_count(void);
int jinx_oracle_constant_registry_append_to_array(JinxZendArray *array);

#endif
