#include "jinx_zend_engine.h"

#include <stdlib.h>
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
    string.flags = JINX_ZEND_STRING_INTERNED;
    string.len = len;
    string.capacity = len;
    string.bytes = (char *)bytes;
    return string;
}

JinxZendString *jinx_zend_string_new(const char *bytes, size_t len) {
    JinxZendString *string = (JinxZendString *)calloc(1, sizeof(JinxZendString));
    if (string == 0) {
        return 0;
    }

    string->bytes = (char *)calloc(len + 1u, sizeof(char));
    if (string->bytes == 0) {
        free(string);
        return 0;
    }

    if (bytes != 0 && len != 0) {
        memcpy(string->bytes, bytes, len);
    }

    string->refcount = 1;
    string->flags = JINX_ZEND_STRING_OWNED;
    string->len = len;
    string->capacity = len;
    string->bytes[len] = '\0';
    return string;
}

JinxZendString *jinx_zend_string_retain(JinxZendString *string) {
    if (string != 0 && (string->flags & JINX_ZEND_STRING_INTERNED) == 0) {
        string->refcount++;
    }

    return string;
}

void jinx_zend_string_release(JinxZendString *string) {
    if (string == 0 || (string->flags & JINX_ZEND_STRING_INTERNED) != 0) {
        return;
    }

    if (string->refcount > 1u) {
        string->refcount--;
        return;
    }

    if ((string->flags & JINX_ZEND_STRING_OWNED) != 0) {
        free(string->bytes);
    }

    free(string);
}

JinxZendString *jinx_zend_string_separate(JinxZendString **slot) {
    JinxZendString *string;
    JinxZendString *copy;

    if (slot == 0 || *slot == 0) {
        return 0;
    }

    string = *slot;
    if ((string->flags & JINX_ZEND_STRING_INTERNED) == 0 && string->refcount == 1u &&
        (string->flags & JINX_ZEND_STRING_OWNED) != 0) {
        return string;
    }

    copy = jinx_zend_string_new(string->bytes, string->len);
    if (copy == 0) {
        return 0;
    }

    jinx_zend_string_release(string);
    *slot = copy;
    return copy;
}

int jinx_zend_string_set_byte(JinxZendString **slot, size_t offset, char byte) {
    JinxZendString *string = jinx_zend_string_separate(slot);
    if (string == 0 || offset >= string->len || string->bytes == 0) {
        return 0;
    }

    string->bytes[offset] = byte;
    return 1;
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
        "Native JinxZendValue exists; next step is zval copy/destruct helpers for arrays, objects, and references."
    },
    {
        "zend_string",
        "Zend/zend_string.h, Zend/zend_string.c",
        "oracle-sm/zend/string.osm",
        "build/oracle-asm/zend/string.oracle_asm.h",
        "runtime/pasm/zend/string.pasm",
        "started",
        "Owned strings, borrowed views, retain/release, and copy-on-write separation exist. Next: interned-string table and hash cache."
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
    JinxZendString view = jinx_zend_string_view("oracle", 6);
    JinxZendString *owned = jinx_zend_string_new("dompipe", 7);
    JinxZendString *alias;
    JinxZendArray array = jinx_zend_array_count_view(4);
    JinxZendValue result;
    int ok;

    if (owned == 0) {
        return 0;
    }

    alias = jinx_zend_string_retain(owned);
    if (alias == 0 || owned->refcount != 2u) {
        jinx_zend_string_release(owned);
        return 0;
    }

    ok = jinx_zend_string_set_byte(&alias, 0, 'J');
    if (!ok || alias == owned || alias->refcount != 1u || owned->refcount != 1u ||
        alias->bytes[0] != 'J' || owned->bytes[0] != 'd') {
        jinx_zend_string_release(alias);
        jinx_zend_string_release(owned);
        return 0;
    }

    jinx_zend_executor_init(&executor);
    args[0] = jinx_zend_string_value(&view);
    args[1] = jinx_zend_array_value(&array);

    jinx_zend_frame_enter(&executor, &frame, "zend-smoke", args, 2);
    result = jinx_zend_frame_leave(&executor, jinx_zend_long((int64_t)(view.len + array.count + alias->len)));

    ok = executor.current_frame == 0 && executor.executed_ops == 2 &&
        result.type == JINX_ZEND_LONG && result.value.lval == 17;

    jinx_zend_string_release(alias);
    jinx_zend_string_release(owned);
    return ok;
}
