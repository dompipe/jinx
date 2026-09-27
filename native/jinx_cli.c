#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

#include "../runtime/jinx_function_list.generated.h"
#include "../runtime/jinx_oracle_zend_array_carrier.h"
#include "../runtime/jinx_zend_array_delete.h"
#include "../runtime/jinx_pasm_machine.h"

#define JINX_NATIVE_SAMPLE_ARGC 32u

static void print_value(JinxValue value);
static void print_value_hex_line(JinxValue value);
static JinxValue make_zend_array_fixture(int deleted);
static void release_cli_value(JinxValue value);
static void release_cli_values(JinxValue *values, size_t count);

static void usage(const char *argv0) {
    printf("JINX native GCC CLI\n\n");
    printf("Usage:\n");
    printf("  %s rc\n", argv0);
    printf("  %s oracle-smoke\n", argv0);
    printf("  %s oracle-call <function> [typed-args...]\n", argv0);
    printf("  %s oracle-call-hex <function> [typed-args...]\n", argv0);
    printf("  %s bench-oracle [iterations]\n", argv0);
    printf("  %s first100\n", argv0);
    printf("  %s first100-list\n", argv0);
    printf("  %s bench-first100 [iterations]\n", argv0);
    printf("  %s bench-all-functions [iterations] [--strict]\n", argv0);
    printf("  %s functions-count\n", argv0);
    printf("  %s functions\n", argv0);
    printf("  %s function-exists <name>\n", argv0);
    printf("  %s functions-smoke\n", argv0);
    printf("  %s notes\n", argv0);
    printf("  %s benchmarks\n", argv0);
    printf("  %s scripts/test-oracle-program-compiler.php\n", argv0);
    printf("\nTyped args:\n");
    printf("  i:<int>       integer value\n");
    printf("  f:<float>     floating point value\n");
    printf("  b:true|false  boolean value\n");
    printf("  s:<text>      string value\n");
    printf("  h:<hex>       binary string from hexadecimal bytes\n");
    printf("  a:<count>     array-count stand-in\n");
    printf("  za:sample     native Zend array [10, 20, \"name\" => 30, \"keep\" => 40]\n");
    printf("  za:deleted    same native Zend array with index 1 and key \"name\" tombstoned\n");
    printf("  null          null value\n");
    printf("  raw text defaults to string\n");
}

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

static int ends_with(const char *text, const char *suffix) {
    size_t text_len = strlen(text);
    size_t suffix_len = strlen(suffix);

    if (suffix_len > text_len) {
        return 0;
    }

    return strcmp(text + text_len - suffix_len, suffix) == 0;
}

static int command_php_script(int argc, char **argv) {
    char **php_argv = (char **) calloc((size_t) argc + 1u, sizeof(char *));

    if (php_argv == NULL) {
        return fail("could not allocate PHP script argv");
    }

    php_argv[0] = "php";
    for (int i = 1; i < argc; i++) {
        php_argv[i] = argv[i];
    }
    php_argv[argc] = NULL;

    execvp("php", php_argv);
    perror("php");
    free(php_argv);
    return 1;
}

