#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "../runtime/jinx_function_list.generated.h"
#include "../runtime/jinx_pasm_machine.h"

static void usage(const char *argv0) {
    printf("JINX native GCC CLI\n\n");
    printf("Usage:\n");
    printf("  %s rc\n", argv0);
    printf("  %s oracle-smoke\n", argv0);
    printf("  %s oracle-call strlen <string>\n", argv0);
    printf("  %s oracle-call count <count>\n", argv0);
    printf("  %s bench-oracle [iterations]\n", argv0);
    printf("  %s first100\n", argv0);
    printf("  %s first100-list\n", argv0);
    printf("  %s bench-first100 [iterations]\n", argv0);
    printf("  %s functions-count\n", argv0);
    printf("  %s functions\n", argv0);
    printf("  %s function-exists <name>\n", argv0);
    printf("  %s functions-smoke\n", argv0);
    printf("  %s notes\n", argv0);
    printf("  %s benchmarks\n", argv0);
}

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

static int oracle_strlen(const char *text, long long *out) {
    JinxValue args[1];
    args[0] = jinx_value_string(text, (uint32_t) strlen(text));

    JinxValue result = jinx_call_builtin_through_oracle("strlen", args, 1);

    if (result.type != 1u) {
        return 0;
    }

    *out = (long long) result.as.i64;
    return 1;
}

static int oracle_count(long long count, long long *out) {
    JinxValue args[1];
    args[0] = jinx_value_array_count((uint32_t) count);

    JinxValue result = jinx_call_builtin_through_oracle("count", args, 1);

    if (result.type != 1u) {
        return 0;
    }

    *out = (long long) result.as.i64;
    return 1;
}

static const char *const first100_names[] = {
    "abs",
    "acos",
    "acosh",
    "addcslashes",
    "addslashes",
    "array_all",
    "array_any",
    "array_change_key_case",
    "array_chunk",
    "array_column",
    "array_combine",
    "array_count_values",
    "array_diff",
    "array_diff_assoc",
    "array_diff_key",
    "array_diff_uassoc",
    "array_diff_ukey",
    "array_fill",
    "array_fill_keys",
    "array_filter",
    "array_find",
    "array_find_key",
    "array_flip",
    "array_intersect",
    "array_intersect_assoc",
    "array_intersect_key",
    "array_intersect_uassoc",
    "array_intersect_ukey",
    "array_is_list",
    "array_key_exists",
    "array_key_first",
    "array_key_last",
    "array_keys",
    "array_map",
    "array_merge",
    "array_merge_recursive",
    "array_pad",
    "array_product",
    "array_reduce",
    "array_replace",
    "array_replace_recursive",
    "array_reverse",
    "array_search",
    "array_slice",
    "array_sum",
    "array_udiff",
    "array_udiff_assoc",
    "array_udiff_uassoc",
    "array_uintersect",
    "array_uintersect_assoc",
    "array_uintersect_uassoc",
    "array_values",
    "asin",
    "asinh",
    "assert",
    "atan",
    "atan2",
    "atanh",
    "base64_decode",
    "base64_encode",
    "base_convert",
    "basename",
    "bin2hex",
    "bindec",
    "boolval",
    "cal_days_in_month",
    "cal_from_jd",
    "cal_info",
    "cal_to_jd",
    "call_user_func",
    "call_user_func_array",
    "ceil",
    "checkdate",
    "checkdnsrr",
    "chop",
    "chr",
    "chunk_split",
    "class_exists",
    "class_implements",
    "class_parents",
    "class_uses",
    "connection_aborted",
    "connection_status",
    "constant",
    "convert_uudecode",
    "convert_uuencode",
    "cos",
    "cosh",
    "count",
    "count_chars",
    "crc32",
    "crypt",
    "ctype_alnum",
    "ctype_alpha",
    "ctype_cntrl",
    "ctype_digit",
    "ctype_graph",
    "ctype_lower",
    "ctype_print",
    "ctype_punct"
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

static void first100_args(const char *name, JinxValue args[8]) {
    for (size_t i = 0; i < 8; i++) {
        args[i] = jinx_value_array_count(4);
    }

    args[1] = jinx_value_int(2);
    args[2] = jinx_value_string("name", 4);
    args[3] = jinx_value_bool(1);
    args[4] = jinx_value_string("callback", 8);
    args[5] = jinx_value_int(0);
    args[6] = jinx_value_string("dompipe", 7);
    args[7] = jinx_value_int(1);

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
        return;
    }

    if (strcmp(name, "bindec") == 0) {
        args[0] = jinx_value_string("1010", 4);
        return;
    }

    if (strncmp(name, "ctype_", 6) == 0) {
        args[0] = jinx_value_string(strcmp(name, "ctype_cntrl") == 0 ? "\n" : "ABC123", strcmp(name, "ctype_cntrl") == 0 ? 1 : 6);
        return;
    }

    if (strcmp(name, "boolval") == 0 || strcmp(name, "assert") == 0) {
        args[0] = jinx_value_bool(1);
        return;
    }
}

