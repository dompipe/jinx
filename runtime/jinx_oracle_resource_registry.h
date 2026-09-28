#ifndef JINX_ORACLE_RESOURCE_REGISTRY_H
#define JINX_ORACLE_RESOURCE_REGISTRY_H

#include "jinx_oracle_asm_context.h"
#include <stddef.h>
#include <stdint.h>

int64_t jinx_oracle_resource_register(void *ptr, const char *type_name);
void jinx_oracle_resource_unregister(void *ptr);
int64_t jinx_oracle_resource_id(void *ptr);
const char *jinx_oracle_resource_type(void *ptr);
size_t jinx_oracle_resource_count(const char *type_name);
JinxValue jinx_oracle_resource_list_value(const char *type_name);

#endif