static const char *const first100_names[] = {
    "abs", "acos", "acosh", "addcslashes", "addslashes",
    "array_all", "array_any", "array_change_key_case", "array_chunk", "array_column",
    "array_combine", "array_count_values", "array_diff", "array_diff_assoc", "array_diff_key",
    "array_diff_uassoc", "array_diff_ukey", "array_fill", "array_fill_keys", "array_filter",
    "array_find", "array_find_key", "array_flip", "array_intersect", "array_intersect_assoc",
    "array_intersect_key", "array_intersect_uassoc", "array_intersect_ukey", "array_is_list", "array_key_exists",
    "array_key_first", "array_key_last", "array_keys", "array_map", "array_merge",
    "array_merge_recursive", "array_pad", "array_product", "array_reduce", "array_replace",
    "array_replace_recursive", "array_reverse", "array_search", "array_slice", "array_sum",
    "array_udiff", "array_udiff_assoc", "array_udiff_uassoc", "array_uintersect", "array_uintersect_assoc",
    "array_uintersect_uassoc", "array_values", "asin", "asinh", "assert",
    "atan", "atan2", "atanh", "base64_decode", "base64_encode",
    "base_convert", "basename", "bin2hex", "bindec", "boolval",
    "cal_days_in_month", "cal_from_jd", "cal_info", "cal_to_jd", "call_user_func",
    "call_user_func_array", "ceil", "checkdate", "checkdnsrr", "chop",
    "chr", "chunk_split", "class_exists", "class_implements", "class_parents",
    "class_uses", "connection_aborted", "connection_status", "constant", "convert_uudecode",
    "convert_uuencode", "cos", "cosh", "count", "count_chars",
    "crc32", "crypt", "ctype_alnum", "ctype_alpha", "ctype_cntrl",
    "ctype_digit", "ctype_graph", "ctype_lower", "ctype_print", "ctype_punct"
};

static size_t first100_count(void) {
    return sizeof(first100_names) / sizeof(first100_names[0]);
}

static int is_math_name(const char *name) {
    return strcmp(name, "acos") == 0 ||
        strcmp(name, "acosh") == 0 ||
        strcmp(name, "asin") == 0 ||
        strcmp(name, "asinh") == 0 ||
        strcmp(name, "atan") == 0 ||
        strcmp(name, "atan2") == 0 ||
        strcmp(name, "atanh") == 0 ||
        strcmp(name, "ceil") == 0 ||
        strcmp(name, "cos") == 0 ||
        strcmp(name, "cosh") == 0;
}

static int is_string_name(const char *name) {
    return strcmp(name, "addcslashes") == 0 ||
        strcmp(name, "addslashes") == 0 ||
        strcmp(name, "base64_decode") == 0 ||
        strcmp(name, "base64_encode") == 0 ||
        strcmp(name, "base_convert") == 0 ||
        strcmp(name, "basename") == 0 ||
        strcmp(name, "bin2hex") == 0 ||
        strcmp(name, "chop") == 0 ||
        strcmp(name, "chr") == 0 ||
        strcmp(name, "chunk_split") == 0 ||
        strcmp(name, "constant") == 0 ||
        strcmp(name, "convert_uudecode") == 0 ||
        strcmp(name, "convert_uuencode") == 0 ||
        strcmp(name, "count_chars") == 0 ||
        strcmp(name, "crypt") == 0;
}

static void seed_sample_args(JinxValue args[JINX_NATIVE_SAMPLE_ARGC]) {
    for (size_t i = 0; i < JINX_NATIVE_SAMPLE_ARGC; i++) {
        switch (i % 8u) {
            case 0u:
                args[i] = jinx_value_array_count(4);
                break;
            case 1u:
                args[i] = jinx_value_int(0);
                break;
            case 2u:
                args[i] = jinx_value_string("name", 4);
                break;
            case 3u:
                args[i] = jinx_value_bool(1);
                break;
            case 4u:
                args[i] = jinx_value_string("callback", 8);
                break;
            case 5u:
                args[i] = jinx_value_int(2);
                break;
            case 6u:
                args[i] = jinx_value_string("dompipe", 7);
                break;
            default:
                args[i] = jinx_value_float(1.0);
                break;
        }
    }
}

