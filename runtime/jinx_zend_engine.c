#include "jinx_zend_engine.h"

#include <string.h>

JinxZendValue jinx_zend_null(void) {
    JinxZendValue value;
    value.type = JINX_ZEND_NULL;
    value.flags = 0;
    value.value.ptr = 0;
    return value;
}

JinxZendValue jinx_zend_bool(int v) {
    JinxZendValue value = jinx_zend_null();
    value.type = v ? JINX_ZEND_TRUE : JINX_ZEND_FALSE;
    value.value.lval = v ? 1 : 0;
    return value;
}

JinxZendValue jinx_zend_long(int64_t v) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_LONG;
    value.value.lval = v;
    return value;
}

JinxZendValue jinx_zend_double(double v) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_DOUBLE;
    value.value.dval = v;
    return value;
}

JinxZendString jinx_zend_string_view(const char *bytes, size_t len) {
    JinxZendString string;
    string.refcount = 1;
    string.flags = 0;
    string.len = len;
    string.bytes = bytes;
    return string;
}

JinxZendValue jinx_zend_string_value(JinxZendString *string) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_STRING;
    value.value.str = string;
    return value;
}

JinxZendArray jinx_zend_array_count_view(size_t count) {
    JinxZendArray array;
    array.refcount = 1;
    array.flags = 0;
    array.count = count;
    array.capacity = count;
    array.buckets = 0;
    return array;
}

JinxZendValue jinx_zend_array_value(JinxZendArray *array) {
    JinxZendValue value = jinx_zend_null();
    value.type = JINX_ZEND_ARRAY;
    value.value.array = array;
    return value;
}

void jinx_zend_executor_init(JinxZendExecutor *executor) {
    if (executor == 0) {
        return;
    }

    executor->current_frame = 0;
    executor->last_error = 0;
    executor->error_level = 0;
    executor->executed_ops = 0;
}

void jinx_zend_frame_enter(
    JinxZendExecutor *executor,
    JinxZendCallFrame *frame,
    const char *function_name,
    JinxZendValue *args,
    size_t argc
) {
    if (executor == 0 || frame == 0) {
        return;
    }

    frame->function_name = function_name;
    frame->scope_name = 0;
    frame->args = args;
    frame->argc = argc;
    frame->return_value = jinx_zend_null();
    frame->previous = executor->current_frame;
    executor->current_frame = frame;
    executor->executed_ops++;
}

JinxZendValue jinx_zend_frame_leave(JinxZendExecutor *executor, JinxZendValue return_value) {
    if (executor != 0 && executor->current_frame != 0) {
        executor->current_frame->return_value = return_value;
        executor->current_frame = executor->current_frame->previous;
        executor->executed_ops++;
    }

    return return_value;
}

static const JinxZendModuleFamily jinx_zend_families[] = {
    {
        "zval",
        "Zend/zend_types.h, Zend/zend.h",
        "oracle-sm/zend/zval.osm",
        "build/oracle-asm/zend/zval.oracle_asm.h",
        "runtime/pasm/zend/zval.pasm",
        "started",
        "Native JinxZendValue exists; next step is copy-on-write/refcount semantics."
    },
    {
        "zend_string",
        "Zend/zend_string.h, Zend/zend_string.c",
        "oracle-sm/zend/string.osm",
        "build/oracle-asm/zend/string.oracle_asm.h",
        "runtime/pasm/zend/string.pasm",
        "started",
        "String view exists; next step is owned allocation, interned strings, hash cache, and binary-safe ops."
    },
    {
        "HashTable/zend_array",
        "Zend/zend_hash.h, Zend/zend_hash.c, Zend/zend_array.c",
        "oracle-sm/zend/hash.osm",
        "build/oracle-asm/zend/hash.oracle_asm.h",
        "runtime/pasm/zend/hash.pasm",
        "planned",
        "Array count view exists; real buckets, packed/mixed arrays, insertion order, and COW are next."
    },
    {
        "executor/call-frame",
        "Zend/zend_execute.c, Zend/zend_vm_def.h, Zend/zend_vm_execute.h",
        "oracle-sm/zend/executor.osm",
        "build/oracle-asm/zend/executor.oracle_asm.h",
        "runtime/pasm/zend/executor.pasm",
        "started",
        "Call-frame skeleton exists; next step is opcode lowering and VM dispatch."
    },
    {
        "objects/classes",
        "Zend/zend_object_handlers.c, Zend/zend_objects_API.c, Zend/zend_compile.c",
        "oracle-sm/zend/object.osm",
        "build/oracle-asm/zend/object.oracle_asm.h",
        "runtime/pasm/zend/object.pasm",
        "planned",
        "Object shell exists; class tables, methods, properties, traits, and interfaces are next."
    },
    {
        "errors/exceptions",
        "Zend/zend_exceptions.c, Zend/zend_errors.h",
        "oracle-sm/zend/errors.osm",
        "build/oracle-asm/zend/errors.oracle_asm.h",
        "runtime/pasm/zend/errors.pasm",
        "planned",
        "Error slot exists on executor; warnings, TypeError, ValueError, and exception unwinding are next."
    },
    {
        "compiler/opcodes",
        "Zend/zend_language_parser.y, Zend/zend_compile.c, Zend/zend_vm_def.h",
        "oracle-sm/zend/opcodes.osm",
        "build/oracle-asm/zend/opcodes.oracle_asm.h",
        "runtime/pasm/zend/opcodes.pasm",
        "planned",
        "Required for arbitrary PHP source: parse, lower AST/opcodes, then execute via Oracle/PASM."
    }
};

const JinxZendModuleFamily *jinx_zend_module_families(size_t *count) {
    if (count != 0) {
        *count = sizeof(jinx_zend_families) / sizeof(jinx_zend_families[0]);
    }

    return jinx_zend_families;
}

int jinx_zend_smoke(void) {
    JinxZendExecutor executor;
    JinxZendCallFrame frame;
    JinxZendValue args[2];
    JinxZendString string = jinx_zend_string_view("oracle", 6);
    JinxZendArray array = jinx_zend_array_count_view(4);
    JinxZendValue result;

    jinx_zend_executor_init(&executor);
    args[0] = jinx_zend_string_value(&string);
    args[1] = jinx_zend_array_value(&array);

    jinx_zend_frame_enter(&executor, &frame, "zend-smoke", args, 2);
    result = jinx_zend_frame_leave(&executor, jinx_zend_long((int64_t)(string.len + array.count)));

    return executor.current_frame == 0 && executor.executed_ops == 2 &&
        result.type == JINX_ZEND_LONG && result.value.lval == 10;
}
