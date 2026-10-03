#include "jinx_oracle_native_script.h"
#include "jinx_builtin_dispatch.h"
#include <ctype.h>
#include <limits.h>

typedef struct NativeAllocation {
    void *ptr;
    struct NativeAllocation *next;
} NativeAllocation;

typedef struct NativeVariable {
    char *name;
    JinxValue value;
    struct NativeVariable *next;
} NativeVariable;

typedef struct NativeRuntime {
    NativeAllocation *allocations;
    NativeVariable *variables;
    char *included[128];
    size_t included_count;
    unsigned depth;
    const char *error;
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
} NativeParser;

enum { N_END = 0, N_ID = 256, N_VAR, N_LITERAL };
enum { N_VALUE_INT = 1, N_VALUE_BOOL = 2, N_VALUE_STRING = 3, N_VALUE_FLOAT = 5 };

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
        parser->literal = jinx_value_int(strtoll(p, &end, 10));
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

static JinxValue native_primary(NativeParser *parser) {
    NativeRuntime *runtime = parser->runtime;
    JinxValue value = jinx_value_null();
    if (runtime->error) return value;
    if (parser->kind == N_LITERAL) {
        value = parser->literal;
        native_next(parser);
    } else if (parser->kind == N_VAR) {
        NativeVariable *variable = native_variable(runtime, parser->token);
        if (variable) value = variable->value;
        native_next(parser);
    } else if (native_accept(parser, '(')) {
        value = native_expression(parser, 0);
        native_expect(parser, ')');
    } else if (native_accept(parser, '-')) {
        value = native_primary(parser);
        if (!parser->checking && value.type != 1) runtime->error = "native unary minus requires integer";
        if (value.as.i64 == INT64_MIN) runtime->error = "native integer overflow";
        else value = jinx_value_int(-value.as.i64);
    } else if (parser->kind == N_ID) {
        char name[256];
        strcpy(name, parser->token);
        native_next(parser);
        if (!strcmp(name, "__DIR__")) value = jinx_value_string(parser->directory, (uint32_t)strlen(parser->directory));
        else if (!strcmp(name, "__FILE__")) value = jinx_value_string(parser->path, (uint32_t)strlen(parser->path));
        else if (!strcmp(name, "true")) value = jinx_value_bool(1);
        else if (!strcmp(name, "false")) value = jinx_value_bool(0);
        else if (!strcmp(name, "null")) value = jinx_value_null();
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
            JinxValue args[16];
            size_t count = 0;
            int ok = 0;
            /* Start with proven scalar native handlers; widen after parity tests. */
            if (strcmp(name, "strlen") && strcmp(name, "strtoupper") &&
                strcmp(name, "strtolower") && strcmp(name, "abs"))
                runtime->error = "builtin is not admitted by the native source interpreter";
            native_expect(parser, '(');
            if (parser->kind != ')') do {
                if (count == 16) { runtime->error = "too many native call arguments"; break; }
                args[count++] = native_expression(parser, 0);
            } while (native_accept(parser, ','));
            native_expect(parser, ')');
            if (!parser->checking && !runtime->error) {
                value = jinx_call_builtin_through_oracle_checked(name, args, count, &ok);
                if (!ok) runtime->error = "native Oracle builtin call failed";
                if (ok && value.type == N_VALUE_STRING) {
                    char *copy = native_copy(runtime, value.as.ptr, value.flags);
                    /* Native scalar handlers return borrowed scratch storage. */
                    value.as.ptr = copy;
                }
            }
        }
    } else runtime->error = "expression is not yet supported by native Oracle";
    return value;
}

static int native_precedence(int kind) {
    if (kind == '.') return 10;
    if (kind == '+' || kind == '-') return 20;
    if (kind == '*' || kind == '%') return 30;
    return -1;
}

static JinxValue native_expression(NativeParser *parser, int minimum) {
    if (++parser->expression_depth > 128) {
        parser->runtime->error = "native expression nesting limit exceeded";
        parser->expression_depth--;
        return jinx_value_null();
    }
    JinxValue left = native_primary(parser);
    while (!parser->runtime->error && native_precedence(parser->kind) >= minimum) {
        int operator = parser->kind;
        int precedence = native_precedence(operator);
        native_next(parser);
        JinxValue right = native_expression(parser, precedence + 1);
        if (parser->checking || parser->runtime->error) continue;
        if (operator == '.') {
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
    parser->expression_depth--;
    return left;
}

static void native_statements(NativeParser *parser, JinxValue *result) {
    while (parser->kind && !parser->runtime->error) {
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
        } else if (parser->kind == N_VAR) {
            char name[256];
            strcpy(name, parser->token);
            native_next(parser);
            native_expect(parser, '=');
            JinxValue value = native_expression(parser, 0);
            native_expect(parser, ';');
            if (!parser->checking && !parser->runtime->error) {
                NativeVariable *variable = native_variable(parser->runtime, name);
                if (variable) variable->value = value;
            }
        } else if (parser->kind == N_ID && !strcmp(parser->token, "echo")) {
            native_next(parser);
            do {
                JinxValue value = native_expression(parser, 0);
                if (!parser->checking && !parser->runtime->error) {
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
            if (!parser->checking) return;
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
    if (runtime->error) return 0;
    runtime->included[runtime->included_count++] = saved_path;
    runtime->depth++;
    parser.checking = 0;
    parser.cursor = source + 5;
    *result = jinx_value_int(1);
    native_next(&parser);
    native_statements(&parser, result);
    runtime->depth--;
    return runtime->error == NULL;
}

int jinx_oracle_native_script(const char *path) {
    NativeRuntime runtime = {0};
    JinxValue result;
    int ok = native_file(&runtime, path, 0, &result);
    if (!ok) fprintf(stderr, "JINX NATIVE SCRIPT ERROR: %s: %s; refusing PHP fallback\n", path, runtime.error);
    while (runtime.allocations) {
        NativeAllocation *allocation = runtime.allocations;
        runtime.allocations = allocation->next;
        free(allocation->ptr);
        free(allocation);
    }
    return ok ? 0 : 1;
}