static void all_function_args(const char *name, JinxValue args[JINX_NATIVE_SAMPLE_ARGC]) {
    seed_sample_args(args);

    if (strcmp(name, "abs") == 0) {
        args[0] = jinx_value_int(-42);
        return;
    }

    if (is_math_name(name)) {
        args[0] = jinx_value_float(strcmp(name, "acosh") == 0 ? 2.0 : 1.0);
        args[1] = jinx_value_float(1.0);
        return;
    }

    if (is_string_name(name)) {
        args[0] = jinx_value_string("dompipe", 7);
        args[1] = jinx_value_string("a..z", 4);
        args[2] = jinx_value_string("-", 1);
        return;
    }

    if (strcmp(name, "count") == 0) {
        args[0] = jinx_value_array_count(4);
        args[1] = jinx_value_int(0);
        return;
    }

    if (strcmp(name, "bindec") == 0) {
        args[0] = jinx_value_string("1010", 4);
        return;
    }

    if (strncmp(name, "ctype_", 6) == 0) {
        const char *text = strcmp(name, "ctype_cntrl") == 0 ? "\n" : "ABC123";
        args[0] = jinx_value_string(text, (uint32_t) strlen(text));
        return;
    }

    if (strcmp(name, "boolval") == 0 || strcmp(name, "assert") == 0) {
        args[0] = jinx_value_bool(1);
        return;
    }

    if (strstr(name, "strlen") != NULL) {
        args[0] = jinx_value_string("oracle", 6);
        return;
    }

    if (strstr(name, "count") != NULL || strstr(name, "length") != NULL || strstr(name, "num") != NULL) {
        args[0] = jinx_value_array_count(4);
        args[1] = jinx_value_int(0);
        return;
    }

    if (strstr(name, "date") != NULL || strstr(name, "time") != NULL) {
        args[0] = jinx_value_string("Y-m-d", 5);
        args[1] = jinx_value_int(1704067200);
        return;
    }

    if (strstr(name, "class") != NULL || strstr(name, "interface") != NULL || strstr(name, "trait") != NULL) {
        args[0] = jinx_value_string("stdClass", 8);
        return;
    }

    if (strstr(name, "json") != NULL) {
        args[0] = jinx_value_string("{\"a\":1}", 7);
        return;
    }

    if (strstr(name, "array") != NULL) {
        args[0] = jinx_value_array_count(4);
        args[1] = jinx_value_int(0);
        return;
    }
}

static int call_all_function_name(const char *name, JinxValue *out) {
    JinxValue args[JINX_NATIVE_SAMPLE_ARGC];
    all_function_args(name, args);

    *out = jinx_call_builtin_through_oracle(name, args, JINX_NATIVE_SAMPLE_ARGC);
    return out->type != 0u;
}

static int call_first100_name(const char *name, JinxValue *out) {
    return call_all_function_name(name, out);
}

static int call_oracle_with_samples(const char *name, JinxValue *out) {
    JinxValue args[JINX_NATIVE_SAMPLE_ARGC];
    all_function_args(name, args);
    *out = jinx_call_builtin_through_oracle(name, args, JINX_NATIVE_SAMPLE_ARGC);
    return out->type != 0u;
}

static JinxValue make_zend_array_fixture(int deleted) {
    JinxZendArray *array = jinx_zend_array_new_packed(4);
    JinxValue value;

    if (array == 0) {
        return jinx_value_null();
    }

    if (!jinx_zend_array_append(array, jinx_zend_long(10)) ||
        !jinx_zend_array_append(array, jinx_zend_long(20)) ||
        !jinx_zend_array_add_assoc(array, "name", 4, jinx_zend_long(30)) ||
        !jinx_zend_array_add_assoc(array, "keep", 4, jinx_zend_long(40))) {
        jinx_zend_array_release(array);
        return jinx_value_null();
    }

    if (deleted) {
        (void)jinx_zend_array_delete_index(array, 1u);
        (void)jinx_zend_array_delete_string(array, "name", 4);
    }

    value = jinx_oracle_zend_array_value_retained(array);
    jinx_zend_array_release(array);
    return value;
}

