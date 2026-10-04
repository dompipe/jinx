#include "jinx_oracle_native_script.h"
#include "jinx_builtin_dispatch.h"
#include "jinx_oracle_zend_array_carrier.h"
#include <ctype.h>
#include <limits.h>
#include <errno.h>
#include <strings.h>

typedef struct NativeAllocation {
    void *ptr;
    struct NativeAllocation *next;
} NativeAllocation;

typedef struct NativeVariable {
    char *name;
    JinxValue value;
    JinxZendReference *reference;
    struct NativeVariable *next;
} NativeVariable;

typedef struct NativeProperty {
    char *name;
    int type;
    int is_static;
    int initialized;
    int is_private;
    int is_readonly;
    struct NativeClass *owner;
    char *object_type;
    NativeVariable storage;
    struct NativeProperty *next;
} NativeProperty;

typedef struct NativeClass {
    char *name;
    int is_readonly;
    struct NativeClass *parent;
    NativeProperty *properties;
    struct NativeFunction *methods;
    struct NativeClass *next;
} NativeClass;

typedef struct NativeEnumCase {
    char *name;
    JinxValue backing;
    JinxZendObject *object;
    struct NativeEnumCase *next;
} NativeEnumCase;

typedef struct NativeEnum {
    char *name;
    int backing_type;
    NativeEnumCase *cases;
    NativeEnumCase *cases_tail;
    struct NativeEnum *next;
} NativeEnum;

typedef struct NativeObject {
    JinxZendObject *object;
    struct NativeObject *next;
} NativeObject;

typedef struct NativeArray {
    JinxZendArray *array;
    struct NativeArray *next;
} NativeArray;

typedef struct NativeReference {
    JinxZendReference *reference;
    struct NativeReference *next;
} NativeReference;

typedef struct NativeSlot {
    NativeVariable *variable;
    JinxZendArray *array;
    JinxValue key;
    NativeProperty *property;
    JinxValue magic_object;
    const char *magic_name;
} NativeSlot;

typedef struct NativeRuntime {
    NativeAllocation *allocations;
    NativeVariable *variables;
    NativeArray *arrays;
    NativeReference *references;
    NativeClass *classes;
    NativeEnum *enums;
    NativeObject *objects;
    NativeClass *active_class;
    NativeClass *called_class;
    struct NativeFunction *functions;
    struct NativeFunction *checked_functions;
    unsigned call_depth;
    const char *exception_class;
    const char *exception_message;
    char *included[128];
    size_t included_count;
    unsigned depth;
    const char *error;
    int started;
} NativeRuntime;

typedef struct NativeParser {
    NativeRuntime *runtime;
    const char *cursor;
    const char *path;
    char *directory;
    char token[256];
    int kind;
    JinxValue literal;
    int checking;
    unsigned expression_depth;
    int returned;
    int strict_types;
} NativeParser;

typedef struct NativeCallArguments {
    JinxValue values[32];
    char *names[32];
    size_t count;
    int saw_named;
} NativeCallArguments;

typedef struct NativeFunction {
    char *name;
    char *parameters[16];
    int types[16];
    char *object_types[16];
    int promoted[16];
    JinxValue defaults[16];
    unsigned char has_default[16];
    unsigned variadic_index_plus_one;
    size_t count;
    int return_type;
    NativeParser body;
    NativeVariable *captures;
    NativeClass *owner;
    NativeClass *called_class;
    int is_private;
    int is_static;
    JinxValue bound_this;
    int is_arrow;
    struct NativeFunction *next;
} NativeFunction;

static void native_statements(NativeParser *parser, JinxValue *result);
static JinxValue native_function_call(NativeParser *parser, NativeFunction *function, JinxValue *args, size_t count);
static JinxValue native_function_call_named(NativeParser *parser, NativeFunction *function, NativeCallArguments *call);
static JinxValue native_closure(NativeParser *parser);
static JinxValue native_method_call(NativeParser *parser, JinxValue object, const char *name, JinxValue *args, size_t count);
static JinxValue native_method_call_named(NativeParser *parser, JinxValue object, const char *name, NativeCallArguments *call);
static JinxValue native_clone(NativeParser *parser, JinxValue value);
static JinxValue native_callback_call(NativeParser *parser, JinxValue callback, JinxValue *args, size_t count);
static JinxValue native_array_callback(NativeParser *parser, const char *name, JinxValue *args, size_t count);
static JinxValue native_array_sum_builtin(NativeParser *parser, JinxValue *args, size_t count);
static JinxValue native_count_builtin(NativeParser *parser, JinxValue *args, size_t count);
static JinxValue native_enum_static_call(NativeParser *parser, NativeEnum *entry, const char *method, NativeCallArguments *call);
static JinxValue native_construct(NativeParser *parser, const char *name, JinxValue *args, size_t count);
static JinxValue native_construct_named(NativeParser *parser, const char *name, NativeCallArguments *call);
static NativeFunction *native_method_find(NativeClass *owner, const char *name);
static JinxValue native_slot_read(NativeParser *parser, NativeSlot slot);
static void native_slot_write(NativeParser *parser, NativeSlot slot, JinxValue value);
static int native_slot_isset(NativeParser *parser, NativeSlot slot);
static void native_slot_unset(NativeParser *parser, NativeSlot slot);
static int native_truth(JinxValue value);
static int native_type_matches(int type, JinxValue value);

static NativeFunction *native_function_find(NativeRuntime *runtime, const char *name) {
    for (NativeFunction *function = runtime->functions; function; function = function->next)
        if (!strcasecmp(function->name, name)) return function;
    return NULL;
}

static int native_function_admitted(NativeRuntime *runtime, const char *name) {
    if (native_function_find(runtime, name)) return 1;
    for (NativeFunction *function = runtime->checked_functions; function; function = function->next)
        if (!strcasecmp(function->name, name)) return 1;
    return 0;
}

enum { N_END = 0, N_ID = 256, N_VAR, N_LITERAL, N_ARROW, N_COALESCE, N_SCOPE, N_OBJECT, N_NULLSAFE, N_ELLIPSIS };
enum { N_VALUE_INT = 1, N_VALUE_BOOL = 2, N_VALUE_STRING = 3, N_VALUE_FLOAT = 5, N_VALUE_CLOSURE = 100 };

static void *native_alloc(NativeRuntime *runtime, size_t size) {
    NativeAllocation *allocation = malloc(sizeof(*allocation));
    void *ptr = calloc(1, size);
    if (!allocation || !ptr) {
        free(allocation);
        free(ptr);
        runtime->error = "native interpreter allocation failed";
        return NULL;
    }
    allocation->ptr = ptr;
    allocation->next = runtime->allocations;
    runtime->allocations = allocation;
    return ptr;
}

static char *native_copy(NativeRuntime *runtime, const char *text, size_t length) {
    char *copy = native_alloc(runtime, length + 1);
    if (copy) memcpy(copy, text, length);
    return copy;
}

static void native_raise(NativeRuntime *runtime, const char *class_name, const char *message) {
    if (!runtime->exception_class) {
        runtime->exception_class = class_name;
        runtime->exception_message = message;
    }
}

static NativeClass *native_class_find(NativeRuntime *runtime, const char *name) {
    for (NativeClass *class_entry = runtime->classes; class_entry; class_entry = class_entry->next)
        if (!strcasecmp(class_entry->name, name)) return class_entry;
    return NULL;
}

static NativeEnum *native_enum_find(NativeRuntime *runtime, const char *name) {
    for (NativeEnum *entry = runtime->enums; entry; entry = entry->next)
        if (!strcasecmp(entry->name, name)) return entry;
    return NULL;
}

static NativeEnumCase *native_enum_case_find(NativeEnum *entry, const char *name) {
    if (!entry) return NULL;
    for (NativeEnumCase *case_entry = entry->cases; case_entry; case_entry = case_entry->next)
        if (!strcmp(case_entry->name, name)) return case_entry;
    return NULL;
}

static int native_strict_equal(JinxValue left, JinxValue right) {
    if (left.type != right.type) return 0;
    if (left.type == 0) return 1;
    if (left.type == N_VALUE_INT || left.type == N_VALUE_BOOL) return left.as.i64 == right.as.i64;
    if (left.type == N_VALUE_FLOAT) return left.as.f64 == right.as.f64;
    if (left.type == N_VALUE_STRING)
        return left.flags == right.flags && (!left.flags || !memcmp(left.as.ptr, right.as.ptr, left.flags));
    if (left.type == JINX_ORACLE_VALUE_ZEND_OBJECT || left.type == JINX_ORACLE_VALUE_ZEND_ARRAY)
        return left.as.ptr == right.as.ptr;
    return left.as.ptr == right.as.ptr;
}

static NativeClass *native_class_resolve(NativeRuntime *runtime, const char *name) {
    if (!strcasecmp(name, "self")) return runtime->active_class;
    if (!strcasecmp(name, "parent")) return runtime->active_class ? runtime->active_class->parent : NULL;
    if (!strcasecmp(name, "static")) return runtime->called_class ? runtime->called_class : runtime->active_class;
    return native_class_find(runtime, name);
}

static const char *native_class_name_resolve(NativeRuntime *runtime, const char *name) {
    NativeClass *resolved = native_class_resolve(runtime, name);
    if (resolved) return resolved->name;
    if (!strcasecmp(name, "self") || !strcasecmp(name, "parent") || !strcasecmp(name, "static")) return NULL;
    return name;
}

static NativeProperty *native_property_find(NativeClass *class_entry, const char *name, int is_static) {
    for (; class_entry; class_entry = class_entry->parent)
        for (NativeProperty *property = class_entry->properties; property; property = property->next)
            if (property->is_static == is_static && !strcmp(property->name, name)) return property;
    return NULL;
}

static int native_object_matches(NativeRuntime *runtime, JinxValue value, const char *name) {
    if (value.type != JINX_ORACLE_VALUE_ZEND_OBJECT) return 0;
    const char *class_name = ((JinxZendObject *)value.as.ptr)->class_name;
    if (class_name && !strcasecmp(class_name, name)) return 1;
    NativeClass *owner = native_class_find(runtime, class_name);
    for (; owner; owner = owner->parent) if (!strcasecmp(owner->name, name)) return 1;
    return 0;
}

static void native_next(NativeParser *parser) {
    const char *p = parser->cursor;
    NativeRuntime *runtime = parser->runtime;
    parser->token[0] = '\0';
    for (;;) {
        while (isspace((unsigned char)*p)) p++;
        if (!strncmp(p, "//", 2) || *p == '#') {
            while (*p && *p != '\n') p++;
        } else if (!strncmp(p, "/*", 2)) {
            const char *end = strstr(p + 2, "*/");
            if (!end) { runtime->error = "unterminated comment"; break; }
            p = end + 2;
        } else break;
    }
    if (!*p || runtime->error) {
        parser->kind = N_END;
    } else if (!strncmp(p, "<<<'", 4)) {
        const char *label = p + 4;
        const char *quote = strchr(label, '\'');
        if (!quote || quote[1] != '\n' || quote == label || quote - label > 120) {
            runtime->error = "unsupported native nowdoc header";
            parser->kind = N_END;
            return;
        }
        char marker[128];
        size_t length = (size_t)(quote - label);
        marker[0] = '\n';
        memcpy(marker + 1, label, length);
        marker[length + 1] = '\0';
        const char *body = quote + 2;
        const char *end = strstr(body, marker);
        if (!end || (isalnum((unsigned char)end[length + 1]) || end[length + 1] == '_')) {
            runtime->error = "unterminated native nowdoc";
            parser->kind = N_END;
            return;
        }
        parser->literal = jinx_value_string(native_copy(runtime, body, (size_t)(end - body)), (uint32_t)(end - body));
        parser->kind = N_LITERAL;
        p = end + length + 1;
    } else if (!strncmp(p, "...", 3)) {
        parser->kind = N_ELLIPSIS;
        p += 3;
    } else if (!strncmp(p, "?->", 3)) {
        parser->kind = N_NULLSAFE;
        p += 3;
    } else if (!strncmp(p, "::", 2) || !strncmp(p, "->", 2)) {
        parser->kind = *p == ':' ? N_SCOPE : N_OBJECT;
        p += 2;
    } else if (!strncmp(p, "=>", 2) || !strncmp(p, "??", 2)) {
        parser->kind = *p == '=' ? N_ARROW : N_COALESCE;
        p += 2;
    } else if (*p == '\'' || *p == '"') {
        char quote = *p++;
        char *buffer = native_alloc(runtime, strlen(p) + 1);
        size_t length = 0;
        if (!buffer) { parser->kind = N_END; return; }
        while (*p && *p != quote) {
            char c = *p++;
            if (c == '$' && quote == '"') runtime->error = "string interpolation is not yet native";
            if (c == '\\') {
                if (!*p) break;
                char escaped = *p++;
                if (escaped == quote || escaped == '\\') c = escaped;
                else if (quote == '"' && escaped == 'n') c = '\n';
                else if (quote == '"' && escaped == 'r') c = '\r';
                else if (quote == '"' && escaped == 't') c = '\t';
                else { buffer[length++] = '\\'; c = escaped; }
            }
            buffer[length++] = c;
        }
        if (*p != quote) runtime->error = "unterminated string";
        else p++;
        parser->literal = jinx_value_string(buffer, (uint32_t)length);
        parser->kind = N_LITERAL;
    } else if (isdigit((unsigned char)*p)) {
        char *end;
        errno = 0;
        parser->literal = jinx_value_int(strtoll(p, &end, 10));
        if (errno == ERANGE) runtime->error = "native integer literal overflow";
        if ((*end == '.' && isdigit((unsigned char)end[1])) || *end == 'e' || *end == 'E')
            runtime->error = "floating-point literals are not yet native";
        p = end;
        parser->kind = N_LITERAL;
    } else if (*p == '$' || isalpha((unsigned char)*p) || *p == '_') {
        parser->kind = *p == '$' ? N_VAR : N_ID;
        if (*p == '$') p++;
        size_t length = 0;
        if (!isalpha((unsigned char)*p) && *p != '_') runtime->error = "invalid variable name";
        while (isalnum((unsigned char)*p) || *p == '_') {
            if (length + 1 < sizeof(parser->token)) parser->token[length++] = *p;
            else runtime->error = "identifier is too long";
            p++;
        }
        parser->token[length] = '\0';
    } else parser->kind = (unsigned char)*p++;
    parser->cursor = p;
}

static int native_accept(NativeParser *parser, int kind) {
    if (parser->kind != kind) return 0;
    native_next(parser);
    return 1;
}

static void native_expect(NativeParser *parser, int kind) {
    if (parser->runtime->error) return;
    if (!parser->checking && parser->runtime->exception_class) return;
    if (!native_accept(parser, kind)) parser->runtime->error = "unsupported or malformed PHP syntax";
}

static NativeVariable *native_variable(NativeRuntime *runtime, const char *name) {
    NativeVariable *variable;
    for (variable = runtime->variables; variable; variable = variable->next)
        if (!strcmp(variable->name, name)) return variable;
    variable = native_alloc(runtime, sizeof(*variable));
    if (!variable) return NULL;
    variable->name = native_copy(runtime, name, strlen(name));
    variable->value = jinx_value_null();
    variable->next = runtime->variables;
    runtime->variables = variable;
    return variable;
}