static int call_first100_name(const char *name, JinxValue *out) {
    JinxValue args[8];
    first100_args(name, args);

    *out = jinx_call_builtin_through_oracle(name, args, 8);
    return out->type != 0u;
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
        default:
            printf("null");
            break;
    }
}

static int pasm_strlen(const char *text, long long *out) {
    JinxPasmMachine machine;
    JinxValue result;

    const JinxPasmOp program[] = {
        {
            .op = JINX_PASM_PUSH_VALUE,
            .name = NULL,
            .value = {0},
            .argc = 0
        },
        {
            .op = JINX_PASM_CALL_BUILTIN,
            .name = "strlen",
            .value = {0},
            .argc = 1
        },
        {
            .op = JINX_PASM_HALT,
            .name = NULL,
            .value = {0},
            .argc = 0
        }
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
    printf("Implemented native runtime calls: strlen, count\n");
    printf("Use the source package for the full PHP web/worker RC surface.\n");
    return 0;
}

static int command_notes(void) {
    printf("dompipe/jinx RC notes\n\n");
    printf("- 3,527 PHP callable signatures have worker-style wrapper records.\n");
    printf("- The native GCC CLI includes a generated inventory for all 3,527 names.\n");
    printf("- functions-smoke verifies every generated name resolves to a C Oracle dispatch wrapper.\n");
    printf("- Worker execution fails closed for unsafe, unavailable, by-reference, and method-only wrappers.\n");
    printf("- The PHP worker path uses a compact name -> id -> row table.\n");
    printf("- The first hot worker-safe benchmark set also has an Oracle-shaped PHP dispatch layer.\n");
    printf("- This native GCC CLI runs the current C Oracle/PASM dispatcher directly.\n");
    printf("- Native runtime coverage is bounded to strlen, count, and PASM CALL_BUILTIN strlen.\n");
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
    printf("Native GCC benchmark command:\n");
    printf("  jinx bench-oracle 1000000\n");
    printf("  jinx bench-first100 100000\n");
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

    JinxOracleWrapper wrapper = jinx_lookup_oracle_wrapper(argv[2]);
    if (wrapper == NULL) {
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

static int command_oracle_smoke(void) {
    long long value = 0;

    if (!oracle_strlen("oracle", &value) || value != 6) {
        return fail("Oracle strlen did not return 6");
    }

    if (!oracle_count(3, &value) || value != 3) {
        return fail("Oracle count did not return 3");
    }

    if (!pasm_strlen("oracle", &value) || value != 6) {
        return fail("PASM CALL_BUILTIN strlen did not return 6");
    }

    printf("PASS: native GCC jinx executed Oracle strlen/count and PASM CALL_BUILTIN strlen\n");
    return 0;
}

static int command_oracle_call(int argc, char **argv) {
    long long value = 0;

    if (argc < 4) {
        return fail("oracle-call requires a function and value");
    }

    if (strcmp(argv[2], "strlen") == 0) {
        if (!oracle_strlen(argv[3], &value)) {
            return fail("Oracle strlen failed");
        }

        printf("%lld\n", value);
        return 0;
    }

    if (strcmp(argv[2], "count") == 0) {
        if (!oracle_count(atoll(argv[3]), &value)) {
            return fail("Oracle count failed");
        }

        printf("%lld\n", value);
        return 0;
    }

    return fail("native Oracle runtime currently supports strlen and count");
}

static int command_bench_oracle(int argc, char **argv) {
    long iterations = 1000000;
    long long value = 0;

    if (argc >= 3) {
        iterations = atol(argv[2]);
    }

    if (iterations <= 0) {
        iterations = 1;
    }

    clock_t start = clock();

    for (long i = 0; i < iterations; i++) {
        if (!oracle_strlen("oracle", &value)) {
            return fail("Oracle strlen failed during benchmark");
        }
    }

    clock_t elapsed = clock() - start;
    double seconds = (double) elapsed / (double) CLOCKS_PER_SEC;
    double ns_per_call = seconds * 1000000000.0 / (double) iterations;

    printf("JINX native Oracle strlen benchmark\n");
    printf("Iterations: %ld\n", iterations);
    printf("Result: %lld\n", value);
    printf("Elapsed ms: %.3f\n", seconds * 1000.0);
    printf("Per call ns: %.1f\n", ns_per_call);
    return 0;
}

int main(int argc, char **argv) {
    if (argc < 2) {
        usage(argv[0]);
        return 1;
    }

    if (strcmp(argv[1], "rc") == 0) {
        return command_rc();
    }

    if (strcmp(argv[1], "oracle-smoke") == 0) {
        return command_oracle_smoke();
    }

    if (strcmp(argv[1], "oracle-call") == 0) {
        return command_oracle_call(argc, argv);
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