static int cli_hex_nibble(unsigned char c) {
    if (c >= (unsigned char)'0' && c <= (unsigned char)'9') return (int)(c - (unsigned char)'0');
    if (c >= (unsigned char)'a' && c <= (unsigned char)'f') return 10 + (int)(c - (unsigned char)'a');
    if (c >= (unsigned char)'A' && c <= (unsigned char)'F') return 10 + (int)(c - (unsigned char)'A');
    return -1;
}

static JinxValue parse_cli_value(const char *text, void **owned) {
    if (owned != NULL) *owned = NULL;
    if (text == NULL || strcmp(text, "null") == 0) {
        return jinx_value_null();
    }

    if (strncmp(text, "i:", 2) == 0) {
        return jinx_value_int(atoll(text + 2));
    }

    if (strncmp(text, "f:", 2) == 0) {
        return jinx_value_float(strtod(text + 2, NULL));
    }

    if (strncmp(text, "b:", 2) == 0) {
        const char *value = text + 2;
        return jinx_value_bool(strcmp(value, "1") == 0 || strcmp(value, "true") == 0 || strcmp(value, "yes") == 0);
    }

    if (strncmp(text, "s:", 2) == 0) {
        return jinx_value_string(text + 2, (uint32_t) strlen(text + 2));
    }

    if (strncmp(text, "h:", 2) == 0) {
        const char *hex = text + 2;
        size_t hex_len = strlen(hex);
        if ((hex_len & 1u) != 0u || hex_len / 2u > UINT32_MAX) {
            return jinx_value_null();
        }

        size_t byte_len = hex_len / 2u;
        unsigned char *bytes = (unsigned char *)malloc(byte_len == 0u ? 1u : byte_len);
        if (bytes == NULL) return jinx_value_null();

        for (size_t i = 0u; i < byte_len; i++) {
            int hi = cli_hex_nibble((unsigned char)hex[i * 2u]);
            int lo = cli_hex_nibble((unsigned char)hex[i * 2u + 1u]);
            if (hi < 0 || lo < 0) {
                free(bytes);
                return jinx_value_null();
            }
            bytes[i] = (unsigned char)((hi << 4) | lo);
        }

        if (owned != NULL) *owned = bytes;
        return jinx_value_string((const char *)bytes, (uint32_t)byte_len);
    }

    if (strncmp(text, "a:", 2) == 0) {
        long long count = atoll(text + 2);
        return jinx_value_array_count(count < 0 ? 0u : (uint32_t) count);
    }

    if (strcmp(text, "za:sample") == 0) {
        return make_zend_array_fixture(0);
    }

    if (strcmp(text, "za:deleted") == 0) {
        return make_zend_array_fixture(1);
    }

    return jinx_value_string(text, (uint32_t) strlen(text));
}

static void print_value_line(JinxValue value) {
    print_value(value);
    printf("\n");
}

static void print_value_hex_line(JinxValue value) {
    if (value.type != 3u) {
        print_value_line(value);
        return;
    }

    const unsigned char *bytes = (const unsigned char *)value.as.ptr;
    printf("hex:");
    for (uint32_t i = 0u; i < value.flags; i++) {
        printf("%02x", (unsigned)bytes[i]);
    }
    printf("\n");
}

static void print_value(JinxValue value) {
    switch (value.type) {
        case 1u:
            printf("int:%lld", (long long)value.as.i64);
            break;
        case 2u:
            printf("bool:%s", value.as.i64 ? "true" : "false");
            break;
        case 3u:
            printf("string:%.*s", (int)value.flags, (const char *)value.as.ptr);
            break;
        case 4u:
            printf("array-count:%u", value.flags);
            break;
        case 5u:
            printf("float:%g", value.as.f64);
            break;
        case JINX_ORACLE_VALUE_ZEND_ARRAY:
            printf("zend-array:%zu", jinx_zend_array_live_count(jinx_oracle_zend_array_ptr(value)));
            break;
        default:
            printf("null");
            break;
    }
}

static void release_cli_value(JinxValue value) {
    jinx_oracle_zend_array_value_release(value);
}