static const char *native_text(NativeParser *parser, JinxValue value, size_t *length) {
    char buffer[64];
    const char *text = "";
    if (value.type == N_VALUE_STRING) {
        *length = value.flags;
        return value.as.ptr;
    }
    if (value.type == N_VALUE_INT) snprintf(buffer, sizeof(buffer), "%lld", (long long)value.as.i64);
    else if (value.type == N_VALUE_FLOAT) snprintf(buffer, sizeof(buffer), "%.14g", value.as.f64);
    else if (value.type == N_VALUE_BOOL) snprintf(buffer, sizeof(buffer), "%s", value.as.i64 ? "1" : "");
    else buffer[0] = '\0';
    text = native_copy(parser->runtime, buffer, strlen(buffer));
    *length = strlen(buffer);
    return text;
}

static JinxValue native_expression(NativeParser *parser, int minimum);
static int native_file(NativeRuntime *runtime, const char *path, int once, JinxValue *result);

static JinxZendValue native_zend_value(NativeParser *parser, JinxValue value) {
    if (value.type == N_VALUE_CLOSURE) {
        parser->runtime->error = "native closures in Zend containers are not yet supported";
        return jinx_zend_null();
    }
    if (value.type == N_VALUE_INT) return jinx_zend_long(value.as.i64);
    if (value.type == N_VALUE_BOOL) return jinx_zend_bool((int)value.as.i64);
    if (value.type == N_VALUE_STRING) return jinx_zend_string_value(jinx_zend_string_new(value.as.ptr, value.flags));
    if (value.type == JINX_ORACLE_VALUE_ZEND_ARRAY) return jinx_zend_array_value(value.as.ptr);
    if (value.type == JINX_ORACLE_VALUE_ZEND_OBJECT) return jinx_zend_object_value(value.as.ptr);
    return jinx_zend_null();
}

static JinxValue native_value_copy(NativeParser *parser, JinxValue value) {
    if (value.type != JINX_ORACLE_VALUE_ZEND_ARRAY) return value;
    NativeArray *owner = native_alloc(parser->runtime, sizeof(*owner));
    if (!owner) return jinx_value_null();
    owner->array = jinx_zend_array_clone(value.as.ptr);
    if (!owner->array) { parser->runtime->error = "native array copy failed"; return jinx_value_null(); }
    owner->next = parser->runtime->arrays;
    parser->runtime->arrays = owner;
    return jinx_oracle_zend_array_value_borrowed(owner->array);
}

static JinxValue native_from_zend(NativeParser *parser, JinxZendValue value) {
    unsigned depth = 0;
    while (value.type == JINX_ZEND_REFERENCE && value.value.ref) {
        if (++depth > 128) { parser->runtime->error = "native reference cycle"; return jinx_value_null(); }
        value = value.value.ref->value;
    }
    switch (value.type) {
        case JINX_ZEND_LONG: return jinx_value_int(value.value.lval);
        case JINX_ZEND_FALSE: return jinx_value_bool(0);
        case JINX_ZEND_TRUE: return jinx_value_bool(1);
        case JINX_ZEND_STRING:
            return jinx_value_string(native_copy(parser->runtime, value.value.str->bytes, value.value.str->len), (uint32_t)value.value.str->len);
        case JINX_ZEND_ARRAY: return jinx_oracle_zend_array_value_borrowed(value.value.array);
        case JINX_ZEND_OBJECT: return jinx_oracle_zend_object_value_borrowed(value.value.object);
        case JINX_ZEND_NULL: return jinx_value_null();
        default: parser->runtime->error = "native referenced value type is unsupported"; return jinx_value_null();
    }
}


static int native_call_argument_push(NativeParser *parser, NativeCallArguments *call, JinxValue value, const char *name) {
    if (call->count >= 32) {
        parser->runtime->error = "native call argument limit";
        return 0;
    }
    if (name) {
        for (size_t i = 0; i < call->count; i++) {
            if (call->names[i] && !strcmp(call->names[i], name)) {
                native_raise(parser->runtime, "Error", "Named parameter overwrites previous argument");
                return 0;
            }
        }
        call->names[call->count] = native_copy(parser->runtime, name, strlen(name));
        call->saw_named = 1;
    } else if (call->saw_named) {
        native_raise(parser->runtime, "Error", "Cannot use positional argument after named argument");
        return 0;
    }
    call->values[call->count++] = value;
    return 1;
}

static void native_parse_call_arguments(NativeParser *parser, NativeCallArguments *call) {
    if (parser->kind == ')') {
        native_next(parser);
        return;
    }
    do {
        if (native_accept(parser, N_ELLIPSIS)) {
            JinxValue unpacked = native_expression(parser, 0);
            if (!parser->checking && !parser->runtime->error && !parser->runtime->exception_class) {
                if (unpacked.type != JINX_ORACLE_VALUE_ZEND_ARRAY) {
                    native_raise(parser->runtime, "TypeError", "Only arrays are currently supported for native argument unpacking");
                } else {
                    JinxZendArray *array = unpacked.as.ptr;
                    for (size_t i = 0; i < array->count && !parser->runtime->error && !parser->runtime->exception_class; i++) {
                        const JinxZendBucket *bucket = jinx_zend_array_iter_at(array, i);
                        if (!bucket) continue;
                        JinxValue value = native_from_zend(parser, bucket->value);
                        if (bucket->key) {
                            if (!native_call_argument_push(parser, call, value, bucket->key->bytes)) break;
                        } else {
                            if (!native_call_argument_push(parser, call, value, NULL)) break;
                        }
                    }
                }
            }
        } else {
            char name[256] = {0};
            if (parser->kind == N_ID) {
                NativeParser peek = *parser;
                native_next(&peek);
                if (peek.kind == ':') {
                    strcpy(name, parser->token);
                    native_next(parser);
                    native_expect(parser, ':');
                }
            }
            JinxValue value = native_expression(parser, 0);
            if (!parser->checking && !parser->runtime->error && !parser->runtime->exception_class)
                native_call_argument_push(parser, call, value, name[0] ? name : NULL);
        }
    } while (native_accept(parser, ',') && parser->kind != ')');
    native_expect(parser, ')');
}

static JinxValue native_call_arguments_array(NativeParser *parser, NativeCallArguments *call) {
    NativeArray *owner = native_alloc(parser->runtime, sizeof(*owner));
    if (!owner) return jinx_value_null();
    owner->array = jinx_zend_array_new_packed(call->count);
    if (!owner->array) {
        parser->runtime->error = "native magic argument array allocation failed";
        return jinx_value_null();
    }
    owner->next = parser->runtime->arrays;
    parser->runtime->arrays = owner;
    for (size_t index = 0; index < call->count && !parser->runtime->error; index++) {
        JinxZendValue converted = native_zend_value(parser, call->values[index]);
        if (parser->runtime->error) break;
        int ok = call->names[index]
            ? jinx_zend_array_add_assoc(owner->array, call->names[index], strlen(call->names[index]), converted)
            : jinx_zend_array_append(owner->array, converted);
        if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
        if (!ok) parser->runtime->error = "native magic argument insertion failed";
    }
    return jinx_oracle_zend_array_value_borrowed(owner->array);
}

static JinxValue native_variable_read(NativeParser *parser, NativeVariable *variable) {
    if (!variable) return jinx_value_null();
    return variable->reference ? native_from_zend(parser, variable->reference->value) : variable->value;
}

static NativeSlot native_object_slot(NativeParser *parser, JinxValue object, const char *name) {
    NativeSlot slot = {0};
    if (parser->checking || parser->runtime->exception_class) return slot;
    if (object.type != JINX_ORACLE_VALUE_ZEND_OBJECT) {
        native_raise(parser->runtime, "Error", "Property access requires an object");
        return slot;
    }
    JinxZendObject *instance = object.as.ptr;
    NativeClass *class_entry = native_class_find(parser->runtime, instance->class_name);
    slot.property = native_property_find(class_entry, name, 0);
    if (!slot.property && (!strcasecmp(instance->class_name, "stdClass") ||
        native_enum_find(parser->runtime, instance->class_name))) {
        slot.array = instance->properties;
        slot.key = jinx_value_string(native_copy(parser->runtime, name, strlen(name)), (uint32_t)strlen(name));
        if (native_enum_find(parser->runtime, instance->class_name)) slot.magic_name = "__enum_readonly__";
        return slot;
    }
    int inaccessible = slot.property && slot.property->is_private && parser->runtime->active_class != slot.property->owner;
    if (!slot.property || inaccessible) {
        if (native_method_find(class_entry, "__get") || native_method_find(class_entry, "__set")) {
            slot.property = NULL;
            slot.magic_object = object;
            slot.magic_name = native_copy(parser->runtime, name, strlen(name));
            return slot;
        }
        if (!slot.property) {
            if (class_entry && class_entry->is_readonly)
                native_raise(parser->runtime, "Error", "Cannot create dynamic property on readonly class");
            else
                parser->runtime->error = "native object property is undeclared";
        } else native_raise(parser->runtime, "Error", "Cannot access private property");
        return slot;
    }
    slot.array = instance->properties;
    slot.key = jinx_value_string(native_copy(parser->runtime, name, strlen(name)), (uint32_t)strlen(name));
    return slot;
}

static NativeSlot native_static_slot(NativeParser *parser, const char *class_name) {
    NativeSlot slot = {0};
    native_expect(parser, N_SCOPE);
    if (parser->kind != N_VAR) { parser->runtime->error = "native static access requires property"; return slot; }
    char name[256];
    strcpy(name, parser->token);
    native_next(parser);
    if (!parser->checking) {
        NativeClass *class_entry = native_class_resolve(parser->runtime, class_name);
        if (!class_entry) {
            native_raise(parser->runtime, "Error", "Class not found for static property access");
            return slot;
        }
        slot.property = native_property_find(class_entry, name, 1);
        if (!slot.property) native_raise(parser->runtime, "Error", "Access to undeclared static property");
        else if (slot.property->is_private && parser->runtime->active_class != slot.property->owner)
            native_raise(parser->runtime, "Error", "Cannot access private static property");
        else slot.variable = &slot.property->storage;
    }
    return slot;
}

static NativeSlot native_slot(NativeParser *parser) {
    NativeSlot slot = {0};
    if (parser->kind == N_ID) {
        char class_name[256];
        strcpy(class_name, parser->token);
        native_next(parser);
        return native_static_slot(parser, class_name);
    }
    if (parser->kind != N_VAR) { parser->runtime->error = "native assignment requires variable"; return slot; }
    char name[256];
    strcpy(name, parser->token);
    native_next(parser);
    if (!parser->checking) slot.variable = native_variable(parser->runtime, name);
    int property_access = 0;
    while (native_accept(parser, N_OBJECT)) {
        property_access = 1;
        if (parser->kind != N_ID) { parser->runtime->error = "native property requires name"; return slot; }
        char property_name[256];
        strcpy(property_name, parser->token);
        native_next(parser);
        slot = native_object_slot(parser, native_slot_read(parser, slot), property_name);
    }
    size_t offset_depth = 0;
    while (native_accept(parser, '[')) {
        JinxValue key = native_expression(parser, 0);
        native_expect(parser, ']');
        if (!parser->checking && !parser->runtime->error) {
            if (!property_access && !strcmp(name, "GLOBALS") && !offset_depth) {
                if (key.type != N_VALUE_STRING) parser->runtime->error = "native GLOBALS key must be string";
                else slot.variable = native_variable(parser->runtime, key.as.ptr);
            } else {
                JinxValue array = native_slot_read(parser, slot);
                if (array.type != JINX_ORACLE_VALUE_ZEND_ARRAY) parser->runtime->error = "native offset requires array";
                else {
                    // Outer copies share child containers until an offset path descends.
                    if (offset_depth) {
                        native_slot_write(parser, slot, array);
                        array = native_slot_read(parser, slot);
                    }
                    slot.array = array.as.ptr;
                }
                slot.variable = NULL;
                slot.property = NULL;
                slot.magic_name = NULL;
                slot.magic_object = jinx_value_null();
                slot.key = key;
            }
        }
        offset_depth++;
    }
    return slot;
}

static JinxZendValue *native_slot_element(NativeParser *parser, NativeSlot slot, int create) {
    if (!slot.array) return NULL;
    if (slot.key.type == N_VALUE_BOOL) slot.key = jinx_value_int(slot.key.as.i64);
    else if (slot.key.type == 0) slot.key = jinx_value_string("", 0);
    int64_t index;
    int numeric = slot.key.type == N_VALUE_INT;
    if (numeric) index = slot.key.as.i64;
    else if (slot.key.type == N_VALUE_STRING) numeric = jinx_zend_array_numeric_string_key(slot.key.as.ptr, slot.key.flags, &index);
    else { parser->runtime->error = "unsupported native offset key"; return NULL; }
    if (numeric && index < 0) { parser->runtime->error = "negative native offset is unsupported"; return NULL; }
    JinxZendValue *value = numeric ? jinx_zend_array_index(slot.array, (size_t)index)
        : jinx_zend_array_find(slot.array, slot.key.as.ptr, slot.key.flags);
    if (!value && create) {
        int ok = numeric ? jinx_zend_array_add_index(slot.array, (size_t)index, jinx_zend_null())
            : jinx_zend_array_add_symtable(slot.array, slot.key.as.ptr, slot.key.flags, jinx_zend_null());
        if (!ok) { parser->runtime->error = "native offset insertion failed"; return NULL; }
        value = numeric ? jinx_zend_array_index(slot.array, (size_t)index)
            : jinx_zend_array_find(slot.array, slot.key.as.ptr, slot.key.flags);
    }
    return value;
}

static JinxValue native_slot_read(NativeParser *parser, NativeSlot slot) {
    if (parser->checking) return jinx_value_null();
    if (slot.magic_name && !strcmp(slot.magic_name, "__enum_readonly__")) {
        JinxZendValue *element = native_slot_element(parser, slot, 0);
        return element ? native_from_zend(parser, *element) : jinx_value_null();
    }
    if (slot.magic_name) {
        NativeCallArguments call = {0};
        call.count = 1;
        call.values[0] = jinx_value_string((void *)slot.magic_name, (uint32_t)strlen(slot.magic_name));
        return native_method_call_named(parser, slot.magic_object, "__get", &call);
    }
    if (slot.property && slot.property->is_static && !slot.property->initialized) {
        native_raise(parser->runtime, "Error", "Typed static property must not be accessed before initialization");
        return jinx_value_null();
    }
    if (slot.variable) return native_variable_read(parser, slot.variable);
    JinxZendValue *element = native_slot_element(parser, slot, 0);
    if (!element && slot.property) native_raise(parser->runtime, "Error", "Typed property must not be accessed before initialization");
    return element ? native_from_zend(parser, *element) : jinx_value_null();
}

