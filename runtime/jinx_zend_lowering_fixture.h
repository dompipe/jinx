#ifndef JINX_ZEND_LOWERING_FIXTURE_H
#define JINX_ZEND_LOWERING_FIXTURE_H

#include "jinx_zend_opcode_vm.h"

/*
 * Tiny PHP-shaped lowering fixtures for the combined Zend opcode VM.
 *
 * These helpers do not parse PHP yet. They emit stable JinxZendVmOp streams for
 * compiler-shaped constructs so parser/AST lowering can target the same VM
 * structure next:
 *   foreach ($array as $key => $value) { $object->$method($value); }
 *   try { throw $throwable; } catch ($class $caught) { clear; }
 */

#define JINX_ZEND_LOWER_FOREACH_ARRAY_REG 0
#define JINX_ZEND_LOWER_FOREACH_OBJECT_REG 1
#define JINX_ZEND_LOWER_FOREACH_KEY_REG 2
#define JINX_ZEND_LOWER_FOREACH_VALUE_REG 3
#define JINX_ZEND_LOWER_FOREACH_RETURN_REG 4

#define JINX_ZEND_LOWER_THROW_THROWABLE_REG 0
#define JINX_ZEND_LOWER_THROW_CAUGHT_REG 1

static inline size_t jinx_zend_lower_foreach_method_fixture(
    JinxZendVmOp *ops,
    size_t capacity,
    JinxZendValue array_value,
    JinxZendValue object_value,
    const char *method_name
) {
    if (ops == 0 || capacity < 7u || method_name == 0) {
        return 0u;
    }

    ops[0].op = JINX_ZEND_VM_LOAD_CONST;
    ops[0].dst = JINX_ZEND_LOWER_FOREACH_ARRAY_REG;
    ops[0].value = array_value;

    ops[1].op = JINX_ZEND_VM_LOAD_CONST;
    ops[1].dst = JINX_ZEND_LOWER_FOREACH_OBJECT_REG;
    ops[1].value = object_value;

    ops[2].op = JINX_ZEND_VM_FE_RESET;
    ops[2].src = JINX_ZEND_LOWER_FOREACH_ARRAY_REG;

    ops[3].op = JINX_ZEND_VM_FE_FETCH;
    ops[3].key_dst = JINX_ZEND_LOWER_FOREACH_KEY_REG;
    ops[3].value_dst = JINX_ZEND_LOWER_FOREACH_VALUE_REG;
    ops[3].target = 6u;

    ops[4].op = JINX_ZEND_VM_METHOD_CALL;
    ops[4].dst = JINX_ZEND_LOWER_FOREACH_RETURN_REG;
    ops[4].src = JINX_ZEND_LOWER_FOREACH_OBJECT_REG;
    ops[4].arg_start = JINX_ZEND_LOWER_FOREACH_VALUE_REG;
    ops[4].argc = 1u;
    ops[4].name = method_name;

    ops[5].op = JINX_ZEND_VM_JMP;
    ops[5].target = 3u;

    ops[6].op = JINX_ZEND_VM_HALT;

    return 7u;
}

static inline size_t jinx_zend_lower_throw_catch_fixture(
    JinxZendVmOp *ops,
    size_t capacity,
    JinxZendValue throwable_value,
    const char *catch_class_name,
    const char *throw_file
) {
    if (ops == 0 || capacity < 5u) {
        return 0u;
    }

    ops[0].op = JINX_ZEND_VM_LOAD_CONST;
    ops[0].dst = JINX_ZEND_LOWER_THROW_THROWABLE_REG;
    ops[0].value = throwable_value;

    ops[1].op = JINX_ZEND_VM_THROW;
    ops[1].src = JINX_ZEND_LOWER_THROW_THROWABLE_REG;
    ops[1].name = throw_file;

    ops[2].op = JINX_ZEND_VM_CATCH;
    ops[2].dst = JINX_ZEND_LOWER_THROW_CAUGHT_REG;
    ops[2].name = catch_class_name;
    ops[2].target = 4u;

    ops[3].op = JINX_ZEND_VM_CLEAR_EXCEPTION;

    ops[4].op = JINX_ZEND_VM_HALT;

    return 5u;
}

#endif /* JINX_ZEND_LOWERING_FIXTURE_H */