static void release_cli_values(JinxValue *values, size_t count) {
    if (values == 0) {
        return;
    }

    for (size_t i = 0; i < count; i++) {
        release_cli_value(values[i]);
    }
}

static int pasm_strlen(const char *text, long long *out) {
    JinxPasmMachine machine;
    JinxValue result;

    const JinxPasmOp program[] = {
        { .op = JINX_PASM_PUSH_VALUE, .name = NULL, .value = {0}, .argc = 0 },
        { .op = JINX_PASM_CALL_BUILTIN, .name = "strlen", .value = {0}, .argc = 1 },
        { .op = JINX_PASM_HALT, .name = NULL, .value = {0}, .argc = 0 }
    };

    JinxPasmOp mutable_program[3];
    memcpy(mutable_program, program, sizeof(program));
    mutable_program[0].value = jinx_value_string(text, (uint32_t) strlen(text));

    jinx_pasm_machine_init(&machine);

    if (!jinx_pasm_run(&machine, mutable_program, &result)) {
        fprintf(stderr, "PASM fault: %s\n", machine.fault ? machine.fault : "(none)");
        return 0;
    }

    *out = (long long) result.as.i64;
    return result.type == 1u;
}

static int command_rc(void) {
    printf("dompipe/jinx native GCC CLI\n");
    printf("Status: RC native smoke executable\n");
    printf("Runtime: Oracle/PASM C dispatcher\n");
    printf("Implemented native runtime calls: universal generated oracle-call, strlen, count, hot first100 subset, full generated dispatch traversal\n");
    printf("Use the source package for the full PHP web/worker RC surface.\n");
    return 0;
}

static int command_notes(void) {
    printf("dompipe/jinx RC notes\n\n");
    printf("- 3,527 PHP callable signatures have worker-style wrapper records.\n");
    printf("- The native GCC CLI includes a generated inventory for all 3,527 names.\n");
    printf("- functions-smoke verifies every generated name resolves to a C Oracle dispatch wrapper.\n");
    printf("- oracle-call can invoke any generated name through the native ./jinx Oracle dispatch table.\n");
    printf("- bench-all-functions runs every generated name through the native ./jinx Oracle dispatch path with deterministic sample arguments.\n");
    printf("- Worker execution fails closed for unsafe, unavailable, by-reference, and method-only wrappers.\n");
    printf("- The PHP worker path uses a compact name -> id -> row table.\n");
    printf("- The first hot worker-safe benchmark set also has an Oracle-shaped PHP dispatch layer.\n");
    printf("- This native GCC CLI runs the current C Oracle/PASM dispatcher directly.\n");
    printf("- Native runtime behavior is still coarse for many complex builtins until exact handlers replace the generic fallback families.\n");
    printf("- Full web/compiler RC tooling remains in the source package under bin/, runtime/, and scripts/.\n");
    return 0;
}

static int command_benchmarks(void) {
    printf("dompipe/jinx RC benchmark snapshot\n\n");
    printf("Worker-style wrapper dispatch:\n");
    printf("  registered wrappers:       3527\n");
    printf("  allowedNames first read:   0.292 ms\n");
    printf("  allowedNames warm read:    0.001 ms\n");
    printf("  strlen hand wrapper:       0.156 us/call\n");
    printf("  acos generated wrapper:    0.582 us/call\n\n");
    printf("First 100 wrapper functions:\n");
    printf("  TOTAL php:                 1.375 us/op\n");
    printf("  TOTAL jinx:                1.604 us/op\n");
    printf("  ratio:                     1.17x\n\n");
    printf("Worker benchmark:\n");
    printf("  native php -S avg:         11.169 ms\n");
    printf("  jinx worker close avg:     5.555 ms\n");
    printf("  worker/native ratio:       0.50x\n\n");
    printf("Native GCC benchmark commands:\n");
    printf("  ./jinx bench-oracle 1000000\n");
    printf("  ./jinx bench-first100 100000\n");
    printf("  ./jinx bench-all-functions 1000\n");
    return 0;
}