static void native_slot_write(NativeParser *parser, NativeSlot slot, JinxValue value) {
    if (parser->checking || parser->runtime->error || parser->runtime->exception_class) return;
    if (slot.magic_name && !strcmp(slot.magic_name, "__enum_readonly__")) {
        native_raise(parser->runtime, "Error", "Cannot modify readonly enum property");
        return;
    }
    if (slot.magic_name) {
        NativeCallArguments call = {0};
        call.count = 2;
        call.values[0] = jinx_value_string((void *)slot.magic_name, (uint32_t)strlen(slot.magic_name));
        call.values[1] = value;
        (void)native_method_call_named(parser, slot.magic_object, "__set", &call);
        return;
    }
    if (slot.property && slot.property->is_readonly && !slot.property->is_static) {
        JinxZendValue *existing = slot.array ? native_slot_element(parser, slot, 0) : NULL;
        if (existing) {
            native_raise(parser->runtime, "Error", "Cannot modify readonly property");
            return;
        }
        if (parser->runtime->active_class != slot.property->owner) {
            native_raise(parser->runtime, "Error", "Cannot initialize readonly property from global scope");
            return;
        }
    }
    if (slot.property && ((slot.property->type && !native_type_matches(slot.property->type, value)) ||
        (slot.property->object_type && value.type != 0 &&
         !native_object_matches(parser->runtime, value, slot.property->object_type)))) {
        native_raise(parser->runtime, "TypeError", "Cannot assign incompatible value to typed property");
        return;
    }
    if (slot.property && slot.property->is_static) slot.property->initialized = 1;
    value = native_value_copy(parser, value);
    if (parser->runtime->error) return;
    if (slot.variable && !slot.variable->reference) { slot.variable->value = value; return; }
    JinxZendValue *target = slot.variable ? &slot.variable->reference->value : native_slot_element(parser, slot, 1);
    if (!target) return;
    if (target->type == JINX_ZEND_REFERENCE) target = &target->value.ref->value;
    JinxZendValue converted = native_zend_value(parser, value);
    if (parser->runtime->error) return;
    if (converted.type == JINX_ZEND_ARRAY || converted.type == JINX_ZEND_OBJECT) converted = jinx_zend_value_copy(converted);
    jinx_zend_value_release(*target);
    *target = converted;
}

static int native_slot_isset(NativeParser *parser, NativeSlot slot) {
    if (parser->checking || parser->runtime->error || parser->runtime->exception_class) return 0;
    if (slot.magic_name) {
        JinxZendObject *instance = slot.magic_object.as.ptr;
        NativeClass *owner = instance ? native_class_find(parser->runtime, instance->class_name) : NULL;
        if (!native_method_find(owner, "__isset")) return 0;
        NativeCallArguments call = {0};
        call.count = 1;
        call.values[0] = jinx_value_string((void *)slot.magic_name, (uint32_t)strlen(slot.magic_name));
        JinxValue result = native_method_call_named(parser, slot.magic_object, "__isset", &call);
        return native_truth(result);
    }
    if (slot.variable) return native_variable_read(parser, slot.variable).type != 0;
    JinxZendValue *element = native_slot_element(parser, slot, 0);
    if (!element) return 0;
    JinxValue value = native_from_zend(parser, *element);
    return value.type != 0;
}

static void native_slot_unset(NativeParser *parser, NativeSlot slot) {
    if (parser->checking || parser->runtime->error || parser->runtime->exception_class) return;
    if (slot.magic_name) {
        JinxZendObject *instance = slot.magic_object.as.ptr;
        NativeClass *owner = instance ? native_class_find(parser->runtime, instance->class_name) : NULL;
        if (native_method_find(owner, "__unset")) {
            NativeCallArguments call = {0};
            call.count = 1;
            call.values[0] = jinx_value_string((void *)slot.magic_name, (uint32_t)strlen(slot.magic_name));
            (void)native_method_call_named(parser, slot.magic_object, "__unset", &call);
        }
        return;
    }
    if (slot.variable) {
        slot.variable->reference = NULL;
        slot.variable->value = jinx_value_null();
        if (slot.property && slot.property->is_static) slot.property->initialized = 0;
        return;
    }
    if (slot.array) {
        if (slot.key.type == N_VALUE_BOOL) slot.key = jinx_value_int(slot.key.as.i64);
        else if (slot.key.type == 0) slot.key = jinx_value_string("", 0);
        int64_t index = 0;
        int numeric = slot.key.type == N_VALUE_INT;
        if (numeric) index = slot.key.as.i64;
        else if (slot.key.type == N_VALUE_STRING)
            numeric = jinx_zend_array_numeric_string_key(slot.key.as.ptr, slot.key.flags, &index);
        else {
            parser->runtime->error = "unsupported native unset offset key";
            return;
        }
        if (numeric) {
            if (index >= 0) (void)jinx_zend_array_del_index(slot.array, (size_t)index);
        } else {
            (void)jinx_zend_array_del_assoc(slot.array, slot.key.as.ptr, slot.key.flags);
        }
    }
}

static JinxZendReference *native_slot_reference(NativeParser *parser, NativeSlot slot) {
    if (parser->checking || parser->runtime->error) return NULL;
    if (slot.property) { parser->runtime->error = "typed property references are not yet native"; return NULL; }
    if (slot.variable && slot.variable->reference) return slot.variable->reference;
    JinxZendValue *element = slot.array ? native_slot_element(parser, slot, 1) : NULL;
    if (element && element->type == JINX_ZEND_REFERENCE) return element->value.ref;
    JinxZendValue initial = element ? *element : native_zend_value(parser, native_slot_read(parser, slot));
    if (parser->runtime->error) return NULL;
    NativeReference *owner = native_alloc(parser->runtime, sizeof(*owner));
    if (!owner) return NULL;
    owner->reference = jinx_zend_reference_new(initial);
    if (!element && initial.type == JINX_ZEND_STRING) jinx_zend_value_release(initial);
    if (!owner->reference) { parser->runtime->error = "native reference allocation failed"; return NULL; }
    owner->next = parser->runtime->references;
    parser->runtime->references = owner;
    if (slot.variable) slot.variable->reference = owner->reference;
    else if (element) {
        jinx_zend_value_release(*element);
        *element = jinx_zend_value_copy(jinx_zend_reference_value(owner->reference));
    }
    return owner->reference;
}

static void native_slot_bind(NativeParser *parser, NativeSlot slot, JinxZendReference *reference) {
    if (parser->checking || parser->runtime->error || !reference) return;
    if (slot.property) { parser->runtime->error = "typed property references are not yet native"; return; }
    if (slot.variable) slot.variable->reference = reference;
    else {
        JinxZendValue *element = native_slot_element(parser, slot, 1);
        if (element) {
            jinx_zend_value_release(*element);
            *element = jinx_zend_value_copy(jinx_zend_reference_value(reference));
        }
    }
}

static JinxValue native_array(NativeParser *parser) {
    NativeRuntime *runtime = parser->runtime;
    NativeArray *owner = NULL;
    JinxZendArray *array = NULL;
    if (!parser->checking) {
        owner = native_alloc(runtime, sizeof(*owner));
        array = jinx_zend_array_new_packed(4);
        if (!owner || !array) {
            if (array) jinx_zend_array_release(array);
            runtime->error = "native array allocation failed";
            return jinx_value_null();
        }
        owner->array = array;
        owner->next = runtime->arrays;
        runtime->arrays = owner;
    }
    native_expect(parser, '[');
    while (parser->kind != ']' && !runtime->error) {
        if (native_accept(parser, '.')) {
            native_expect(parser, '.');
            native_expect(parser, '.');
            JinxValue source = native_expression(parser, 0);
            if (!parser->checking && !runtime->error && !runtime->exception_class) {
                if (source.type != JINX_ORACLE_VALUE_ZEND_ARRAY) {
                    native_raise(runtime, "Error", "Only arrays and Traversables can be unpacked");
                } else {
                    JinxZendArray *input = source.as.ptr;
                    for (size_t i = 0; i < input->count && !runtime->error; i++) {
                        const JinxZendBucket *bucket = jinx_zend_array_iter_at(input, i);
                        if (!bucket) continue;
                        JinxValue item = native_value_copy(parser, native_from_zend(parser, bucket->value));
                        JinxZendValue converted = native_zend_value(parser, item);
                        if (runtime->error) break;
                        int ok = bucket->key
                            ? jinx_zend_array_add_symtable(array, bucket->key->bytes, bucket->key->len, converted)
                            : jinx_zend_array_append(array, converted);
                        if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
                        if (!ok) runtime->error = "native array spread insertion failed";
                    }
                }
            }
            if (!native_accept(parser, ',')) break;
            continue;
        }
        JinxValue key = jinx_value_null();
        JinxValue value = native_expression(parser, 0);
        int keyed = native_accept(parser, N_ARROW);
        if (keyed) { key = value; value = native_expression(parser, 0); }
        if (!parser->checking && !runtime->error) {
            value = native_value_copy(parser, value);
            if (key.type == N_VALUE_BOOL) key = jinx_value_int(key.as.i64);
            else if (key.type == 0) key = jinx_value_string("", 0);
            JinxZendValue converted = native_zend_value(parser, value);
            if (parser->runtime->error) break;
            int ok;
            if (!keyed) ok = jinx_zend_array_append(array, converted);
            else if (key.type == N_VALUE_STRING) ok = jinx_zend_array_add_symtable(array, key.as.ptr, key.flags, converted);
            else if (key.type == N_VALUE_INT && key.as.i64 >= 0) ok = jinx_zend_array_add_index(array, (size_t)key.as.i64, converted);
            else { ok = 0; runtime->error = "native array key type is unsupported"; }
            if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
            if (!ok) runtime->error = "native array insertion failed";
        }
        if (!native_accept(parser, ',')) break;
    }
    native_expect(parser, ']');
    return array ? jinx_oracle_zend_array_value_borrowed(array) : jinx_value_null();
}

static int native_builtin_admitted(const char *name) {
    static const char *names[] = {
        "strlen", "strtoupper", "strtolower", "abs", "json_encode",
        "file_put_contents", "unlink", "tempnam", "sys_get_temp_dir",
        "fopen", "fwrite", "rewind", "fread", "fclose", "file_exists",
        "file_get_contents", "gettype", "error_reporting", "call_user_func", "array_map", "array_reduce", "array_sum", "count", NULL
    };
    for (size_t i = 0; names[i]; i++) if (!strcmp(name, names[i])) return 1;
    return 0;
}

static void native_instance_defaults(NativeParser *parser, NativeClass *class_entry, JinxZendObject *object) {
    if (!class_entry || parser->runtime->error || parser->runtime->exception_class) return;
    native_instance_defaults(parser, class_entry->parent, object);
    for (NativeProperty *property = class_entry->properties; property; property = property->next) {
        if (!property->is_static && property->initialized) {
            NativeSlot slot = {0};
            slot.array = object->properties;
            slot.key = jinx_value_string(property->name, (uint32_t)strlen(property->name));
            slot.property = property;
            native_slot_write(parser, slot, property->storage.value);
        }
    }
}

static JinxValue native_instance(NativeParser *parser, const char *name, int internal) {
    int plain_object = !strcasecmp(name, "stdClass");
    if (parser->checking) {
        if (plain_object) return jinx_value_null();
        JinxValue argument = jinx_value_string(name, (uint32_t)strlen(name));
        int ok = 0;
        JinxValue registered = jinx_call_builtin_through_oracle_checked("class_exists", &argument, 1, &ok);
        if (!ok || (registered.type == N_VALUE_BOOL && registered.as.i64))
            parser->runtime->error = "registered builtin constructors are not yet admitted by native source interpreter";
        return jinx_value_null();
    }
    if (parser->runtime->exception_class) return jinx_value_null();
    NativeClass *class_entry = native_class_find(parser->runtime, name);
    if (!class_entry && !internal && !plain_object) {
        native_raise(parser->runtime, "Error", "Class not found");
        return jinx_value_null();
    }
    NativeObject *owner = native_alloc(parser->runtime, sizeof(*owner));
    if (!owner) return jinx_value_null();
    owner->object = jinx_zend_object_new(name);
    if (!owner->object) { parser->runtime->error = "native object allocation failed"; return jinx_value_null(); }
    owner->next = parser->runtime->objects;
    parser->runtime->objects = owner;
    native_instance_defaults(parser, class_entry, owner->object);
    return jinx_oracle_zend_object_value_borrowed(owner->object);
}

static JinxValue native_cast(NativeParser *parser, const char *type, JinxValue value) {
    NativeRuntime *runtime = parser->runtime;
    if (!strcmp(type, "bool")) return jinx_value_bool(native_truth(value));
    if (!strcmp(type, "string")) {
        size_t length = 0;
        const char *text = native_text(parser, value, &length);
        return jinx_value_string(native_copy(runtime, text, length), (uint32_t)length);
    }
    if (!strcmp(type, "int")) {
        if (value.type == N_VALUE_INT) return value;
        if (value.type == N_VALUE_BOOL) return jinx_value_int(value.as.i64);
        if (value.type == N_VALUE_FLOAT) return jinx_value_int((int64_t)value.as.f64);
        if (value.type == N_VALUE_STRING) {
            char *end = NULL;
            errno = 0;
            long long number = strtoll(value.as.ptr, &end, 10);
            if (errno == ERANGE) number = ((const char *)value.as.ptr)[0] == '-' ? LLONG_MIN : LLONG_MAX;
            return jinx_value_int(number);
        }
        if (value.type == 0) return jinx_value_int(0);
        return jinx_value_int(1);
    }
    if (!strcmp(type, "array")) {
        if (value.type == JINX_ORACLE_VALUE_ZEND_ARRAY) return native_value_copy(parser, value);
        NativeArray *owner = native_alloc(runtime, sizeof(*owner));
        if (!owner) return jinx_value_null();
        if (value.type == JINX_ORACLE_VALUE_ZEND_OBJECT) {
            JinxZendObject *object = value.as.ptr;
            owner->array = jinx_zend_array_clone(object->properties);
        } else {
            owner->array = jinx_zend_array_new_packed(value.type == 0 ? 0 : 1);
            if (owner->array && value.type != 0) {
                JinxZendValue converted = native_zend_value(parser, value);
                if (!runtime->error && !jinx_zend_array_append(owner->array, converted))
                    runtime->error = "native scalar-to-array cast failed";
                if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
            }
        }
        if (!owner->array) { runtime->error = "native array cast allocation failed"; return jinx_value_null(); }
        owner->next = runtime->arrays;
        runtime->arrays = owner;
        return jinx_oracle_zend_array_value_borrowed(owner->array);
    }
    if (!strcmp(type, "object")) {
        if (value.type == JINX_ORACLE_VALUE_ZEND_OBJECT) return value;
        JinxValue object_value = native_instance(parser, "stdClass", 0);
        if (runtime->error || runtime->exception_class || object_value.type != JINX_ORACLE_VALUE_ZEND_OBJECT)
            return jinx_value_null();
        JinxZendObject *object = object_value.as.ptr;
        if (value.type == JINX_ORACLE_VALUE_ZEND_ARRAY) {
            JinxZendArray *array = value.as.ptr;
            for (size_t i = 0; i < array->count; i++) {
                const JinxZendBucket *bucket = jinx_zend_array_iter_at(array, i);
                if (!bucket) continue;
                char numeric[32];
                const char *key = NULL;
                size_t length = 0;
                if (bucket->key) {
                    key = bucket->key->bytes;
                    length = bucket->key->len;
                } else {
                    int written = snprintf(numeric, sizeof(numeric), "%llu", (unsigned long long)bucket->h);
                    if (written < 0 || written >= (int)sizeof(numeric)) {
                        runtime->error = "native object cast numeric key overflow";
                        break;
                    }
                    key = numeric;
                    length = (size_t)written;
                }
                if (!jinx_zend_array_add_assoc(object->properties, key, length, bucket->value)) {
                    runtime->error = "native array-to-object cast failed";
                    break;
                }
            }
        } else if (value.type != 0) {
            JinxZendValue converted = native_zend_value(parser, value);
            if (!runtime->error && !jinx_zend_array_add_assoc(object->properties, "scalar", 6, converted))
                runtime->error = "native scalar-to-object cast failed";
            if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
        }
        return object_value;
    }
    runtime->error = "unsupported native cast";
    return jinx_value_null();
}