static int command_functions_count(void) {
    printf("%zu\n", jinx_all_function_count);
    return jinx_all_function_count == 3527u ? 0 : 1;
}

static int command_functions(void) {
    for (size_t i = 0; i < jinx_all_function_count; i++) {
        printf("%4zu  %s\n", i + 1, jinx_all_function_names[i]);
    }

    return 0;
}

static int command_function_exists(int argc, char **argv) {
    if (argc < 3) {
        return fail("function-exists requires a function name");
    }

    if (jinx_lookup_oracle_wrapper(argv[2]) == NULL) {
        printf("missing: %s\n", argv[2]);
        return 1;
    }

    printf("present: %s\n", argv[2]);
    return 0;
}

static int command_functions_smoke(void) {
    size_t missing = 0;

    for (size_t i = 0; i < jinx_all_function_count; i++) {
        if (jinx_lookup_oracle_wrapper(jinx_all_function_names[i]) == NULL) {
            if (missing < 20) {
                fprintf(stderr, "missing dispatch wrapper: %s\n", jinx_all_function_names[i]);
            }
            missing++;
        }
    }

    if (missing != 0) {
        fprintf(stderr, "FAIL: %zu/%zu names are missing native Oracle dispatch wrappers\n", missing, jinx_all_function_count);
        return 1;
    }

    printf("PASS: all %zu function names resolve to native Oracle dispatch wrappers\n", jinx_all_function_count);
    return 0;
}

static int command_first100_list(void) {
    for (size_t i = 0; i < first100_count(); i++) {
        printf("%3zu  %s\n", i + 1, first100_names[i]);
    }

    return 0;
}

static int command_first100(void) {
    size_t passed = 0;

    printf("JINX native first 100 Oracle wrapper functions\n");
    printf("%-4s %-28s %s\n", "#", "function", "result");
    printf("--------------------------------------------------\n");

    for (size_t i = 0; i < first100_count(); i++) {
        JinxValue result;
        int ok = call_first100_name(first100_names[i], &result);

        printf("%-4zu %-28s ", i + 1, first100_names[i]);
        if (ok) {
            print_value(result);
            passed++;
        } else {
            printf("FAIL");
        }
        printf("\n");
    }

    printf("--------------------------------------------------\n");
    printf("PASS: %zu/%zu first wrapper functions executed through native Oracle dispatch\n", passed, first100_count());

    return passed == first100_count() ? 0 : 1;
}

static int command_bench_first100(int argc, char **argv) {
    long iterations = 100000;
    size_t calls = first100_count();

    if (argc >= 3) {
        iterations = atol(argv[2]);
    }

    if (iterations <= 0) {
        iterations = 1;
    }

    clock_t start = clock();

    for (long i = 0; i < iterations; i++) {
        for (size_t n = 0; n < calls; n++) {
            JinxValue result;
            if (!call_first100_name(first100_names[n], &result)) {
                fprintf(stderr, "FAIL: native first100 dispatch failed for %s\n", first100_names[n]);
                return 1;
            }
        }
    }

    clock_t elapsed = clock() - start;
    double seconds = (double) elapsed / (double) CLOCKS_PER_SEC;
    double total_calls = (double) iterations * (double) calls;

    printf("JINX native first 100 Oracle wrapper benchmark\n");
    printf("Functions: %zu\n", calls);
    printf("Iterations per function: %ld\n", iterations);
    printf("Total calls: %.0f\n", total_calls);
    printf("Elapsed ms: %.3f\n", seconds * 1000.0);
    printf("Per call ns: %.1f\n", seconds * 1000000000.0 / total_calls);
    return 0;
}

static int command_bench_all_functions(int argc, char **argv) {
    long iterations = 1000;
    int strict = 0;
    size_t calls = jinx_all_function_count;
    size_t missing = 0;
    size_t concrete = 0;
    size_t null_or_fault = 0;

    if (argc >= 3) {
        iterations = atol(argv[2]);
    }

    if (argc >= 4 && strcmp(argv[3], "--strict") == 0) {
        strict = 1;
    }

    if (iterations <= 0) {
        iterations = 1;
    }

    for (size_t n = 0; n < calls; n++) {
        const char *name = jinx_all_function_names[n];
        JinxValue result;

        if (jinx_lookup_oracle_wrapper(name) == NULL) {
            if (missing < 20) {
                fprintf(stderr, "missing dispatch wrapper: %s\n", name);
            }
            missing++;
            continue;
        }

        if (call_all_function_name(name, &result)) {
            concrete++;
        } else {
            if (null_or_fault < 20) {
                fprintf(stderr, "null/fault placeholder: %s\n", name);
            }
            null_or_fault++;
        }
    }

    if (missing != 0) {
        fprintf(stderr, "FAIL: %zu/%zu names are missing native Oracle dispatch wrappers\n", missing, calls);
        return 1;
    }

    if (strict && null_or_fault != 0) {
        fprintf(stderr, "FAIL: %zu/%zu functions returned null/fault placeholders under --strict\n", null_or_fault, calls);
        return 1;
    }

    clock_t start = clock();

    for (long i = 0; i < iterations; i++) {
        for (size_t n = 0; n < calls; n++) {
            JinxValue result;
            (void) call_all_function_name(jinx_all_function_names[n], &result);
        }
    }

    clock_t elapsed = clock() - start;
    double seconds = (double) elapsed / (double) CLOCKS_PER_SEC;
    double total_calls = (double) iterations * (double) calls;

    printf("JINX native all-functions Oracle dispatch benchmark\n");
    printf("Functions: %zu\n", calls);
    printf("Iterations per function: %ld\n", iterations);
    printf("Sample args per call: %u\n", JINX_NATIVE_SAMPLE_ARGC);
    printf("Dispatch wrappers present: %zu/%zu\n", calls - missing, calls);
    printf("Concrete non-null first-pass returns: %zu/%zu\n", concrete, calls);
    printf("Null/fault placeholder first-pass returns: %zu/%zu\n", null_or_fault, calls);
    printf("Total dispatches: %.0f\n", total_calls);
    printf("Elapsed ms: %.3f\n", seconds * 1000.0);
    printf("Per dispatch ns: %.1f\n", seconds * 1000000000.0 / total_calls);
    return 0;
}

static int command_oracle_smoke(void) {
    long long value = 0;
    JinxValue result;

    if (!call_oracle_with_samples("strlen", &result) || result.type != 1u || result.as.i64 != 6) {
        return fail("Oracle strlen did not return 6");
    }

    if (!call_oracle_with_samples("count", &result) || result.type != 1u || result.as.i64 != 4) {
        return fail("Oracle count did not return 4");
    }

    if (!pasm_strlen("oracle", &value) || value != 6) {
        return fail("PASM CALL_BUILTIN strlen did not return 6");
    }

    printf("PASS: native GCC jinx executed Oracle strlen/count and PASM CALL_BUILTIN strlen\n");
    return 0;
}