static JinxValue native_primary(NativeParser *parser) {
    NativeRuntime *runtime = parser->runtime;
    JinxValue value = jinx_value_null();
    if (runtime->error) return value;
    if (parser->kind == N_LITERAL) {
        value = parser->literal;
        native_next(parser);
    } else if (parser->kind == '[') {
        value = native_array(parser);
    } else if (parser->kind == N_VAR) {
        NativeParser peek = *parser;
        native_next(&peek);
        if (peek.kind == N_OBJECT || peek.kind == N_NULLSAFE) {
            if (!parser->checking) value = native_variable_read(parser, native_variable(runtime, parser->token));
            native_next(parser);
        } else {
            NativeSlot slot = native_slot(parser);
            value = native_slot_read(parser, slot);
        }
        if (native_accept(parser, '(')) {
            NativeCallArguments call = {0};
            native_parse_call_arguments(parser, &call);
            if (!parser->checking && !runtime->error && !runtime->exception_class) {
                if (value.type != N_VALUE_CLOSURE) native_raise(runtime, "Error", "Value is not callable");
                else value = native_function_call_named(parser, value.as.ptr, &call);
            }
        }
    } else if (parser->kind == N_ID && (!strcmp(parser->token, "function") || !strcmp(parser->token, "fn"))) {
        value = native_closure(parser);
    } else if (parser->kind == N_ID && !strcmp(parser->token, "clone")) {
        native_next(parser);
        value = native_primary(parser);
        if (!parser->checking && !runtime->error && !runtime->exception_class) value = native_clone(parser, value);
    } else if (native_accept(parser, '+')) {
        native_expect(parser, '+');
        NativeSlot slot = native_slot(parser);
        value = native_slot_read(parser, slot);
        if (!parser->checking && !runtime->error && !runtime->exception_class) {
            int64_t number;
            if (value.type != N_VALUE_INT || __builtin_add_overflow(value.as.i64, (int64_t)1, &number))
                runtime->error = "unsupported native prefix increment";
            else { value = jinx_value_int(number); native_slot_write(parser, slot, value); }
        }
    } else if (native_accept(parser, '@')) {
        /* Admitted filesystem handlers return false without host PHP warnings. */
        value = native_primary(parser);
    } else if (parser->kind == '(') {
        NativeParser look = *parser;
        native_next(&look);
        int cast = look.kind == N_ID &&
            (!strcmp(look.token, "int") || !strcmp(look.token, "string") ||
             !strcmp(look.token, "bool") || !strcmp(look.token, "array") ||
             !strcmp(look.token, "object"));
        char cast_name[256] = {0};
        if (cast) {
            strcpy(cast_name, look.token);
            native_next(&look);
            cast = look.kind == ')';
        }
        native_next(parser);
        if (cast) {
            native_next(parser);
            native_expect(parser, ')');
            value = native_primary(parser);
            if (!parser->checking && !runtime->error && !runtime->exception_class)
                value = native_cast(parser, cast_name, value);
        } else {
            value = native_expression(parser, 0);
            native_expect(parser, ')');
        }
    } else if (native_accept(parser, '-')) {
        value = native_primary(parser);
        if (!parser->checking && value.type != 1) runtime->error = "native unary minus requires integer";
        if (value.as.i64 == INT64_MIN) runtime->error = "native integer overflow";
        else value = jinx_value_int(-value.as.i64);
    } else if (parser->kind == N_ID) {
        char name[256];
        strcpy(name, parser->token);
        native_next(parser);
        if (!strcmp(name, "new")) {
            if (parser->kind != N_ID) runtime->error = "native new requires class name";
            char class_name[256];
            strcpy(class_name, parser->token);
            native_next(parser);
            native_expect(parser, '(');
            NativeCallArguments call = {0};
            native_parse_call_arguments(parser, &call);
            if (!runtime->error && !parser->checking) {
                const char *resolved_name = native_class_name_resolve(runtime, class_name);
                if (!resolved_name) native_raise(runtime, "Error", "Cannot resolve relative class name");
                else value = native_construct_named(parser, resolved_name, &call);
            }
        } else if (parser->kind == N_SCOPE) {
            NativeParser peek = *parser;
            native_next(&peek);
            if (peek.kind == N_ID && !strcasecmp(peek.token, "class")) {
                native_next(parser);
                native_next(parser);
                if (!parser->checking) {
                    const char *resolved_name = native_class_name_resolve(runtime, name);
                    if (!resolved_name) native_raise(runtime, "Error", "Cannot resolve relative class name");
                    else value = jinx_value_string(native_copy(runtime, resolved_name, strlen(resolved_name)), (uint32_t)strlen(resolved_name));
                }
            } else if (peek.kind == N_ID) {
                NativeParser after_member = peek;
                char method_name[256];
                strcpy(method_name, peek.token);
                native_next(&after_member);
                if (after_member.kind == '(') {
                    native_next(parser); /* :: */
                    native_next(parser); /* method */
                    native_expect(parser, '(');
                    NativeCallArguments call = {0};
                    native_parse_call_arguments(parser, &call);
                    if (!parser->checking && !runtime->error && !runtime->exception_class) {
                        NativeEnum *enum_entry = native_enum_find(runtime, name);
                        if (enum_entry) {
                            value = native_enum_static_call(parser, enum_entry, method_name, &call);
                        } else {
                        NativeClass *lookup_class = native_class_resolve(runtime, name);
                        if (!lookup_class) {
                            native_raise(runtime, "Error", "Class not found for static method call");
                        } else {
                            NativeFunction *method = native_method_find(lookup_class, method_name);
                            if (!method) {
                                NativeFunction *magic = native_method_find(lookup_class, "__callStatic");
                                if (!magic || !magic->is_static) native_raise(runtime, "Error", "Undefined static method");
                                else {
                                    JinxValue magic_args = native_call_arguments_array(parser, &call);
                                    NativeCallArguments forwarded = {0};
                                    forwarded.count = 2;
                                    forwarded.values[0] = jinx_value_string(native_copy(runtime, method_name, strlen(method_name)), (uint32_t)strlen(method_name));
                                    forwarded.values[1] = magic_args;
                                    NativeFunction frame = *magic;
                                    frame.called_class = !strcasecmp(name, "self") || !strcasecmp(name, "parent")
                                        ? (runtime->called_class ? runtime->called_class : lookup_class)
                                        : lookup_class;
                                    value = native_function_call_named(parser, &frame, &forwarded);
                                }
                            } else if (!method->is_static) native_raise(runtime, "Error", "Non-static method cannot be called statically");
                            else if (method->is_private && runtime->active_class != method->owner)
                                native_raise(runtime, "Error", "Cannot call private static method");
                            else {
                                NativeFunction frame = *method;
                                if (!strcasecmp(name, "self") || !strcasecmp(name, "parent"))
                                    frame.called_class = runtime->called_class ? runtime->called_class : lookup_class;
                                else
                                    frame.called_class = lookup_class;
                                value = native_function_call_named(parser, &frame, &call);
                            }
                        }
                        }
                    }
                } else {
                    native_next(parser); /* :: */
                    if (parser->kind != N_ID) {
                        runtime->error = "native static member requires name";
                    } else {
                        char member_name[256];
                        strcpy(member_name, parser->token);
                        native_next(parser);
                        if (!parser->checking && !runtime->error && !runtime->exception_class) {
                            NativeEnum *enum_entry = native_enum_find(runtime, name);
                            NativeEnumCase *case_entry = native_enum_case_find(enum_entry, member_name);
                            if (!case_entry) native_raise(runtime, "Error", "Undefined enum case or class constant");
                            else value = jinx_oracle_zend_object_value_borrowed(case_entry->object);
                        }
                    }
                }
            } else {
                NativeSlot slot = native_static_slot(parser, name);
                value = native_slot_read(parser, slot);
            }
        } else if (!strcmp(name, "isset")) {
            native_expect(parser, '(');
            NativeSlot slot = native_slot(parser);
            native_expect(parser, ')');
            if (!runtime->error && !runtime->exception_class)
                value = jinx_value_bool(native_slot_isset(parser, slot));
        } else if (!strcmp(name, "__DIR__")) value = jinx_value_string(parser->directory, (uint32_t)strlen(parser->directory));
        else if (!strcmp(name, "__FILE__")) value = jinx_value_string(parser->path, (uint32_t)strlen(parser->path));
        else if (!strcmp(name, "true")) value = jinx_value_bool(1);
        else if (!strcmp(name, "false")) value = jinx_value_bool(0);
        else if (!strcmp(name, "null")) value = jinx_value_null();
        else if (!strcmp(name, "E_ALL")) value = jinx_value_int(32767);
        else if (!strcmp(name, "include") || !strcmp(name, "require") ||
                 !strcmp(name, "include_once") || !strcmp(name, "require_once")) {
            JinxValue filename = native_expression(parser, 0);
            if (!parser->checking && !runtime->error) {
                if (filename.type != N_VALUE_STRING) runtime->error = "native include requires string path";
                else {
                    char resolved[PATH_MAX];
                    const char *target = filename.as.ptr;
                    if (target[0] != '/') {
                        if (snprintf(resolved, sizeof(resolved), "%s/%s", parser->directory, target) >= (int)sizeof(resolved))
                            runtime->error = "include path is too long";
                        target = resolved;
                    }
                    if (!runtime->error) native_file(runtime, target, strstr(name, "_once") != NULL, &value);
                }
            }
        } else {
            JinxValue args[32];
            size_t count = 0;
            int ok = 0;
            /* Start with proven scalar native handlers; widen after parity tests. */
            if (!native_builtin_admitted(name) && !(parser->checking
                    ? native_function_admitted(runtime, name) : native_function_find(runtime, name) != NULL))
                runtime->error = "builtin is not admitted by the native source interpreter";
            if (native_function_admitted(runtime, name) && !parser->strict_types)
                runtime->error = "native user function calls require strict_types=1";
            native_expect(parser, '(');
            NativeCallArguments call = {0};
            native_parse_call_arguments(parser, &call);
            count = call.count;
            for (size_t i = 0; i < count; i++) args[i] = call.values[i];
            if (!parser->checking && !runtime->error && !runtime->exception_class) {
                NativeFunction *function = native_function_find(runtime, name);
                if (function) {
                    value = native_function_call_named(parser, function, &call);
                } else if (call.saw_named) {
                    native_raise(runtime, "Error", "Named arguments for native builtins are not yet admitted");
                } else if (!strcmp(name, "array_map") || !strcmp(name, "array_reduce")) {
                    value = native_array_callback(parser, name, args, count);
                } else if (!strcmp(name, "array_sum")) {
                    value = native_array_sum_builtin(parser, args, count);
                } else if (!strcmp(name, "count")) {
                    value = native_count_builtin(parser, args, count);
                } else if (!strcmp(name, "call_user_func")) {
                    if (!count) native_raise(runtime, "ArgumentCountError", "call_user_func requires a callback");
                    else value = native_callback_call(parser, args[0], args + 1, count - 1);
                } else {
                    value = jinx_call_builtin_through_oracle_checked(name, args, count, &ok);
                    if (!ok) runtime->error = "native Oracle builtin call failed";
                }
                if (ok && value.type == N_VALUE_STRING) {
                    char *copy = native_copy(runtime, value.as.ptr, value.flags);
                    /* Native scalar handlers return borrowed scratch storage. */
                    value.as.ptr = copy;
                }
            }
        }
    } else runtime->error = "expression is not yet supported by native Oracle";
    int nullsafe_short = 0;
    while (!runtime->error && (parser->kind == N_OBJECT || parser->kind == N_SCOPE || parser->kind == N_NULLSAFE)) {
        int access = parser->kind;
        if (access == N_NULLSAFE && !parser->checking && !runtime->exception_class && value.type == 0)
            nullsafe_short = 1;
        int saved_checking = parser->checking;
        if (nullsafe_short) parser->checking = 1;
        native_next(parser);
        if (parser->kind != N_ID) { runtime->error = "native object postfix requires name"; parser->checking = saved_checking; break; }
        char member[256];
        strcpy(member, parser->token);
        native_next(parser);
        if (access == N_SCOPE) {
            if (strcmp(member, "class")) runtime->error = "only object class-name fetch is native";
            else if (!parser->checking && !runtime->exception_class) {
                if (value.type != JINX_ORACLE_VALUE_ZEND_OBJECT) native_raise(runtime, "TypeError", "class-name fetch requires object");
                else {
                    const char *class_name = ((JinxZendObject *)value.as.ptr)->class_name;
                    value = jinx_value_string(class_name, (uint32_t)strlen(class_name));
                }
            }
        } else if (native_accept(parser, '(')) {
            NativeCallArguments call = {0};
            native_parse_call_arguments(parser, &call);
            if (!parser->checking && !runtime->error && !runtime->exception_class)
                value = native_method_call_named(parser, value, member, &call);
        } else value = native_slot_read(parser, native_object_slot(parser, value, member));
        parser->checking = saved_checking;
        if (nullsafe_short) value = jinx_value_null();
    }
    return value;
}

static int native_precedence(int kind) {
    if (kind == N_COALESCE) return 5;
    if (kind == '.') return 10;
    if (kind == '+' || kind == '-') return 20;
    if (kind == '*' || kind == '%') return 30;
    return -1;
}

static int native_truth(JinxValue value) {
    if (!value.type) return 0;
    if (value.type == N_VALUE_INT || value.type == N_VALUE_BOOL) return value.as.i64 != 0;
    if (value.type == N_VALUE_FLOAT) return value.as.f64 != 0;
    if (value.type == N_VALUE_STRING) return value.flags && !(value.flags == 1 && ((char *)value.as.ptr)[0] == '0');
    if (value.type == JINX_ORACLE_VALUE_ZEND_ARRAY) return ((JinxZendArray *)value.as.ptr)->count != 0;
    return 1;
}

static JinxValue native_expression(NativeParser *parser, int minimum) {
    if (++parser->expression_depth > 128) {
        parser->runtime->error = "native expression nesting limit exceeded";
        parser->expression_depth--;
        return jinx_value_null();
    }
    JinxValue left = native_primary(parser);
    while (!parser->runtime->error && !parser->runtime->exception_class && native_precedence(parser->kind) >= minimum) {
        int operator = parser->kind;
        int precedence = native_precedence(operator);
        native_next(parser);
        int checking = parser->checking;
        int skip_right = operator == N_COALESCE && left.type != 0 && !checking;
        if (skip_right) parser->checking = 1;
        JinxValue right = native_expression(parser, precedence + (operator == N_COALESCE ? 0 : 1));
        parser->checking = checking;
        if (parser->checking || parser->runtime->error) continue;
        if (operator == N_COALESCE) {
            if (!skip_right) left = right;
        } else if (operator == '.') {
            size_t a, b;
            const char *first = native_text(parser, left, &a);
            const char *second = native_text(parser, right, &b);
            char *joined = native_alloc(parser->runtime, a + b + 1);
            if (!joined || !first || !second) {
                parser->expression_depth--;
                return jinx_value_null();
            }
            memcpy(joined, first, a);
            memcpy(joined + a, second, b);
            left = jinx_value_string(joined, (uint32_t)(a + b));
        } else {
            int64_t result = 0;
            int overflow = 0;
            if (left.type != 1 || right.type != 1) {
                parser->runtime->error = "native arithmetic currently requires integers";
                break;
            }
            if (operator == '+') overflow = __builtin_add_overflow(left.as.i64, right.as.i64, &result);
            if (operator == '-') overflow = __builtin_sub_overflow(left.as.i64, right.as.i64, &result);
            if (operator == '*') overflow = __builtin_mul_overflow(left.as.i64, right.as.i64, &result);
            if (operator == '%') {
                if (!right.as.i64) { parser->runtime->error = "Modulo by zero"; break; }
                result = right.as.i64 == -1 ? 0 : left.as.i64 % right.as.i64;
            }
            if (overflow) parser->runtime->error = "native integer overflow";
            left = jinx_value_int(result);
        }
    }
    if (!parser->runtime->error && !parser->runtime->exception_class && minimum <= 3 && native_accept(parser, '?')) {
        int checking = parser->checking;
        int truth = !checking && native_truth(left);
        JinxValue yes = left;
        if (parser->kind != ':') {
            parser->checking = checking || !truth;
            yes = native_expression(parser, 0);
        }
        parser->checking = checking;
        if (parser->runtime->error || parser->runtime->exception_class) {
            parser->expression_depth--;
            return jinx_value_null();
        }
        native_expect(parser, ':');
        parser->checking = checking || truth;
        JinxValue no = native_expression(parser, 4);
        parser->checking = checking;
        if (!checking) left = truth ? yes : no;
        if (!parser->runtime->exception_class && parser->kind == '?') parser->runtime->error = "unparenthesized nested native ternary is unsupported";
    }
    parser->expression_depth--;
    return left;
}

static void native_statements(NativeParser *parser, JinxValue *result);

enum { N_TYPE_SET = 1 << 30 };

static int native_type_bit(uint32_t type) {
    if (type == N_VALUE_CLOSURE) return 1 << 7;
    if (type == JINX_ORACLE_VALUE_ZEND_ARRAY) return 1 << 8;
    if (type == JINX_ORACLE_VALUE_ZEND_OBJECT) return 1 << 9;
    return type <= 6 ? 1 << type : 0;
}

static int native_type_matches(int type, JinxValue value) {
    return !type || ((type & N_TYPE_SET) ? (type & native_type_bit(value.type)) != 0 : value.type == (uint32_t)type);
}

static int native_function_type(NativeParser *parser) {
    int nullable = native_accept(parser, '?');
    int mask = nullable ? 1 : 0;
    int first = 0;
    int members = 0;
    if (!nullable && parser->kind == N_ID && !strcmp(parser->token, "mixed")) {
        native_next(parser);
        if (parser->kind == '|') parser->runtime->error = "mixed cannot be part of a native union";
        return 0;
    }
    if (parser->kind != N_ID) {
        if (nullable) parser->runtime->error = "nullable native type requires name";
        return 0;
    }
    do {
        int type = 0;
        if (parser->kind != N_ID) { parser->runtime->error = "native union requires type name"; break; }
        if (!strcmp(parser->token, "int")) type = N_VALUE_INT;
        else if (!strcmp(parser->token, "string")) type = N_VALUE_STRING;
        else if (!strcmp(parser->token, "bool")) type = N_VALUE_BOOL;
        else if (!strcmp(parser->token, "float")) type = N_VALUE_FLOAT;
        else if (!strcmp(parser->token, "array")) type = JINX_ORACLE_VALUE_ZEND_ARRAY;
        else if (!strcmp(parser->token, "object")) type = JINX_ORACLE_VALUE_ZEND_OBJECT;
        else if (!strcmp(parser->token, "mixed") || !strcmp(parser->token, "void")) type = 0;
        else if (!strcmp(parser->token, "iterable")) type = JINX_ORACLE_VALUE_ZEND_ARRAY;
        else if (!strcasecmp(parser->token, "Closure") || !strcmp(parser->token, "callable")) type = N_VALUE_CLOSURE;
        else if (!strcmp(parser->token, "static") || !strcmp(parser->token, "self") || !strcmp(parser->token, "parent")) type = JINX_ORACLE_VALUE_ZEND_OBJECT;
        else if (!strcmp(parser->token, "null")) type = 0;
        else parser->runtime->error = "native function type is not supported";
        int bit = native_type_bit((uint32_t)type);
        if (mask & bit) parser->runtime->error = "duplicate native union type";
        mask |= bit;
        if (!members) first = type;
        members++;
        native_next(parser);
    } while (!parser->runtime->error && native_accept(parser, '|'));
    if (nullable && members != 1) parser->runtime->error = "nullable shorthand cannot contain union";
    return nullable || members > 1 || !first ? N_TYPE_SET | mask : first;
}

static int native_parameter_type(NativeParser *parser, char **object_type) {
    NativeParser look = *parser;
    int nullable = native_accept(&look, '?');
    if (look.kind == N_ID) {
        const char *name = look.token;
        int builtin = !strcmp(name, "int") || !strcmp(name, "string") || !strcmp(name, "bool") ||
            !strcmp(name, "float") || !strcmp(name, "array") || !strcmp(name, "object") ||
            !strcmp(name, "mixed") || !strcmp(name, "iterable") || !strcasecmp(name, "Closure") ||
            !strcmp(name, "callable") || !strcmp(name, "static") || !strcmp(name, "self") ||
            !strcmp(name, "parent") || !strcmp(name, "null");
        NativeParser after = look;
        native_next(&after);
        if (!builtin && after.kind != '|') {
            if (nullable) native_expect(parser, '?');
            if (parser->kind != N_ID) {
                parser->runtime->error = "native object type requires class name";
                return 0;
            }
            *object_type = native_copy(parser->runtime, parser->token, strlen(parser->token));
            native_next(parser);
            return nullable
                ? (N_TYPE_SET | native_type_bit(0) | native_type_bit(JINX_ORACLE_VALUE_ZEND_OBJECT))
                : JINX_ORACLE_VALUE_ZEND_OBJECT;
        }
    }
    return native_function_type(parser);
}

static void native_function_declaration(NativeParser *parser, NativeClass *owner, int method, int is_private, int is_static) {
    if (!parser->strict_types) { parser->runtime->error = "native function declarations require strict_types=1"; return; }
    native_next(parser);
    if (parser->kind != N_ID) { parser->runtime->error = "native function requires name"; return; }
    NativeFunction definition = {0};
    definition.owner = owner;
    definition.is_private = is_private;
    definition.is_static = is_static;
    definition.name = native_copy(parser->runtime, parser->token, strlen(parser->token));
    native_next(parser);
    native_expect(parser, '(');
    if (parser->kind != ')') do {
        if (definition.count == 16) { parser->runtime->error = "native function parameter limit"; return; }
        size_t index = definition.count++;
        if (parser->kind == N_ID && (!strcmp(parser->token, "public") || !strcmp(parser->token, "private"))) {
            if (!method || strcmp(definition.name, "__construct")) {
                parser->runtime->error = "native promotion requires constructor"; return;
            }
            definition.promoted[index] = !strcmp(parser->token, "private") ? 2 : 1;
            native_next(parser);
        }
        definition.types[index] = native_parameter_type(parser, &definition.object_types[index]);
        int variadic = native_accept(parser, N_ELLIPSIS);
        if (variadic) {
            if (definition.variadic_index_plus_one) { parser->runtime->error = "duplicate native variadic parameter"; return; }
            if (definition.promoted[index]) { parser->runtime->error = "native promoted parameter cannot be variadic"; return; }
            definition.variadic_index_plus_one = (unsigned)index + 1;
        } else if (definition.variadic_index_plus_one) {
            parser->runtime->error = "native variadic parameter must be last"; return;
        }
        if (parser->kind != N_VAR) { parser->runtime->error = "native function requires parameter"; return; }
        definition.parameters[index] = native_copy(parser->runtime, parser->token, strlen(parser->token));
        for (size_t i = 0; i < index; i++)
            if (!strcmp(definition.parameters[i], definition.parameters[index]))
                parser->runtime->error = "duplicate native function parameter";
        native_next(parser);
        if (native_accept(parser, '=')) {
            if (variadic) { parser->runtime->error = "native variadic parameter cannot have a default"; return; }
            definition.has_default[index] = 1;
            definition.defaults[index] = native_expression(parser, 0);
        }
    } while (native_accept(parser, ','));
    native_expect(parser, ')');
    if (native_accept(parser, ':')) definition.return_type = native_function_type(parser);
    native_expect(parser, '{');
    definition.body = *parser;
    if (parser->checking && !method && !parser->runtime->error) {
        NativeFunction *admitted = native_alloc(parser->runtime, sizeof(*admitted));
        if (!admitted) return;
        *admitted = definition;
        admitted->next = parser->runtime->checked_functions;
        parser->runtime->checked_functions = admitted;
    }
    NativeParser end = *parser;
    end.checking = 1;
    JinxValue ignored = jinx_value_null();
    native_statements(&end, &ignored);
    native_expect(&end, '}');
    int checking = parser->checking;
    *parser = end;
    parser->checking = checking;
    if (!checking && !parser->runtime->error) {
        if (!method && native_function_find(parser->runtime, definition.name)) {
            native_raise(parser->runtime, "Error", "Function already declared");
            return;
        }
        NativeFunction *function = native_alloc(parser->runtime, sizeof(*function));
        if (!function) return;
        *function = definition;
        if (method) {
            for (size_t i = 0; i < function->count; i++) if (function->promoted[i]) {
                if (native_property_find(owner, function->parameters[i], 0)) {
                    parser->runtime->error = "duplicate native promoted property"; return;
                }
                NativeProperty *property = native_alloc(parser->runtime, sizeof(*property));
                if (!property) return;
                property->name = function->parameters[i];
                property->type = function->types[i];
                property->object_type = function->object_types[i];
                property->is_private = function->promoted[i] == 2;
                property->is_readonly = owner && owner->is_readonly;
                property->owner = owner;
                property->initialized = !property->type;
                property->next = owner->properties;
                owner->properties = property;
            }
            function->next = owner->methods;
            owner->methods = function;
        } else {
            function->next = parser->runtime->functions;
            parser->runtime->functions = function;
        }
    }
}

static JinxValue native_closure(NativeParser *parser) {
    JinxValue result = jinx_value_null();
    if (!parser->strict_types) { parser->runtime->error = "native closures require strict_types=1"; return result; }
    NativeFunction *function = native_alloc(parser->runtime, sizeof(*function));
    if (!function) return result;
    function->is_arrow = !strcmp(parser->token, "fn");
    if (!parser->checking) {
        function->owner = parser->runtime->active_class;
        for (NativeVariable *variable = parser->runtime->variables; variable; variable = variable->next)
            if (!strcmp(variable->name, "this")) function->bound_this = native_variable_read(parser, variable);
    }
    native_next(parser);
    native_expect(parser, '(');
    if (parser->kind != ')') do {
        if (function->count == 16) { parser->runtime->error = "native closure parameter limit"; return result; }
        size_t index = function->count++;
        function->types[index] = native_parameter_type(parser, &function->object_types[index]);
        int variadic = native_accept(parser, N_ELLIPSIS);
        if (variadic) {
            if (function->variadic_index_plus_one) { parser->runtime->error = "duplicate native variadic parameter"; return result; }
            function->variadic_index_plus_one = (unsigned)index + 1;
        } else if (function->variadic_index_plus_one) {
            parser->runtime->error = "native variadic parameter must be last"; return result;
        }
        if (parser->kind != N_VAR) { parser->runtime->error = "native closure requires parameter"; return result; }
        function->parameters[index] = native_copy(parser->runtime, parser->token, strlen(parser->token));
        native_next(parser);
        if (native_accept(parser, '=')) {
            if (variadic) { parser->runtime->error = "native variadic parameter cannot have a default"; return result; }
            function->has_default[index] = 1;
            function->defaults[index] = native_expression(parser, 0);
        }
    } while (native_accept(parser, ','));
    native_expect(parser, ')');
    if (parser->kind == N_ID && !strcmp(parser->token, "use")) {
        native_next(parser);
        native_expect(parser, '(');
        do {
            int reference = native_accept(parser, '&');
            if (parser->kind != N_VAR) { parser->runtime->error = "native closure capture requires variable"; return result; }
            char name[256];
            strcpy(name, parser->token);
            NativeSlot slot = native_slot(parser);
            if (!parser->checking && !parser->runtime->error) {
                NativeVariable *capture = native_alloc(parser->runtime, sizeof(*capture));
                if (!capture) return result;
                capture->name = native_copy(parser->runtime, name, strlen(name));
                if (reference) capture->reference = native_slot_reference(parser, slot);
                else capture->value = native_value_copy(parser, native_slot_read(parser, slot));
                capture->next = function->captures;
                function->captures = capture;
            }
        } while (native_accept(parser, ','));
        native_expect(parser, ')');
    }
    if (native_accept(parser, ':')) function->return_type = native_function_type(parser);
    if (function->is_arrow && !parser->checking) {
        for (NativeVariable *variable = parser->runtime->variables; variable; variable = variable->next) {
            int parameter = 0;
            for (size_t i = 0; i < function->count; i++)
                if (!strcmp(variable->name, function->parameters[i])) parameter = 1;
            if (parameter || !strcmp(variable->name, "this")) continue;
            NativeVariable *capture = native_alloc(parser->runtime, sizeof(*capture));
            if (!capture) return result;
            capture->name = variable->name;
            capture->value = native_value_copy(parser, native_variable_read(parser, variable));
            capture->next = function->captures;
            function->captures = capture;
        }
    }
    native_expect(parser, function->is_arrow ? N_ARROW : '{');
    function->body = *parser;
    NativeParser end = *parser;
    end.checking = 1;
    JinxValue ignored = jinx_value_null();
    if (function->is_arrow) ignored = native_expression(&end, 0);
    else { native_statements(&end, &ignored); native_expect(&end, '}'); }
    int checking = parser->checking;
    *parser = end;
    parser->checking = checking;
    if (!checking && !parser->runtime->error) { result.type = N_VALUE_CLOSURE; result.as.ptr = function; }
    return result;
}