static int command_oracle_call(int argc, char **argv, int hex_output) {
    const char *name;
    JinxValue args[JINX_NATIVE_SAMPLE_ARGC];
    JinxValue result;
    int supplied_argc;
    int exit_code = 0;
    void *owned_args[JINX_NATIVE_SAMPLE_ARGC] = {0};

    if (argc < 3) {
        return fail("oracle-call requires a function name");
    }

    name = argv[2];

    if (jinx_lookup_oracle_wrapper(name) == NULL) {
        fprintf(stderr, "missing: %s\n", name);
        return 1;
    }

    supplied_argc = argc - 3;

    if ((size_t)supplied_argc > JINX_NATIVE_SAMPLE_ARGC) {
        fprintf(stderr, "too many arguments: max %u\n", JINX_NATIVE_SAMPLE_ARGC);
        return 1;
    }

    all_function_args(name, args);

    for (int i = 0; i < supplied_argc; i++) {
        args[i] = parse_cli_value(argv[i + 3], &owned_args[i]);
    }

    result = jinx_call_builtin_through_oracle(name, args, (size_t)supplied_argc);

    if (result.type == 0u) {
        fprintf(stderr, "null/fault: %s\n", name);
        exit_code = 1;
    } else if (hex_output) {
        print_value_hex_line(result);
    } else {
        print_value_line(result);
    }

    release_cli_value(result);
    release_cli_values(args, JINX_NATIVE_SAMPLE_ARGC);
    for (size_t i = 0u; i < JINX_NATIVE_SAMPLE_ARGC; i++) {
        free(owned_args[i]);
    }
    return exit_code;
}

static int command_bench_oracle(int argc, char **argv) {
    long iterations = 1000000;
    JinxValue value;

    if (argc >= 3) {
        iterations = atol(argv[2]);
    }

    if (iterations <= 0) {
        iterations = 1;
    }

    clock_t start = clock();

    for (long i = 0; i < iterations; i++) {
        if (!call_oracle_with_samples("strlen", &value)) {
            return fail("Oracle strlen failed during benchmark");
        }
    }

    clock_t elapsed = clock() - start;
    double seconds = (double) elapsed / (double) CLOCKS_PER_SEC;
    double ns_per_call = seconds * 1000000000.0 / (double) iterations;

    printf("JINX native Oracle strlen benchmark\n");
    printf("Iterations: %ld\n", iterations);
    printf("Result: ");
    print_value(value);
    printf("\n");
    printf("Elapsed ms: %.3f\n", seconds * 1000.0);
    printf("Per call ns: %.1f\n", ns_per_call);
    return 0;
}

int main(int argc, char **argv) {
    if (argc < 2) {
        usage(argv[0]);
        return 1;
    }

    if (ends_with(argv[1], ".php")) {
        return command_php_script(argc, argv);
    }

    if (strcmp(argv[1], "rc") == 0) {
        return command_rc();
    }

    if (strcmp(argv[1], "oracle-smoke") == 0) {
        return command_oracle_smoke();
    }

    if (strcmp(argv[1], "oracle-call") == 0) {
        return command_oracle_call(argc, argv, 0);
    }

    if (strcmp(argv[1], "oracle-call-hex") == 0) {
        return command_oracle_call(argc, argv, 1);
    }

    if (strcmp(argv[1], "bench-oracle") == 0) {
        return command_bench_oracle(argc, argv);
    }

    if (strcmp(argv[1], "first100") == 0) {
        return command_first100();
    }

    if (strcmp(argv[1], "first100-list") == 0) {
        return command_first100_list();
    }

    if (strcmp(argv[1], "bench-first100") == 0) {
        return command_bench_first100(argc, argv);
    }

    if (strcmp(argv[1], "bench-all-functions") == 0) {
        return command_bench_all_functions(argc, argv);
    }

    if (strcmp(argv[1], "functions-count") == 0) {
        return command_functions_count();
    }

    if (strcmp(argv[1], "functions") == 0) {
        return command_functions();
    }

    if (strcmp(argv[1], "function-exists") == 0) {
        return command_function_exists(argc, argv);
    }

    if (strcmp(argv[1], "functions-smoke") == 0) {
        return command_functions_smoke();
    }

    if (strcmp(argv[1], "notes") == 0) {
        return command_notes();
    }

    if (strcmp(argv[1], "benchmarks") == 0) {
        return command_benchmarks();
    }

    usage(argv[0]);
    return 1;
}