static JinxValue native_function_call_named(NativeParser *parser, NativeFunction *function, NativeCallArguments *call) {
    NativeRuntime *runtime = parser->runtime;
    JinxValue result = jinx_value_null();
    JinxValue bound[16];
    unsigned char provided[16] = {0};
    for (size_t i = 0; i < 16; i++) bound[i] = jinx_value_null();

    size_t variadic_index = function->variadic_index_plus_one ? function->variadic_index_plus_one - 1 : (size_t)-1;
    JinxZendArray *variadic = NULL;
    if (function->variadic_index_plus_one) {
        NativeArray *owner = native_alloc(runtime, sizeof(*owner));
        if (!owner) return result;
        owner->array = jinx_zend_array_new_packed(4);
        if (!owner->array) { runtime->error = "native variadic array allocation failed"; return result; }
        owner->next = runtime->arrays;
        runtime->arrays = owner;
        variadic = owner->array;
        bound[variadic_index] = jinx_oracle_zend_array_value_borrowed(variadic);
        provided[variadic_index] = 1;
    }

    size_t positional = 0;
    for (size_t a = 0; a < call->count && !runtime->error && !runtime->exception_class; a++) {
        const char *name = call->names[a];
        if (name) {
            size_t target = (size_t)-1;
            for (size_t i = 0; i < function->count; i++) {
                if (i != variadic_index && !strcmp(function->parameters[i], name)) { target = i; break; }
            }
            if (target != (size_t)-1) {
                if (provided[target]) {
                    native_raise(runtime, "Error", "Named parameter overwrites previous argument");
                    break;
                }
                bound[target] = call->values[a];
                provided[target] = 1;
            } else if (variadic) {
                JinxZendValue converted = native_zend_value(parser, call->values[a]);
                if (!runtime->error && !jinx_zend_array_add_assoc(variadic, name, strlen(name), converted))
                    runtime->error = "native named variadic insertion failed";
                if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
            } else {
                native_raise(runtime, "Error", "Unknown named parameter");
                break;
            }
            continue;
        }

        while (positional < function->count && (positional == variadic_index || provided[positional])) positional++;
        if (positional < function->count) {
            bound[positional] = call->values[a];
            provided[positional] = 1;
            positional++;
        } else if (variadic) {
            JinxZendValue converted = native_zend_value(parser, call->values[a]);
            if (!runtime->error && !jinx_zend_array_append(variadic, converted))
                runtime->error = "native variadic append failed";
            if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
        } /* PHP permits extra positional args for user functions; they are ignored here until func_get_args is native. */
    }

    for (size_t i = 0; i < function->count && !runtime->error && !runtime->exception_class; i++) {
        if (i == variadic_index) continue;
        if (!provided[i]) {
            if (function->has_default[i]) {
                bound[i] = native_value_copy(parser, function->defaults[i]);
                provided[i] = 1;
            } else {
                native_raise(runtime, "ArgumentCountError", "Missing function argument");
                break;
            }
        }
        if (!native_type_matches(function->types[i], bound[i]) ||
            (function->object_types[i] && bound[i].type != 0 &&
             !native_object_matches(runtime, bound[i], function->object_types[i]))) {
            native_raise(runtime, "TypeError", "Invalid function argument type");
            break;
        }
    }

    if (variadic && !runtime->error && !runtime->exception_class) {
        for (size_t i = 0; i < variadic->count; i++) {
            const JinxZendBucket *bucket = jinx_zend_array_iter_at(variadic, i);
            if (!bucket) continue;
            JinxValue item = native_from_zend(parser, bucket->value);
            if (!native_type_matches(function->types[variadic_index], item) ||
                (function->object_types[variadic_index] && item.type != 0 &&
                 !native_object_matches(runtime, item, function->object_types[variadic_index]))) {
                native_raise(runtime, "TypeError", "Invalid variadic argument type");
                break;
            }
        }
    }
    if (runtime->error || runtime->exception_class) return result;

    if (runtime->call_depth >= 64) { runtime->error = "native function call depth limit"; return result; }
    NativeVariable *saved = runtime->variables;
    NativeClass *saved_class = runtime->active_class;
    NativeClass *saved_called_class = runtime->called_class;
    runtime->active_class = function->owner;
    if (function->owner) runtime->called_class = function->called_class ? function->called_class : function->owner;
    runtime->variables = NULL;
    runtime->call_depth++;
    NativeParser body = function->body;
    body.checking = 0;
    body.returned = 0;
    if (function->bound_this.type == JINX_ORACLE_VALUE_ZEND_OBJECT) {
        NativeVariable *self = native_variable(runtime, "this");
        if (self) self->value = function->bound_this;
    }
    for (NativeVariable *capture = function->captures; capture && !runtime->error; capture = capture->next) {
        NativeVariable *local = native_variable(runtime, capture->name);
        if (!local) break;
        local->reference = capture->reference;
        if (!capture->reference) local->value = native_value_copy(&body, capture->value);
    }
    for (size_t i = 0; i < function->count && !runtime->error; i++) {
        NativeVariable *parameter = native_variable(runtime, function->parameters[i]);
        if (parameter) parameter->value = native_value_copy(&body, bound[i]);
        if (function->promoted[i])
            native_slot_write(&body, native_object_slot(&body, function->bound_this, function->parameters[i]), bound[i]);
    }
    if (function->is_arrow) result = native_expression(&body, 0);
    else native_statements(&body, &result);
    runtime->variables = saved;
    runtime->active_class = saved_class;
    runtime->called_class = saved_called_class;
    runtime->call_depth--;
    if (!runtime->error && !runtime->exception_class && !native_type_matches(function->return_type, result))
        native_raise(runtime, "TypeError", "Invalid function return type");
    return result;
}

static JinxValue native_function_call(NativeParser *parser, NativeFunction *function, JinxValue *args, size_t count) {
    NativeCallArguments call = {0};
    if (count > 32) { parser->runtime->error = "native call argument limit"; return jinx_value_null(); }
    call.count = count;
    for (size_t i = 0; i < count; i++) call.values[i] = args[i];
    return native_function_call_named(parser, function, &call);
}

static NativeFunction *native_method_find(NativeClass *owner, const char *name) {
    for (; owner; owner = owner->parent)
        for (NativeFunction *method = owner->methods; method; method = method->next)
            if (!strcasecmp(method->name, name)) return method;
    return NULL;
}

static JinxValue native_construct_named(NativeParser *parser, const char *name, NativeCallArguments *call) {
    if (call->count && !parser->strict_types) { parser->runtime->error = "native constructor arguments require strict_types=1"; return jinx_value_null(); }
    JinxValue object = native_instance(parser, name, 0);
    if (parser->checking || parser->runtime->error || parser->runtime->exception_class) return object;
    NativeClass *owner = native_class_find(parser->runtime, name);
    if (native_method_find(owner, "__construct")) (void)native_method_call_named(parser, object, "__construct", call);
    return object;
}

static JinxValue native_construct(NativeParser *parser, const char *name, JinxValue *args, size_t count) {
    NativeCallArguments call = {0};
    if (count > 32) { parser->runtime->error = "native constructor argument limit"; return jinx_value_null(); }
    call.count = count;
    for (size_t i = 0; i < count; i++) call.values[i] = args[i];
    return native_construct_named(parser, name, &call);
}

typedef struct NativeCallback {
    NativeFunction frame;
    const char *builtin;
    int is_function;
    int weak;
} NativeCallback;

static NativeCallback native_callback_resolve(NativeParser *parser, JinxValue value) {
    NativeCallback callback = {0};
    NativeFunction *function = NULL;
    if (value.type == N_VALUE_CLOSURE) function = value.as.ptr;
    else if (value.type == N_VALUE_STRING) {
        if (strstr(value.as.ptr, "::")) {
            parser->runtime->error = "native string method callbacks are not yet supported"; return callback;
        }
        function = native_function_find(parser->runtime, value.as.ptr);
        if (!function && native_builtin_admitted(value.as.ptr) && strcmp(value.as.ptr, "call_user_func") &&
            strcmp(value.as.ptr, "array_map") && strcmp(value.as.ptr, "array_reduce")) callback.builtin = value.as.ptr;
        if (!function && !callback.builtin && jinx_lookup_oracle_wrapper(value.as.ptr)) {
            parser->runtime->error = "callback builtin is not yet admitted by native interpreter"; return callback;
        }
    } else if (value.type == JINX_ORACLE_VALUE_ZEND_ARRAY) {
        JinxZendArray *array = value.as.ptr;
        NativeSlot first = {0}, second = {0};
        first.array = second.array = array;
        first.key = jinx_value_int(0);
        second.key = jinx_value_int(1);
        JinxZendValue *target = native_slot_element(parser, first, 0);
        JinxZendValue *method_name = native_slot_element(parser, second, 0);
        if (array->count == 2 && target && method_name) {
            JinxValue receiver = native_from_zend(parser, *target);
            JinxValue name = native_from_zend(parser, *method_name);
            NativeClass *owner = NULL;
            if (receiver.type == N_VALUE_STRING) owner = native_class_find(parser->runtime, receiver.as.ptr);
            else if (receiver.type == JINX_ORACLE_VALUE_ZEND_OBJECT)
                owner = native_class_find(parser->runtime, ((JinxZendObject *)receiver.as.ptr)->class_name);
            if (owner && name.type == N_VALUE_STRING) function = native_method_find(owner, name.as.ptr);
            if (function && ((!function->is_static && receiver.type != JINX_ORACLE_VALUE_ZEND_OBJECT) ||
                (function->is_private && parser->runtime->active_class != function->owner))) function = NULL;
            if (function && !function->is_static) callback.frame.bound_this = receiver;
        }
    }
    if (function) {
        JinxValue bound = callback.frame.bound_this;
        callback.frame = *function;
        if (bound.type == JINX_ORACLE_VALUE_ZEND_OBJECT) callback.frame.bound_this = bound;
        callback.is_function = 1;
    } else if (!callback.builtin) native_raise(parser->runtime, "TypeError", "Invalid native callback");
    return callback;
}

static JinxValue native_callback_invoke(NativeParser *parser, NativeCallback *callback, JinxValue *args, size_t count) {
    if (callback->is_function) {
        JinxValue converted[16];
        if (callback->weak) {
            for (size_t i = 0; i < count; i++) {
                converted[i] = args[i];
                int type = i < callback->frame.count ? callback->frame.types[i] : 0;
                if ((type & N_TYPE_SET) && !native_type_matches(type, args[i])) {
                    parser->runtime->error = "native weak union callback coercion is not yet supported"; return jinx_value_null();
                }
                if (type == N_VALUE_INT && args[i].type == N_VALUE_BOOL) converted[i] = jinx_value_int(args[i].as.i64);
                else if (type == N_VALUE_INT && args[i].type == N_VALUE_STRING) {
                    char *end;
                    errno = 0;
                    long long number = strtoll(args[i].as.ptr, &end, 10);
                    int parsed = end != args[i].as.ptr;
                    while (isspace((unsigned char)*end)) end++;
                    if (errno == ERANGE) { parser->runtime->error = "native callback numeric range is unsupported"; return jinx_value_null(); }
                    if (parsed && (size_t)(end - (char *)args[i].as.ptr) == args[i].flags) converted[i] = jinx_value_int(number);
                    else {
                        char *numeric_end;
                        (void)strtod(args[i].as.ptr, &numeric_end);
                        int numeric = numeric_end != args[i].as.ptr;
                        while (isspace((unsigned char)*numeric_end)) numeric_end++;
                        if (numeric && (size_t)(numeric_end - (char *)args[i].as.ptr) == args[i].flags) {
                            parser->runtime->error = "native callback decimal coercion is not yet supported"; return jinx_value_null();
                        }
                    }
                } else if (type == N_VALUE_STRING && (args[i].type == N_VALUE_INT || args[i].type == N_VALUE_BOOL)) {
                    size_t length;
                    const char *text = native_text(parser, args[i], &length);
                    converted[i] = jinx_value_string(native_copy(parser->runtime, text, length), (uint32_t)length);
                } else if (type == N_VALUE_BOOL && args[i].type == N_VALUE_INT) converted[i] = jinx_value_bool(args[i].as.i64 != 0);
                else if (type == N_VALUE_BOOL && args[i].type == N_VALUE_STRING)
                    converted[i] = jinx_value_bool(args[i].flags && !(args[i].flags == 1 && ((char *)args[i].as.ptr)[0] == '0'));
            }
            args = converted;
        }
        return native_function_call(parser, &callback->frame, args, count);
    }
    int ok = 0;
    JinxValue result = jinx_call_builtin_through_oracle_checked(callback->builtin, args, count, &ok);
    if (!ok) native_raise(parser->runtime, "ArgumentCountError", "Invalid callback argument count");
    if (ok && result.type == N_VALUE_STRING) result.as.ptr = native_copy(parser->runtime, result.as.ptr, result.flags);
    return result;
}

static JinxValue native_callback_call(NativeParser *parser, JinxValue value, JinxValue *args, size_t count) {
    NativeCallback callback = native_callback_resolve(parser, value);
    if (parser->runtime->error || parser->runtime->exception_class) return jinx_value_null();
    return native_callback_invoke(parser, &callback, args, count);
}

static JinxValue native_array_sum_builtin(NativeParser *parser, JinxValue *args, size_t count) {
    if (count != 1) {
        native_raise(parser->runtime, "ArgumentCountError", "array_sum expects exactly one argument");
        return jinx_value_null();
    }
    if (args[0].type != JINX_ORACLE_VALUE_ZEND_ARRAY) {
        native_raise(parser->runtime, "TypeError", "array_sum expects array");
        return jinx_value_null();
    }
    JinxZendArray *array = args[0].as.ptr;
    int have_float = 0;
    double floating = 0.0;
    int64_t integer = 0;
    for (size_t n = 0; n < array->count; n++) {
        const JinxZendBucket *bucket = jinx_zend_array_iter_at(array, n);
        if (!bucket) continue;
        JinxValue value = native_from_zend(parser, bucket->value);
        if (value.type == N_VALUE_INT || value.type == N_VALUE_BOOL) {
            if (have_float) floating += (double)value.as.i64;
            else {
                int64_t next;
                if (__builtin_add_overflow(integer, value.as.i64, &next)) {
                    floating = (double)integer + (double)value.as.i64;
                    have_float = 1;
                } else integer = next;
            }
        } else if (value.type == N_VALUE_FLOAT) {
            if (!have_float) { floating = (double)integer; have_float = 1; }
            floating += value.as.f64;
        } else if (value.type == N_VALUE_STRING) {
            char *end = NULL;
            errno = 0;
            double parsed = strtod(value.as.ptr, &end);
            if (end != value.as.ptr) {
                while (isspace((unsigned char)*end)) end++;
                if (!errno && (size_t)(end - (char *)value.as.ptr) == value.flags) {
                    if (!have_float) { floating = (double)integer; have_float = 1; }
                    floating += parsed;
                }
            }
        }
    }
    return have_float ? jinx_value_float(floating) : jinx_value_int(integer);
}

static JinxValue native_count_builtin(NativeParser *parser, JinxValue *args, size_t count) {
    if (count < 1 || count > 2) {
        native_raise(parser->runtime, "ArgumentCountError", "count expects one or two arguments");
        return jinx_value_null();
    }
    if (args[0].type == JINX_ORACLE_VALUE_ZEND_ARRAY)
        return jinx_value_int((int64_t)((JinxZendArray *)args[0].as.ptr)->count);
    native_raise(parser->runtime, "TypeError", "count expects array or Countable");
    return jinx_value_null();
}

static JinxValue native_enum_static_call(NativeParser *parser, NativeEnum *entry, const char *method, NativeCallArguments *call) {
    if (!entry) {
        native_raise(parser->runtime, "Error", "Enum not found");
        return jinx_value_null();
    }
    if (!strcmp(method, "cases")) {
        if (call->count) {
            native_raise(parser->runtime, "ArgumentCountError", "cases expects no arguments");
            return jinx_value_null();
        }
        NativeArray *owner = native_alloc(parser->runtime, sizeof(*owner));
        if (!owner) return jinx_value_null();
        owner->array = jinx_zend_array_new_packed(4);
        if (!owner->array) { parser->runtime->error = "native enum cases array allocation failed"; return jinx_value_null(); }
        owner->next = parser->runtime->arrays;
        parser->runtime->arrays = owner;
        for (NativeEnumCase *case_entry = entry->cases; case_entry; case_entry = case_entry->next) {
            JinxZendValue converted = jinx_zend_object_value(case_entry->object);
            if (!jinx_zend_array_append(owner->array, converted)) {
                parser->runtime->error = "native enum cases append failed";
                return jinx_value_null();
            }
        }
        return jinx_oracle_zend_array_value_borrowed(owner->array);
    }
    if (!strcmp(method, "from") || !strcmp(method, "tryFrom")) {
        int try_from = !strcmp(method, "tryFrom");
        if (!entry->backing_type) {
            native_raise(parser->runtime, "Error", "Unit enum has no from/tryFrom");
            return jinx_value_null();
        }
        if (call->count != 1 || (call->names[0] && strcmp(call->names[0], "value"))) {
            native_raise(parser->runtime, call->count == 1 ? "Error" : "ArgumentCountError", "Invalid enum from/tryFrom arguments");
            return jinx_value_null();
        }
        JinxValue sought = call->values[0];
        if ((entry->backing_type == N_VALUE_STRING && sought.type != N_VALUE_STRING) ||
            (entry->backing_type == N_VALUE_INT && sought.type != N_VALUE_INT)) {
            native_raise(parser->runtime, "TypeError", "Enum backing value has wrong type");
            return jinx_value_null();
        }
        for (NativeEnumCase *case_entry = entry->cases; case_entry; case_entry = case_entry->next)
            if (native_strict_equal(case_entry->backing, sought))
                return jinx_oracle_zend_object_value_borrowed(case_entry->object);
        if (try_from) return jinx_value_null();
        native_raise(parser->runtime, "ValueError", "Value is not a valid backing value for enum");
        return jinx_value_null();
    }
    native_raise(parser->runtime, "Error", "Undefined enum static method");
    return jinx_value_null();
}

static JinxValue native_array_callback(NativeParser *parser, const char *name, JinxValue *args, size_t count) {
    int reduce = !strcmp(name, "array_reduce");
    if (count < 2 || (reduce && count > 3)) {
        native_raise(parser->runtime, "ArgumentCountError", "Invalid array callback argument count"); return jinx_value_null();
    }
    if (!reduce && count > 2) { parser->runtime->error = "native array_map supports one input array"; return jinx_value_null(); }
    if (!reduce && args[0].type == 0) {
        if (args[1].type != JINX_ORACLE_VALUE_ZEND_ARRAY) {
            native_raise(parser->runtime, "TypeError", "Array callback requires array"); return jinx_value_null();
        }
        return native_value_copy(parser, args[1]);
    }
    NativeCallback callback = native_callback_resolve(parser, args[reduce ? 1 : 0]);
    callback.weak = 1;
    if (parser->runtime->error || parser->runtime->exception_class) return jinx_value_null();
    JinxValue input = args[reduce ? 0 : 1];
    if (input.type != JINX_ORACLE_VALUE_ZEND_ARRAY) {
        native_raise(parser->runtime, "TypeError", "Array callback requires array"); return jinx_value_null();
    }
    JinxZendArray *array = input.as.ptr;
    JinxValue result = reduce && count == 3 ? args[2] : jinx_value_null();
    NativeArray *owner = NULL;
    if (!reduce) {
        owner = native_alloc(parser->runtime, sizeof(*owner));
        if (!owner) return result;
        owner->array = jinx_zend_array_new_packed(array->count);
        if (!owner->array) { parser->runtime->error = "native callback array allocation failed"; return result; }
        owner->next = parser->runtime->arrays;
        parser->runtime->arrays = owner;
        result = jinx_oracle_zend_array_value_borrowed(owner->array);
    }
    size_t length = array->count;
    for (size_t i = 0; i < length && !parser->runtime->error && !parser->runtime->exception_class; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_iter_at(array, i);
        if (!bucket) continue;
        JinxValue arguments[2] = {result, native_from_zend(parser, bucket->value)};
        JinxValue mapped = native_callback_invoke(parser, &callback, reduce ? arguments : arguments + 1, reduce ? 2 : 1);
        if (parser->runtime->error || parser->runtime->exception_class) break;
        if (reduce) result = mapped;
        else {
            JinxZendValue converted = native_zend_value(parser, native_value_copy(parser, mapped));
            if (parser->runtime->error) break;
            int ok = bucket->key ? jinx_zend_array_add_symtable(owner->array, bucket->key->bytes, bucket->key->len, converted)
                : jinx_zend_array_add_index(owner->array, (size_t)bucket->h, converted);
            if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
            if (!ok) parser->runtime->error = "native callback array insertion failed";
        }
        if (array->count != length) parser->runtime->error = "native callback structural mutation is not supported";
    }
    return result;
}

static JinxValue native_method_call_named(NativeParser *parser, JinxValue object, const char *name, NativeCallArguments *call) {
    if (object.type != JINX_ORACLE_VALUE_ZEND_OBJECT) {
        native_raise(parser->runtime, "Error", "Method call requires object"); return jinx_value_null();
    }
    JinxZendObject *instance = object.as.ptr;
    NativeClass *dispatch_class = native_class_find(parser->runtime, instance->class_name);
    NativeFunction *method = native_method_find(dispatch_class, name);
    if (!method) {
        NativeFunction *magic = native_method_find(dispatch_class, "__call");
        if (!magic) { native_raise(parser->runtime, "Error", "Undefined method"); return jinx_value_null(); }
        JinxValue magic_args = native_call_arguments_array(parser, call);
        if (parser->runtime->error) return jinx_value_null();
        NativeCallArguments forwarded = {0};
        forwarded.count = 2;
        forwarded.values[0] = jinx_value_string(native_copy(parser->runtime, name, strlen(name)), (uint32_t)strlen(name));
        forwarded.values[1] = magic_args;
        NativeFunction frame = *magic;
        frame.called_class = dispatch_class;
        frame.bound_this = object;
        return native_function_call_named(parser, &frame, &forwarded);
    }
    if (method->is_private && parser->runtime->active_class != method->owner) {
        native_raise(parser->runtime, "Error", "Cannot call private method"); return jinx_value_null();
    }
    NativeFunction frame = *method;
    frame.called_class = native_class_find(parser->runtime, instance->class_name);
    if (!method->is_static) frame.bound_this = object;
    return native_function_call_named(parser, &frame, call);
}

static JinxValue native_method_call(NativeParser *parser, JinxValue object, const char *name, JinxValue *args, size_t count) {
    NativeCallArguments call = {0};
    if (count > 32) { parser->runtime->error = "native method argument limit"; return jinx_value_null(); }
    call.count = count;
    for (size_t i = 0; i < count; i++) call.values[i] = args[i];
    return native_method_call_named(parser, object, name, &call);
}

static JinxValue native_clone(NativeParser *parser, JinxValue value) {
    if (value.type != JINX_ORACLE_VALUE_ZEND_OBJECT) {
        native_raise(parser->runtime, "Error", "Clone requires object"); return jinx_value_null();
    }
    JinxZendObject *source = value.as.ptr;
    NativeClass *owner = native_class_find(parser->runtime, source->class_name);
    if (!owner) { parser->runtime->error = "native clone requires user class"; return jinx_value_null(); }
    NativeObject *copy = native_alloc(parser->runtime, sizeof(*copy));
    if (!copy) return jinx_value_null();
    copy->object = jinx_zend_object_new(source->class_name);
    if (!copy->object) { parser->runtime->error = "native clone allocation failed"; return jinx_value_null(); }
    copy->next = parser->runtime->objects;
    parser->runtime->objects = copy;
    JinxZendArray *properties = jinx_zend_array_clone(source->properties);
    if (!properties) { parser->runtime->error = "native clone property copy failed"; return jinx_value_null(); }
    jinx_zend_array_release(copy->object->properties);
    copy->object->properties = properties;
    JinxValue result = jinx_oracle_zend_object_value_borrowed(copy->object);
    if (native_method_find(owner, "__clone")) (void)native_method_call(parser, result, "__clone", NULL, 0);
    return result;
}

static void native_enum_declaration(NativeParser *parser) {
    NativeRuntime *runtime = parser->runtime;
    native_next(parser);
    if (parser->kind != N_ID) { runtime->error = "native enum requires name"; return; }
    char enum_name[256];
    strcpy(enum_name, parser->token);
    native_next(parser);

    int backing_type = 0;
    if (native_accept(parser, ':')) {
        if (parser->kind != N_ID || (strcmp(parser->token, "string") && strcmp(parser->token, "int"))) {
            runtime->error = "native backed enum requires string or int";
            return;
        }
        backing_type = !strcmp(parser->token, "string") ? N_VALUE_STRING : N_VALUE_INT;
        native_next(parser);
    }

    NativeEnum *entry = NULL;
    if (!parser->checking) {
        if (native_enum_find(runtime, enum_name) || native_class_find(runtime, enum_name)) {
            native_raise(runtime, "Error", "Enum name is already in use");
            return;
        }
        entry = native_alloc(runtime, sizeof(*entry));
        if (!entry) return;
        entry->name = native_copy(runtime, enum_name, strlen(enum_name));
        entry->backing_type = backing_type;
        entry->next = runtime->enums;
        runtime->enums = entry;
    }

    native_expect(parser, '{');
    while (parser->kind != '}' && !runtime->error && (parser->checking || !runtime->exception_class)) {
        if (parser->kind != N_ID || strcmp(parser->token, "case")) {
            runtime->error = "native enum currently supports case declarations";
            break;
        }
        native_next(parser);
        if (parser->kind != N_ID) { runtime->error = "native enum case requires name"; break; }
        char case_name[256];
        strcpy(case_name, parser->token);
        native_next(parser);

        int has_backing = native_accept(parser, '=');
        JinxValue backing = has_backing ? native_expression(parser, 0) : jinx_value_null();
        native_expect(parser, ';');

        if ((backing_type && !has_backing) || (!backing_type && has_backing)) {
            runtime->error = backing_type ? "backed enum case requires value" : "unit enum case cannot have value";
            break;
        }
        if (backing_type && backing.type != (uint32_t)backing_type) {
            native_raise(runtime, "TypeError", "Enum case backing value has wrong type");
            break;
        }

        if (!parser->checking && entry && !runtime->exception_class) {
            if (native_enum_case_find(entry, case_name)) {
                native_raise(runtime, "Error", "Duplicate enum case");
                break;
            }
            for (NativeEnumCase *existing = entry->cases; existing; existing = existing->next) {
                if (backing_type && native_strict_equal(existing->backing, backing)) {
                    native_raise(runtime, "Error", "Duplicate enum backing value");
                    break;
                }
            }
            if (runtime->exception_class) break;

            NativeEnumCase *case_entry = native_alloc(runtime, sizeof(*case_entry));
            NativeObject *owner = native_alloc(runtime, sizeof(*owner));
            if (!case_entry || !owner) break;
            owner->object = jinx_zend_object_new(enum_name);
            if (!owner->object) { runtime->error = "native enum case object allocation failed"; break; }
            owner->next = runtime->objects;
            runtime->objects = owner;

            case_entry->name = native_copy(runtime, case_name, strlen(case_name));
            case_entry->backing = native_value_copy(parser, backing);
            case_entry->object = owner->object;

            JinxZendValue name_value = jinx_zend_string_value(jinx_zend_string_new(case_name, strlen(case_name)));
            if (!name_value.value.str || !jinx_zend_array_add_assoc(owner->object->properties, "name", 4, name_value)) {
                if (name_value.type == JINX_ZEND_STRING) jinx_zend_value_release(name_value);
                runtime->error = "native enum name property allocation failed";
                break;
            }
            jinx_zend_value_release(name_value);

            if (backing_type) {
                JinxZendValue converted = native_zend_value(parser, backing);
                if (!runtime->error && !jinx_zend_array_add_assoc(owner->object->properties, "value", 5, converted))
                    runtime->error = "native enum value property allocation failed";
                if (converted.type == JINX_ZEND_STRING) jinx_zend_value_release(converted);
                if (runtime->error) break;
            }

            if (!entry->cases) entry->cases = case_entry;
            else entry->cases_tail->next = case_entry;
            entry->cases_tail = case_entry;
        }
    }
    native_expect(parser, '}');
}

static void native_class_declaration(NativeParser *parser, int readonly_class) {
    native_next(parser);
    if (parser->kind != N_ID) { parser->runtime->error = "native class requires name"; return; }
    char name[256];
    strcpy(name, parser->token);
    native_next(parser);
    NativeClass *parent = NULL;
    if (parser->kind == N_ID && !strcmp(parser->token, "extends")) {
        native_next(parser);
        if (parser->kind != N_ID) { parser->runtime->error = "native extends requires class name"; return; }
        if (!parser->checking) {
            parent = native_class_find(parser->runtime, parser->token);
            if (!parent) native_raise(parser->runtime, "Error", "Parent class not found");
            for (NativeClass *ancestor = parent; ancestor; ancestor = ancestor->parent) {
                for (NativeProperty *property = ancestor->properties; property; property = property->next)
                    if (property->is_private) parser->runtime->error = "native private inheritance layout is not supported";
                for (NativeFunction *method = ancestor->methods; method; method = method->next)
                    if (method->is_private) parser->runtime->error = "native private inheritance layout is not supported";
            }
        }
        native_next(parser);
    }
    NativeClass *class_entry = NULL;
    if (!parser->checking && !parser->runtime->exception_class) {
        if (native_class_find(parser->runtime, name)) { native_raise(parser->runtime, "Error", "Class already declared"); return; }
        class_entry = native_alloc(parser->runtime, sizeof(*class_entry));
        if (!class_entry) return;
        class_entry->name = native_copy(parser->runtime, name, strlen(name));
        class_entry->is_readonly = readonly_class;
        class_entry->parent = parent;
        class_entry->next = parser->runtime->classes;
        parser->runtime->classes = class_entry;
    }
    native_expect(parser, '{');
    while (parser->kind != '}' && !parser->runtime->error && (parser->checking || !parser->runtime->exception_class)) {
        if (parser->kind != N_ID || (strcmp(parser->token, "public") && strcmp(parser->token, "private"))) {
            parser->runtime->error = "native class requires public/private member";
            break;
        }
        int is_private = !strcmp(parser->token, "private");
        if (is_private && parent) { parser->runtime->error = "native private inheritance layout is not supported"; break; }
        native_next(parser);
        int member_readonly = parser->kind == N_ID && !strcmp(parser->token, "readonly");
        if (member_readonly) native_next(parser);
        int is_static = parser->kind == N_ID && !strcmp(parser->token, "static");
        if (is_static) native_next(parser);
        if ((readonly_class || member_readonly) && is_static) {
            parser->runtime->error = "readonly property cannot be static";
            break;
        }
        if (parser->kind == N_ID && !strcmp(parser->token, "function")) {
            native_function_declaration(parser, class_entry, 1, is_private, is_static);
            continue;
        }
        int type = 0;
        char *object_type = NULL;
        if (parser->kind == N_ID) {
            if (!strcmp(parser->token, "int")) type = N_VALUE_INT;
            else if (!strcmp(parser->token, "string")) type = N_VALUE_STRING;
            else if (!strcmp(parser->token, "bool")) type = N_VALUE_BOOL;
            else if (!strcmp(parser->token, "float")) type = N_VALUE_FLOAT;
            else if (!strcmp(parser->token, "array")) type = JINX_ORACLE_VALUE_ZEND_ARRAY;
            else if (!strcmp(parser->token, "object")) type = JINX_ORACLE_VALUE_ZEND_OBJECT;
            else if (!strcmp(parser->token, "mixed")) type = 0;
            else {
                type = JINX_ORACLE_VALUE_ZEND_OBJECT;
                object_type = native_copy(parser->runtime, parser->token, strlen(parser->token));
            }
            native_next(parser);
        }
        if (readonly_class && !type && !object_type) {
            parser->runtime->error = "readonly class properties must be typed";
            break;
        }
        if (parser->kind != N_VAR) { parser->runtime->error = "native property declaration requires variable"; break; }
        char property_name[256];
        strcpy(property_name, parser->token);
        native_next(parser);
        int initialized = native_accept(parser, '=');
        JinxValue initial = initialized ? native_expression(parser, 0) : jinx_value_null();
        if (!type) initialized = 1;
        native_expect(parser, ';');
        if (class_entry && !parser->runtime->error && !parser->runtime->exception_class) {
            NativeProperty *property = native_alloc(parser->runtime, sizeof(*property));
            if (!property) break;
            property->name = native_copy(parser->runtime, property_name, strlen(property_name));
            property->type = type;
            property->object_type = object_type;
            property->is_static = is_static;
            property->initialized = initialized;
            property->is_private = is_private;
            property->is_readonly = readonly_class || member_readonly;
            property->owner = class_entry;
            property->storage.value = initial;
            property->next = class_entry->properties;
            class_entry->properties = property;
            if (property->is_readonly && initialized) {
                native_raise(parser->runtime, "Error", "Readonly property cannot have a default value");
            } else if (initialized && type && !native_type_matches(type, initial)) {
                native_raise(parser->runtime, "TypeError", "Incompatible property default");
            } else if (initialized && object_type && initial.type != 0 &&
                       !native_object_matches(parser->runtime, initial, object_type)) {
                native_raise(parser->runtime, "TypeError", "Incompatible property default");
            }
        }
    }
    native_expect(parser, '}');
}

static int native_catch_matches(const char *caught, const char *thrown) {
    return !strcmp(caught, "Throwable") || !strcmp(caught, thrown) ||
        (!strcmp(caught, "Error") && (!strcmp(thrown, "Error") || !strcmp(thrown, "TypeError") || !strcmp(thrown, "ArgumentCountError"))) ||
        (!strcmp(caught, "TypeError") && !strcmp(thrown, "ArgumentCountError"));
}

static void native_try(NativeParser *parser, JinxValue *result) {
    JinxValue ignored = jinx_value_null();
    native_next(parser);
    native_expect(parser, '{');
    NativeParser body = *parser;
    NativeParser end = body;
    end.checking = 1;
    native_statements(&end, &ignored);
    native_expect(&end, '}');
    if (end.kind != N_ID || strcmp(end.token, "catch")) end.runtime->error = "native try requires catch";
    native_next(&end);
    native_expect(&end, '(');
    if (end.kind != N_ID) end.runtime->error = "native catch requires exception class";
    char caught[256];
    strcpy(caught, end.token);
    native_next(&end);
    if (end.kind != N_VAR) end.runtime->error = "native catch requires variable";
    char variable[256];
    strcpy(variable, end.token);
    native_next(&end);
    native_expect(&end, ')');
    native_expect(&end, '{');
    NativeParser handler = end;
    native_statements(&end, &ignored);
    native_expect(&end, '}');
    int checking = parser->checking;
    *parser = end;
    parser->checking = checking;
    if (checking || parser->runtime->error) return;
    body.checking = 0;
    native_statements(&body, result);
    if (parser->runtime->error) return;
    const char *thrown = parser->runtime->exception_class;
    if (thrown && native_catch_matches(caught, thrown)) {
        parser->runtime->exception_class = NULL;
        parser->runtime->exception_message = NULL;
        NativeVariable *binding = native_variable(parser->runtime, variable);
        if (!binding) return;
        binding->reference = NULL;
        binding->value = native_instance(parser, thrown, 1);
        handler.checking = 0;
        native_statements(&handler, result);
        if (handler.returned) parser->returned = 1;
    } else if (!thrown && body.returned) parser->returned = 1;
}

static int native_static_statement(NativeParser *parser) {
    if (parser->kind != N_ID) return 0;
    NativeParser peek = *parser;
    native_next(&peek);
    return peek.kind == N_SCOPE;
}

static void native_foreach(NativeParser *parser, JinxValue *result) {
    JinxValue ignored = jinx_value_null();
    native_next(parser);
    native_expect(parser, '(');
    JinxValue iterable = native_expression(parser, 0);
    if (parser->kind != N_ID || strcmp(parser->token, "as")) parser->runtime->error = "native foreach requires as";
    native_next(parser);
    native_expect(parser, '&');
    NativeSlot destination = native_slot(parser);
    native_expect(parser, ')');
    native_expect(parser, '{');
    NativeParser body = *parser;
    NativeParser end = body;
    end.checking = 1;
    native_statements(&end, &ignored);
    native_expect(&end, '}');
    int checking = parser->checking;
    *parser = end;
    parser->checking = checking;
    if (checking || parser->runtime->error) return;
    if (iterable.type != JINX_ORACLE_VALUE_ZEND_ARRAY) { parser->runtime->error = "native foreach requires array"; return; }
    JinxZendArray *array = iterable.as.ptr;
    size_t count = array->count;
    for (size_t i = 0; i < count && !parser->runtime->error; i++) {
        const JinxZendBucket *bucket = jinx_zend_array_iter_at(array, i);
        if (!bucket) continue;
        NativeSlot element = {0};
        element.array = array;
        element.key = bucket->key ? jinx_value_string(bucket->key->bytes, (uint32_t)bucket->key->len)
            : jinx_value_int((int64_t)bucket->h);
        native_slot_bind(parser, destination, native_slot_reference(parser, element));
        NativeParser iteration = body;
        iteration.checking = 0;
        native_statements(&iteration, result);
        if (iteration.returned) { parser->returned = 1; return; }
        if (array->count != count) parser->runtime->error = "native foreach structural mutation is not yet supported";
    }
}

static void native_statements(NativeParser *parser, JinxValue *result) {
    while (parser->kind && parser->kind != '}' && !parser->runtime->error &&
        (parser->checking || !parser->runtime->exception_class)) {
        if (parser->kind == N_ID && !strcmp(parser->token, "declare")) {
            native_next(parser);
            native_expect(parser, '(');
            if (parser->kind != N_ID || strcmp(parser->token, "strict_types")) parser->runtime->error = "unsupported native declare";
            native_next(parser);
            native_expect(parser, '=');
            if (parser->kind != N_LITERAL || parser->literal.type != 1 || parser->literal.as.i64 != 1)
                parser->runtime->error = "unsupported native strict_types value";
            native_next(parser);
            native_expect(parser, ')');
            native_expect(parser, ';');
            parser->strict_types = 1;
        } else if (parser->kind == N_ID && !strcmp(parser->token, "function")) {
            native_function_declaration(parser, NULL, 0, 0, 0);
        } else if (parser->kind == N_ID && !strcmp(parser->token, "enum")) {
            native_enum_declaration(parser);
        } else if (parser->kind == N_ID && !strcmp(parser->token, "class")) {
            native_class_declaration(parser, 0);
        } else if (parser->kind == N_ID && !strcmp(parser->token, "readonly")) {
            native_next(parser);
            if (parser->kind != N_ID || strcmp(parser->token, "class")) {
                parser->runtime->error = "native readonly modifier currently requires class";
            } else {
                native_class_declaration(parser, 1);
            }
        } else if (parser->kind == N_ID && !strcmp(parser->token, "try")) {
            native_try(parser, result);
            if (parser->returned) return;
        } else if (parser->kind == N_VAR || native_static_statement(parser)) {
            NativeSlot slot = native_slot(parser);
            if (native_accept(parser, N_COALESCE)) {
                native_expect(parser, '=');
                int checking = parser->checking;
                JinxValue existing = jinx_value_null();
                if (!checking && !parser->runtime->error && !parser->runtime->exception_class) {
                    if (slot.property && slot.property->is_static && !slot.property->initialized) {
                        existing = jinx_value_null();
                    } else if (slot.variable) existing = native_variable_read(parser, slot.variable);
                    else {
                        JinxZendValue *element = native_slot_element(parser, slot, 0);
                        if (element) existing = native_from_zend(parser, *element);
                    }
                }
                int skip = !checking && existing.type != 0;
                parser->checking = checking || skip;
                JinxValue value = native_expression(parser, 0);
                parser->checking = checking;
                native_expect(parser, ';');
                if (!checking && !skip) native_slot_write(parser, slot, value);
                continue;
            }
            int operation = parser->kind;
            int compound = operation == '+' || operation == '*';
            if (compound) native_next(parser);
            if (operation == '+' && native_accept(parser, '+')) {
                native_expect(parser, ';');
                JinxValue old = native_slot_read(parser, slot);
                if (!parser->checking && !parser->runtime->error && !parser->runtime->exception_class) {
                    int64_t sum;
                    if (old.type != N_VALUE_INT || __builtin_add_overflow(old.as.i64, (int64_t)1, &sum))
                        parser->runtime->error = "unsupported native increment";
                    else native_slot_write(parser, slot, jinx_value_int(sum));
                }
                continue;
            }
            native_expect(parser, '=');
            if (native_accept(parser, '&')) {
                if (compound) parser->runtime->error = "reference binding cannot be compound assignment";
                NativeSlot source = native_slot(parser);
                native_expect(parser, ';');
                native_slot_bind(parser, slot, native_slot_reference(parser, source));
                continue;
            }
            JinxValue value = native_expression(parser, 0);
            native_expect(parser, ';');
            if (!parser->checking && !parser->runtime->error && !parser->runtime->exception_class) {
                if (compound) {
                    JinxValue old = native_slot_read(parser, slot);
                    if (parser->runtime->exception_class) continue;
                    int64_t number;
                    int overflow = old.type != N_VALUE_INT || value.type != N_VALUE_INT;
                    if (!overflow) overflow = operation == '+' ? __builtin_add_overflow(old.as.i64, value.as.i64, &number)
                        : __builtin_mul_overflow(old.as.i64, value.as.i64, &number);
                    if (overflow) parser->runtime->error = "unsupported native compound arithmetic";
                    else value = jinx_value_int(number);
                }
                native_slot_write(parser, slot, value);
            }
        } else if (parser->kind == N_ID && !strcmp(parser->token, "foreach")) {
            native_foreach(parser, result);
            if (parser->returned) return;
        } else if (parser->kind == N_ID && !strcmp(parser->token, "unset")) {
            native_next(parser);
            native_expect(parser, '(');
            NativeSlot slot = native_slot(parser);
            native_expect(parser, ')');
            native_expect(parser, ';');
            if (!parser->checking && !parser->runtime->error)
                native_slot_unset(parser, slot);
        } else if (parser->kind == N_ID && !strcmp(parser->token, "echo")) {
            native_next(parser);
            do {
                JinxValue value = native_expression(parser, 0);
                if (!parser->checking && !parser->runtime->error && !parser->runtime->exception_class) {
                    size_t length;
                    const char *text = native_text(parser, value, &length);
                    if (text) fwrite(text, 1, length, stdout);
                }
            } while (native_accept(parser, ','));
            native_expect(parser, ';');
        } else if (parser->kind == N_ID && !strcmp(parser->token, "return")) {
            native_next(parser);
            *result = parser->kind == ';' ? jinx_value_null() : native_expression(parser, 0);
            native_expect(parser, ';');
            if (!parser->checking) { parser->returned = 1; return; }
        } else {
            (void)native_expression(parser, 0);
            native_expect(parser, ';');
        }
    }
}

static int native_file(NativeRuntime *runtime, const char *path, int once, JinxValue *result) {
    char canonical[PATH_MAX];
    if (!realpath(path, canonical)) { runtime->error = "native include/input file not found"; return 0; }
    for (size_t i = 0; i < runtime->included_count; i++)
        if (once && !strcmp(runtime->included[i], canonical)) { *result = jinx_value_bool(1); return 1; }
    if (runtime->depth >= 64 || runtime->included_count >= 128) {
        runtime->error = "native include nesting/file limit exceeded";
        return 0;
    }
    FILE *file = fopen(canonical, "rb");
    if (!file) { runtime->error = "native input cannot be opened"; return 0; }
    if (fseek(file, 0, SEEK_END)) { fclose(file); runtime->error = "native input seek failed"; return 0; }
    long size = ftell(file);
    if (size < 0 || size > 16 * 1024 * 1024) { fclose(file); runtime->error = "native input size limit"; return 0; }
    rewind(file);
    char *source = native_alloc(runtime, (size_t)size + 1);
    if (!source) { fclose(file); return 0; }
    size_t read_count = fread(source, 1, (size_t)size, file);
    fclose(file);
    if (read_count != (size_t)size || memchr(source, 0, read_count)) {
        runtime->error = "native input read failed or contains NUL"; return 0;
    }
    if (strncmp(source, "<?php", 5)) { runtime->error = "native input requires PHP opening tag"; return 0; }
    char *saved_path = native_copy(runtime, canonical, strlen(canonical));
    char *directory = native_copy(runtime, canonical, strlen(canonical));
    if (!saved_path || !directory) return 0;
    char *slash = strrchr(directory, '/');
    if (slash) { if (slash == directory) slash[1] = '\0'; else *slash = '\0'; }
    NativeParser parser = {0};
    parser.runtime = runtime;
    parser.path = saved_path;
    parser.directory = directory;
    parser.checking = 1;
    parser.cursor = source + 5;
    native_next(&parser);
    native_statements(&parser, result);
    if (parser.kind && !runtime->error) runtime->error = "unexpected closing brace in native input";
    if (runtime->error) return 0;
    runtime->started = 1;
    runtime->included[runtime->included_count++] = saved_path;
    runtime->depth++;
    parser.checking = 0;
    parser.cursor = source + 5;
    *result = jinx_value_int(1);
    native_next(&parser);
    native_statements(&parser, result);
    runtime->depth--;
    return runtime->error == NULL && runtime->exception_class == NULL;
}

static int native_script_run(const char *path, int probing) {
    NativeRuntime runtime = {0};
    JinxValue result;
    int ok = native_file(&runtime, path, 0, &result);
    int unsupported = !ok && probing && !runtime.started;
    if (!ok && !unsupported) fprintf(stderr, "JINX NATIVE SCRIPT ERROR: %s: %s: %s; refusing PHP fallback\n",
        path, runtime.exception_class ? runtime.exception_class : "InterpreterError",
        runtime.error ? runtime.error : runtime.exception_message);
    for (NativeArray *array = runtime.arrays; array; array = array->next) jinx_zend_array_release(array->array);
    for (NativeReference *reference = runtime.references; reference; reference = reference->next)
        jinx_zend_reference_release(reference->reference);
    for (NativeObject *object = runtime.objects; object; object = object->next) jinx_zend_object_release(object->object);
    while (runtime.allocations) {
        NativeAllocation *allocation = runtime.allocations;
        runtime.allocations = allocation->next;
        free(allocation->ptr);
        free(allocation);
    }
    return unsupported ? 2 : (ok ? 0 : 1);
}

int jinx_oracle_native_script(const char *path) { return native_script_run(path, 0); }
int jinx_oracle_native_script_try(const char *path) { return native_script_run(path, 1); }
