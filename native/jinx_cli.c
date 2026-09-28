#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <math.h>
#include <unistd.h>
#include <sys/stat.h>

#include "../runtime/jinx_function_list.generated.h"
#include "../runtime/jinx_oracle_zend_array_carrier.h"
#include "../runtime/jinx_oracle_extended_builtins.h"
#include "../runtime/jinx_oracle_script_context.h"
#include "../runtime/jinx_zend_array_delete.h"
#include "../runtime/jinx_pasm_machine.h"

#define JINX_NATIVE_SAMPLE_ARGC 32u

static void print_value(JinxValue value);
static void print_value_hex_line(JinxValue value);
static JinxValue make_zend_array_fixture(int deleted);
static JinxValue make_zend_empty_array_fixture(void);
static JinxValue make_zend_string_array_fixture(void);
static JinxValue make_zend_walk_array_fixture(void);
static void release_cli_value(JinxValue value);
static void release_cli_values(JinxValue *values, size_t count);

static void usage(const char *argv0) {
    printf("JINX native GCC CLI\n\n");
    printf("Usage:\n");
    printf("  %s rc\n", argv0);
    printf("  %s oracle-smoke\n", argv0);
    printf("  %s oracle-constant-smoke\n", argv0);
    printf("  %s oracle-frame-smoke\n", argv0);
    printf("  %s oracle-error-smoke\n", argv0);
    printf("  %s oracle-posix-error-smoke\n", argv0);
    printf("  %s oracle-ini-smoke\n", argv0);
    printf("  %s oracle-runtime-state-smoke\n", argv0);
    printf("  %s oracle-strtok-smoke\n", argv0);
    printf("  %s oracle-script-context-smoke <main-file> <included-file>\n", argv0);
    printf("  %s oracle-call <function> [typed-args...]\n", argv0);
    printf("  %s oracle-call-hex <function> [typed-args...]\n", argv0);
    printf("  %s oracle-call-refs <function> [typed-args...]\n", argv0);
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
    printf("  za:empty      native empty Zend array\n");
    printf("  za:deleted    same native Zend array with index 1 and key \"name\" tombstoned\n");
    printf("  za:strings    native Zend array [\"b\", \"a\", \"c\"]\n");
    printf("  za:walk       native Zend array keyed for array_walk(settype)\n");
    printf("  obj:<class>   native object fixture with generated class metadata\n");
    printf("  dt:<text>     native DateTime fixture\n");
    printf("  dti:<text>    native DateTimeImmutable fixture\n");
    printf("  tz:<name>     native DateTimeZone fixture\n");
    printf("  di:<text>     native DateInterval fixture\n");
    printf("  fp:tmp        native temporary stream fixture\n");
    printf("  gz:tmp        native temporary gzip stream fixture\n");
    printf("  deflate:gzip  native gzip DeflateContext fixture\n");
    printf("  hash:<algo>   native HashContext fixture when OpenSSL is available\n");
    printf("  finfo:default native finfo fixture when libmagic is available\n");
    printf("  dir:tmp       native directory-stream fixture\n");
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

static int command_php_frontend(int argc, char **argv) {
    const char *slash = strrchr(argv[0], '/');
    const char *backslash = strrchr(argv[0], '\\');
    const char *separator = slash > backslash ? slash : backslash;
    const char *suffix = "bin/jinx";
    size_t directory_len = separator != NULL ? (size_t)(separator - argv[0] + 1) : 0u;
    char *frontend = (char *)malloc(directory_len + strlen(suffix) + 1u);
    char **php_argv = (char **)calloc((size_t)argc + 2u, sizeof(char *));

    if (frontend == NULL || php_argv == NULL) {
        free(frontend);
        free(php_argv);
        return fail("unable to allocate PHP frontend command");
    }

    if (directory_len != 0u) memcpy(frontend, argv[0], directory_len);
    memcpy(frontend + directory_len, suffix, strlen(suffix) + 1u);

    php_argv[0] = "php";
    php_argv[1] = frontend;
    for (int i = 1; i < argc; i++) php_argv[i + 1] = argv[i];
    php_argv[argc + 1] = NULL;

    execvp("php", php_argv);
    free(frontend);
    free(php_argv);
    return fail("unable to execute PHP Jinx frontend");
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

    if (strcmp(name, "array_all") == 0 || strcmp(name, "array_any") == 0 ||
        strcmp(name, "array_find") == 0 || strcmp(name, "array_find_key") == 0) {
        args[0] = make_zend_array_fixture(0);
        args[1] = jinx_value_string("is_numeric", 10);
        return;
    }

    if (strcmp(name, "array_map") == 0) {
        args[0] = jinx_value_string("abs", 3);
        args[1] = make_zend_array_fixture(0);
        return;
    }

    if (strcmp(name, "array_walk") == 0 || strcmp(name, "array_walk_recursive") == 0) {
        args[0] = make_zend_walk_array_fixture();
        args[1] = jinx_value_string("settype", 7);
        return;
    }

    if (strcmp(name, "usort") == 0 || strcmp(name, "uasort") == 0 ||
        strcmp(name, "uksort") == 0) {
        args[0] = make_zend_string_array_fixture();
        args[1] = jinx_value_string("strcmp", 6);
        return;
    }

    if (strcmp(name, "array_reduce") == 0) {
        args[0] = make_zend_array_fixture(0);
        args[1] = jinx_value_string("max", 3);
        args[2] = jinx_value_int(0);
        return;
    }

    if (strcmp(name, "array_fill") == 0) {
        args[0] = jinx_value_int(0);
        args[1] = jinx_value_int(3);
        args[2] = jinx_value_int(9);
        return;
    }

    if (strcmp(name, "array_combine") == 0) {
        args[0] = make_zend_array_fixture(0);
        args[1] = make_zend_array_fixture(0);
        return;
    }

    if (strcmp(name, "array_chunk") == 0) {
        args[0] = make_zend_array_fixture(0);
        args[1] = jinx_value_int(2);
        return;
    }

    if (strncmp(name, "array_", 6) == 0) {
        args[0] = make_zend_array_fixture(0);
        args[1] = jinx_value_int(0);
        return;
    }

    if (strcmp(name, "cal_days_in_month") == 0) {
        args[0] = jinx_value_int(0);
        args[1] = jinx_value_int(2);
        args[2] = jinx_value_int(2024);
        return;
    }

    if (strcmp(name, "cal_to_jd") == 0) {
        args[0] = jinx_value_int(0);
        args[1] = jinx_value_int(1);
        args[2] = jinx_value_int(1);
        args[3] = jinx_value_int(2024);
        return;
    }

    if (strcmp(name, "call_user_func") == 0) {
        args[0] = jinx_value_string("strlen", 6);
        args[1] = jinx_value_string("oracle", 6);
        return;
    }

    if (strcmp(name, "call_user_func_array") == 0) {
        args[0] = jinx_value_string("max", 3);
        args[1] = make_zend_array_fixture(0);
        return;
    }

    if (strcmp(name, "constant") == 0 || strcmp(name, "defined") == 0) {
        args[0] = jinx_value_string("PHP_VERSION_ID", 14);
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
        args[0] = make_zend_array_fixture(0);
        args[1] = jinx_value_int(0);
        return;
    }
}

static int call_all_function_name(const char *name, JinxValue *out) {
    JinxValue args[JINX_NATIVE_SAMPLE_ARGC];
    uint32_t total_args = 0u;
    int ok = 0;

    if (!jinx_lookup_oracle_arity(name, NULL, &total_args, NULL) ||
        total_args > JINX_NATIVE_SAMPLE_ARGC) {
        *out = jinx_value_null();
        return 0;
    }

    all_function_args(name, args);

    *out = jinx_call_builtin_through_oracle_checked(
        name,
        args,
        (size_t) total_args,
        &ok
    );
    release_cli_values(args, JINX_NATIVE_SAMPLE_ARGC);
    return ok;
}

static int call_first100_name(const char *name, JinxValue *out) {
    return call_all_function_name(name, out);
}

static int call_oracle_with_samples(const char *name, JinxValue *out) {
    JinxValue args[JINX_NATIVE_SAMPLE_ARGC];
    uint32_t total_args = 0u;
    int ok = 0;

    if (!jinx_lookup_oracle_arity(name, NULL, &total_args, NULL) ||
        total_args > JINX_NATIVE_SAMPLE_ARGC) {
        *out = jinx_value_null();
        return 0;
    }

    all_function_args(name, args);
    *out = jinx_call_builtin_through_oracle_checked(
        name,
        args,
        (size_t) total_args,
        &ok
    );
    return ok;
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

static JinxValue make_zend_empty_array_fixture(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(1u);
    JinxValue value;

    if (array == NULL) return jinx_value_null();
    value = jinx_oracle_zend_array_value_retained(array);
    jinx_zend_array_release(array);
    return value;
}

static JinxValue make_zend_string_array_fixture(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(3u);
    JinxZendString *b = NULL;
    JinxZendString *a = NULL;
    JinxZendString *c = NULL;
    JinxValue value;

    if (array == NULL) return jinx_value_null();
    b = jinx_zend_string_new("b", 1u);
    a = jinx_zend_string_new("a", 1u);
    c = jinx_zend_string_new("c", 1u);
    if (b == NULL || a == NULL || c == NULL ||
        !jinx_zend_array_append(array, jinx_zend_string_value(b)) ||
        !jinx_zend_array_append(array, jinx_zend_string_value(a)) ||
        !jinx_zend_array_append(array, jinx_zend_string_value(c))) {
        jinx_zend_string_release(b);
        jinx_zend_string_release(a);
        jinx_zend_string_release(c);
        jinx_zend_array_release(array);
        return jinx_value_null();
    }
    jinx_zend_string_release(b);
    jinx_zend_string_release(a);
    jinx_zend_string_release(c);
    value = jinx_oracle_zend_array_value_retained(array);
    jinx_zend_array_release(array);
    return value;
}

static JinxValue make_zend_walk_array_fixture(void) {
    JinxZendArray *array = jinx_zend_array_new_packed(3u);
    JinxZendString *seven = NULL;
    JinxValue value;

    if (array == NULL) return jinx_value_null();
    seven = jinx_zend_string_new("7", 1u);
    if (seven == NULL ||
        !jinx_zend_array_add_assoc(array, "string", 6u, jinx_zend_long(42)) ||
        !jinx_zend_array_add_assoc(array, "integer", 7u, jinx_zend_string_value(seven)) ||
        !jinx_zend_array_add_assoc(array, "boolean", 7u, jinx_zend_long(0))) {
        jinx_zend_string_release(seven);
        jinx_zend_array_release(array);
        return jinx_value_null();
    }
    jinx_zend_string_release(seven);
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

    if (strcmp(text, "za:empty") == 0) {
        return make_zend_empty_array_fixture();
    }

    if (strcmp(text, "za:deleted") == 0) {
        return make_zend_array_fixture(1);
    }

    if (strcmp(text, "za:strings") == 0) {
        return make_zend_string_array_fixture();
    }

    if (strcmp(text, "za:walk") == 0) {
        return make_zend_walk_array_fixture();
    }

    if (strncmp(text, "obj:", 4) == 0 ||
        strncmp(text, "dt:", 3) == 0 ||
        strncmp(text, "dti:", 4) == 0 ||
        strncmp(text, "tz:", 3) == 0 ||
        strncmp(text, "di:", 3) == 0 ||
        strcmp(text, "fp:tmp") == 0 ||
        strcmp(text, "pp:tmp") == 0 ||
        strcmp(text, "gz:tmp") == 0 ||
        strncmp(text, "deflate:", 8) == 0 ||
        strncmp(text, "hash:", 5) == 0 ||
        strcmp(text, "finfo:default") == 0 ||
        strcmp(text, "dir:tmp") == 0) {
        return jinx_oracle_extended_fixture(text);
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

static void print_php_g_double(double value) {
    char buffer[128];

    if (isnan(value)) {
        fputs("NAN", stdout);
        return;
    }
    if (isinf(value)) {
        fputs(signbit(value) ? "-INF" : "INF", stdout);
        return;
    }

    /*
     * PHP sprintf("%g") uses zend_gcvt() with FLOAT_PRECISION (6).
     * libc %g is numerically compatible for the mantissa/threshold here,
     * but its exponential spelling differs: it may omit the decimal
     * fraction and pads the exponent to two digits (1e-07). PHP emits
     * 1.0e-7. Normalize only that spelling so CLI parity remains exact.
     */
    snprintf(buffer, sizeof(buffer), "%.6g", value);

    char *exponent = strchr(buffer, 'e');
    if (exponent == NULL) exponent = strchr(buffer, 'E');
    if (exponent == NULL) {
        fputs(buffer, stdout);
        return;
    }

    char mantissa[96];
    size_t mantissa_len = (size_t)(exponent - buffer);
    if (mantissa_len >= sizeof(mantissa) - 3u) {
        fputs(buffer, stdout);
        return;
    }

    memcpy(mantissa, buffer, mantissa_len);
    mantissa[mantissa_len] = '\0';
    if (strchr(mantissa, '.') == NULL) {
        mantissa[mantissa_len++] = '.';
        mantissa[mantissa_len++] = '0';
        mantissa[mantissa_len] = '\0';
    }

    const char *p = exponent + 1;
    char sign = '+';
    if (*p == '+' || *p == '-') {
        sign = *p++;
    }
    while (*p == '0' && p[1] != '\0') p++;

    printf("%s%c%c%s", mantissa, *exponent, sign, p);
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
            fputs("float:", stdout);
            print_php_g_double(value.as.f64);
            break;
        case JINX_ORACLE_VALUE_ZEND_ARRAY:
            printf("zend-array:%zu", jinx_zend_array_live_count(jinx_oracle_zend_array_ptr(value)));
            break;
        case JINX_ORACLE_VALUE_ZEND_OBJECT: {
            JinxZendObject *object = jinx_oracle_zend_object_ptr(value);
            printf(
                "zend-object:%s:%zu",
                object != 0 && object->class_name != 0 ? object->class_name : "object",
                object != 0 && object->properties != 0
                    ? jinx_zend_array_live_count(object->properties)
                    : 0u
            );
            break;
        }
        default:
            printf("null");
            break;
    }
}

static void release_cli_value(JinxValue value) {
    jinx_oracle_zend_container_value_release(value);
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
        release_cli_value(result);
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
            release_cli_value(result);
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
            release_cli_value(result);
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
            release_cli_value(result);
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

static int command_oracle_frame_smoke(void) {
    JinxZendExecutor executor;
    JinxZendCallFrame frame;
    JinxZendValue frame_args[3];
    JinxZendString *text_arg;
    JinxValue result;
    JinxValue get_arg_args[1];
    int ok = 0;

    text_arg = jinx_zend_string_new("oracle", 6u);
    if (text_arg == NULL) {
        return fail("could not allocate native frame smoke string");
    }

    frame_args[0] = jinx_zend_long(7);
    frame_args[1] = jinx_zend_string_value(text_arg);
    frame_args[2] = jinx_zend_bool(1);

    jinx_zend_executor_init(&executor);
    jinx_zend_frame_enter(
        &executor,
        &frame,
        "jinx_frame_smoke",
        frame_args,
        3u
    );
    frame.scope_name = "JinxFrameScope";
    jinx_zend_frame_set_callsite(
        &frame,
        "/tmp/jinx-frame-smoke.php",
        41u,
        "::"
    );

    if (!jinx_zend_frame_set_local(
            &frame, "alpha", jinx_zend_long(11)
        ) ||
        !jinx_zend_frame_set_local(
            &frame, "beta", jinx_zend_long(22)
        )) {
        (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
        jinx_zend_string_release(text_arg);
        return fail("could not seed native frame locals");
    }

    result = jinx_call_builtin_through_oracle_checked(
        "func_num_args", NULL, 0u, &ok
    );
    if (!ok || result.type != 1u || result.as.i64 != 3) {
        release_cli_value(result);
        jinx_zend_frame_leave(&executor, jinx_zend_null());
        jinx_zend_string_release(text_arg);
        return fail("func_num_args did not read caller frame argc");
    }
    release_cli_value(result);

    get_arg_args[0] = jinx_value_int(1);
    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "func_get_arg", get_arg_args, 1u, &ok
    );
    if (!ok || result.type != 3u || result.flags != 6u ||
        memcmp(result.as.ptr, "oracle", 6u) != 0) {
        release_cli_value(result);
        jinx_zend_frame_leave(&executor, jinx_zend_null());
        jinx_zend_string_release(text_arg);
        return fail("func_get_arg did not read caller frame argument");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "func_get_args", NULL, 0u, &ok
    );
    if (!ok || !jinx_oracle_value_is_zend_array(result) ||
        jinx_zend_array_live_count(
            jinx_oracle_zend_array_ptr(result)
        ) != 3u) {
        release_cli_value(result);
        jinx_zend_frame_leave(&executor, jinx_zend_null());
        jinx_zend_string_release(text_arg);
        return fail("func_get_args did not copy caller frame arguments");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "get_called_class", NULL, 0u, &ok
    );
    if (!ok || result.type != 3u ||
        result.flags != strlen("JinxFrameScope") ||
        memcmp(
            result.as.ptr,
            "JinxFrameScope",
            strlen("JinxFrameScope")
        ) != 0) {
        release_cli_value(result);
        jinx_zend_frame_leave(&executor, jinx_zend_null());
        jinx_zend_string_release(text_arg);
        return fail("get_called_class did not read frame scope");
    }
    release_cli_value(result);

    {
        JinxValue backtrace_args[2];
        JinxZendArray *trace;
        JinxZendValue *record_value;
        JinxZendArray *record;
        JinxZendValue *function;
        JinxZendValue *class_name;
        JinxZendValue *type;
        JinxZendValue *file;
        JinxZendValue *line;
        JinxZendValue *trace_args;

        backtrace_args[0] = jinx_value_int(0);
        backtrace_args[1] = jinx_value_int(1);
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "debug_backtrace", backtrace_args, 2u, &ok
        );
        if (!ok || !jinx_oracle_value_is_zend_array(result)) {
            release_cli_value(result);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("debug_backtrace did not return native frame array");
        }

        trace = jinx_oracle_zend_array_ptr(result);
        if (jinx_zend_array_live_count(trace) != 1u) {
            release_cli_value(result);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("debug_backtrace limit did not cap native frame count");
        }

        record_value = jinx_zend_array_index(trace, 0u);
        if (record_value == NULL ||
            record_value->type != JINX_ZEND_ARRAY ||
            record_value->value.array == NULL) {
            release_cli_value(result);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("debug_backtrace frame record is not a Zend array");
        }

        record = record_value->value.array;
        function = jinx_zend_array_find(record, "function", 8u);
        class_name = jinx_zend_array_find(record, "class", 5u);
        type = jinx_zend_array_find(record, "type", 4u);
        file = jinx_zend_array_find(record, "file", 4u);
        line = jinx_zend_array_find(record, "line", 4u);
        trace_args = jinx_zend_array_find(record, "args", 4u);

        if (function == NULL || function->type != JINX_ZEND_STRING ||
            function->value.str == NULL ||
            strcmp(function->value.str->bytes, "jinx_frame_smoke") != 0 ||
            class_name == NULL || class_name->type != JINX_ZEND_STRING ||
            class_name->value.str == NULL ||
            strcmp(class_name->value.str->bytes, "JinxFrameScope") != 0 ||
            type == NULL || type->type != JINX_ZEND_STRING ||
            type->value.str == NULL ||
            strcmp(type->value.str->bytes, "::") != 0 ||
            file == NULL || file->type != JINX_ZEND_STRING ||
            file->value.str == NULL ||
            strcmp(file->value.str->bytes, "/tmp/jinx-frame-smoke.php") != 0 ||
            line == NULL || line->type != JINX_ZEND_LONG ||
            line->value.lval != 41 ||
            trace_args == NULL || trace_args->type != JINX_ZEND_ARRAY ||
            trace_args->value.array == NULL ||
            jinx_zend_array_live_count(trace_args->value.array) != 3u) {
            release_cli_value(result);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("debug_backtrace native frame fields mismatch");
        }

        release_cli_value(result);
    }

    {
        JinxValue print_args[2];
        print_args[0] = jinx_value_int(2); /* DEBUG_BACKTRACE_IGNORE_ARGS */
        print_args[1] = jinx_value_int(1);
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "debug_print_backtrace", print_args, 2u, &ok
        );
        if (!ok || result.type != 0u) {
            release_cli_value(result);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("debug_print_backtrace did not return null");
        }
        release_cli_value(result);
    }

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "get_defined_vars", NULL, 0u, &ok
    );
    if (!ok || !jinx_oracle_value_is_zend_array(result) ||
        jinx_zend_array_live_count(
            jinx_oracle_zend_array_ptr(result)
        ) != 2u) {
        release_cli_value(result);
        (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
        jinx_zend_string_release(text_arg);
        return fail("get_defined_vars did not read frame locals");
    }
    release_cli_value(result);

    {
        JinxValue compact_args[2];
        compact_args[0] = jinx_value_string("alpha", 5u);
        compact_args[1] = jinx_value_string("beta", 4u);
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "compact", compact_args, 2u, &ok
        );
        if (!ok || !jinx_oracle_value_is_zend_array(result) ||
            jinx_zend_array_live_count(
                jinx_oracle_zend_array_ptr(result)
            ) != 2u) {
            release_cli_value(result);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("compact did not read frame locals");
        }
        release_cli_value(result);
    }

    {
        JinxZendArray *extract_array = jinx_zend_array_new_packed(2u);
        JinxValue extract_args[2];
        JinxZendValue *alpha;
        JinxZendValue *gamma;

        if (extract_array == NULL ||
            !jinx_zend_array_add_assoc(
                extract_array, "alpha", 5u, jinx_zend_long(99)
            ) ||
            !jinx_zend_array_add_assoc(
                extract_array, "gamma", 5u, jinx_zend_long(33)
            )) {
            jinx_zend_array_release(extract_array);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("could not create extract smoke array");
        }

        extract_args[0] =
            jinx_oracle_zend_array_value_borrowed(extract_array);
        extract_args[1] = jinx_value_int(1); /* EXTR_SKIP */
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "extract", extract_args, 2u, &ok
        );
        if (!ok || result.type != 1u || result.as.i64 != 1) {
            release_cli_value(result);
            jinx_zend_array_release(extract_array);
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("extract EXTR_SKIP returned wrong count");
        }
        release_cli_value(result);
        jinx_zend_array_release(extract_array);

        alpha = jinx_zend_frame_get_local(&frame, "alpha");
        gamma = jinx_zend_frame_get_local(&frame, "gamma");
        if (alpha == NULL || alpha->type != JINX_ZEND_LONG ||
            alpha->value.lval != 11 ||
            gamma == NULL || gamma->type != JINX_ZEND_LONG ||
            gamma->value.lval != 33) {
            (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
            jinx_zend_string_release(text_arg);
            return fail("extract did not mutate frame locals with PHP skip semantics");
        }
    }

    (void)jinx_zend_frame_leave(&executor, jinx_zend_null());
    jinx_zend_string_release(text_arg);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "func_num_args", NULL, 0u, &ok
    );
    release_cli_value(result);
    if (ok) {
        return fail("func_num_args must fault outside function context");
    }

    printf("PASS: native Zend frame context drives func_num_args/func_get_arg/func_get_args/get_called_class/get_defined_vars/compact/extract/debug_backtrace/debug_print_backtrace and clears on frame leave\n");
    return 0;
}

static int command_oracle_error_smoke(void) {
    JinxZendExecutor executor;
    JinxValue result;
    int ok = 0;

    jinx_zend_executor_init(&executor);
    if (!jinx_zend_executor_set_last_error(
            &executor,
            512u,
            "jinx native error",
            "/tmp/jinx-error.php",
            73u
        )) {
        return fail("could not seed Zend executor last-error state");
    }

    result = jinx_call_builtin_through_oracle_checked(
        "error_get_last", NULL, 0u, &ok
    );
    if (!ok || !jinx_oracle_value_is_zend_array(result)) {
        release_cli_value(result);
        jinx_zend_executor_clear_last_error(&executor);
        return fail("error_get_last did not return Zend error array");
    }

    {
        JinxZendArray *array = jinx_oracle_zend_array_ptr(result);
        JinxZendValue *type = jinx_zend_array_find(array, "type", 4u);
        JinxZendValue *message = jinx_zend_array_find(array, "message", 7u);
        JinxZendValue *file = jinx_zend_array_find(array, "file", 4u);
        JinxZendValue *line = jinx_zend_array_find(array, "line", 4u);

        if (type == NULL || type->type != JINX_ZEND_LONG ||
            type->value.lval != 512 ||
            message == NULL || message->type != JINX_ZEND_STRING ||
            message->value.str == NULL ||
            strcmp(message->value.str->bytes, "jinx native error") != 0 ||
            file == NULL || file->type != JINX_ZEND_STRING ||
            file->value.str == NULL ||
            strcmp(file->value.str->bytes, "/tmp/jinx-error.php") != 0 ||
            line == NULL || line->type != JINX_ZEND_LONG ||
            line->value.lval != 73) {
            release_cli_value(result);
            jinx_zend_executor_clear_last_error(&executor);
            return fail("error_get_last returned wrong error fields");
        }
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "error_clear_last", NULL, 0u, &ok
    );
    if (!ok || result.type != 0u) {
        release_cli_value(result);
        jinx_zend_executor_clear_last_error(&executor);
        return fail("error_clear_last did not return null");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "error_get_last", NULL, 0u, &ok
    );
    if (!ok || result.type != 0u) {
        release_cli_value(result);
        jinx_zend_executor_clear_last_error(&executor);
        return fail("error_get_last did not return null after clear");
    }
    release_cli_value(result);

    jinx_zend_executor_clear_last_error(&executor);
    printf("PASS: native Zend executor last-error state drives error_get_last/error_clear_last\n");
    return 0;
}

static int command_oracle_ini_smoke(void) {
    JinxValue args[2];
    JinxValue result;
    int ok = 0;

    args[0] = jinx_value_string("precision", 9u);
    result = jinx_call_builtin_through_oracle_checked("ini_get", args, 1u, &ok);
    if (!ok || result.type != 3u) return fail("initial INI read failed");
    printf("before="); print_value_line(result);
    release_cli_value(result);

    args[1] = jinx_value_string("13", 2u);
    ok = 0;
    result = jinx_call_builtin_through_oracle_checked("ini_set", args, 2u, &ok);
    if (!ok || result.type != 3u) return fail("INI write failed");
    printf("set_old="); print_value_line(result);
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked("ini_get", args, 1u, &ok);
    if (!ok || result.type != 3u) return fail("mutated INI read failed");
    printf("during="); print_value_line(result);
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked("ini_restore", args, 1u, &ok);
    if (!ok || result.type != 0u) return fail("INI restore failed");
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked("ini_get", args, 1u, &ok);
    if (!ok || result.type != 3u) return fail("restored INI read failed");
    printf("restored="); print_value_line(result);
    release_cli_value(result);
    return 0;
}

static int command_oracle_strtok_smoke(void) {
    const char *source = "alpha,beta;;gamma";
    const char *delimiters = ",;";
    JinxValue args[2];
    JinxValue result;
    int ok = 0;

    args[0] = jinx_value_string(source, (uint32_t)strlen(source));
    args[1] = jinx_value_string(delimiters, (uint32_t)strlen(delimiters));
    result = jinx_call_builtin_through_oracle_checked("strtok", args, 2u, &ok);
    if (!ok) return fail("strtok initial call failed");
    printf("first="); print_value_line(result); release_cli_value(result);

    args[0] = args[1];
    for (int i = 0; i < 3; i++) {
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked("strtok", args, 1u, &ok);
        if (!ok) return fail("strtok continuation failed");
        printf("%s=", i == 0 ? "second" : (i == 1 ? "third" : "fourth"));
        print_value_line(result);
        release_cli_value(result);
    }
    return 0;
}

static int command_oracle_runtime_state_smoke(void) {
    JinxValue args[2];
    JinxValue result;
    int ok = 0;

    args[0] = jinx_value_string("JINX_NATIVE_ENV_SMOKE=present", 29u);
    result = jinx_call_builtin_through_oracle_checked("putenv", args, 1u, &ok);
    if (!ok || result.type != 2u) return fail("putenv set failed");
    printf("put_set="); print_value_line(result);
    release_cli_value(result);

    args[0] = jinx_value_string("JINX_NATIVE_ENV_SMOKE", 21u);
    ok = 0;
    result = jinx_call_builtin_through_oracle_checked("getenv", args, 1u, &ok);
    if (!ok) return fail("getenv after putenv failed");
    printf("env_during="); print_value_line(result);
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked("putenv", args, 1u, &ok);
    if (!ok || result.type != 2u) return fail("putenv unset failed");
    printf("put_unset="); print_value_line(result);
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked("getenv", args, 1u, &ok);
    if (!ok) return fail("getenv after unset failed");
    printf("env_after="); print_value_line(result);
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "ignore_user_abort", NULL, 0u, &ok
    );
    if (!ok || result.type != 1u) return fail("ignore_user_abort read failed");
    printf("abort_before="); print_value_line(result);
    {
        int64_t original = result.as.i64;
        release_cli_value(result);
        args[0] = jinx_value_bool(1);
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "ignore_user_abort", args, 1u, &ok
        );
        if (!ok || result.type != 1u) return fail("ignore_user_abort set failed");
        printf("abort_set_old="); print_value_line(result);
        release_cli_value(result);

        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "ignore_user_abort", NULL, 0u, &ok
        );
        if (!ok || result.type != 1u) return fail("ignore_user_abort reread failed");
        printf("abort_during="); print_value_line(result);
        release_cli_value(result);

        args[0] = jinx_value_bool(original != 0);
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "ignore_user_abort", args, 1u, &ok
        );
        if (!ok || result.type != 1u) return fail("ignore_user_abort restore failed");
        release_cli_value(result);
    }

    {
        char *original_timezone = NULL;
        uint32_t original_timezone_len = 0u;

        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "date_default_timezone_get", NULL, 0u, &ok
        );
        if (!ok || result.type != 3u) {
            release_cli_value(result);
            return fail("date_default_timezone_get failed in runtime-state smoke");
        }
        original_timezone_len = result.flags;
        original_timezone = (char *)malloc((size_t)original_timezone_len + 1u);
        if (original_timezone == NULL) {
            release_cli_value(result);
            return fail("could not copy original timezone");
        }
        if (original_timezone_len != 0u) {
            memcpy(original_timezone, result.as.ptr, original_timezone_len);
        }
        original_timezone[original_timezone_len] = '\0';
        release_cli_value(result);

        args[0] = jinx_value_string("UTC", 3u);
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "date_default_timezone_set", args, 1u, &ok
        );
        if (!ok || result.type != 2u || result.as.i64 == 0) {
            free(original_timezone);
            release_cli_value(result);
            return fail("date_default_timezone_set UTC failed");
        }
        printf("tz_set="); print_value_line(result);
        release_cli_value(result);

        args[0] = jinx_value_string(
            "%Y-%m-%d %H:%M:%S",
            (uint32_t)strlen("%Y-%m-%d %H:%M:%S")
        );
        args[1] = jinx_value_int(0);
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "strftime", args, 2u, &ok
        );
        if (!ok || result.type != 3u) {
            free(original_timezone);
            release_cli_value(result);
            return fail("strftime did not observe mutable timezone");
        }
        printf("tz_strftime="); print_value_line(result);
        release_cli_value(result);

        args[0] = jinx_value_string(
            original_timezone,
            original_timezone_len
        );
        ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            "date_default_timezone_set", args, 1u, &ok
        );
        free(original_timezone);
        if (!ok || result.type != 2u || result.as.i64 == 0) {
            release_cli_value(result);
            return fail("date_default_timezone_set restore failed");
        }
        release_cli_value(result);
    }
    return 0;
}

static int command_oracle_posix_error_smoke(void) {
    JinxValue access_args[1];
    JinxValue access_result;
    JinxValue last_result;
    JinxValue errno_result;
    int ok = 0;

    access_args[0] = jinx_value_string(
        "/__jinx_native_posix_missing__",
        (uint32_t)strlen("/__jinx_native_posix_missing__")
    );
    access_result = jinx_call_builtin_through_oracle_checked(
        "posix_access", access_args, 1u, &ok
    );
    if (!ok || access_result.type != 2u || access_result.as.i64 != 0) {
        release_cli_value(access_result);
        return fail("posix_access did not produce false for missing path");
    }

    ok = 0;
    last_result = jinx_call_builtin_through_oracle_checked(
        "posix_get_last_error", NULL, 0u, &ok
    );
    if (!ok || last_result.type != 1u || last_result.as.i64 <= 0) {
        release_cli_value(access_result);
        release_cli_value(last_result);
        return fail("posix_get_last_error did not retain errno");
    }

    ok = 0;
    errno_result = jinx_call_builtin_through_oracle_checked(
        "posix_errno", NULL, 0u, &ok
    );
    if (!ok || errno_result.type != 1u ||
        errno_result.as.i64 != last_result.as.i64) {
        release_cli_value(access_result);
        release_cli_value(last_result);
        release_cli_value(errno_result);
        return fail("posix_errno did not alias posix_get_last_error");
    }

    printf(
        "access=bool:false\nlast=int:%lld\nerrno=int:%lld\n",
        (long long)last_result.as.i64,
        (long long)errno_result.as.i64
    );
    release_cli_value(access_result);
    release_cli_value(last_result);
    release_cli_value(errno_result);
    return 0;
}

static int command_oracle_script_context_smoke(
    int argc,
    char **argv
) {
    struct stat main_stat;
    JinxValue result;
    int ok = 0;

    if (argc < 4) {
        return fail(
            "oracle-script-context-smoke requires main-file and included-file"
        );
    }

    if (stat(argv[2], &main_stat) != 0) {
        return fail("could not stat main script context fixture");
    }

    if (!jinx_oracle_script_context_set_main(argv[2]) ||
        !jinx_oracle_script_context_add_include(argv[3])) {
        jinx_oracle_script_context_clear();
        return fail("could not seed native script/include context");
    }

    result = jinx_call_builtin_through_oracle_checked(
        "get_included_files", NULL, 0u, &ok
    );
    if (!ok || !jinx_oracle_value_is_zend_array(result) ||
        jinx_zend_array_live_count(
            jinx_oracle_zend_array_ptr(result)
        ) != 2u) {
        release_cli_value(result);
        jinx_oracle_script_context_clear();
        return fail("get_included_files did not read script context");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "get_required_files", NULL, 0u, &ok
    );
    if (!ok || !jinx_oracle_value_is_zend_array(result) ||
        jinx_zend_array_live_count(
            jinx_oracle_zend_array_ptr(result)
        ) != 2u) {
        release_cli_value(result);
        jinx_oracle_script_context_clear();
        return fail("get_required_files did not alias included-file context");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "getlastmod", NULL, 0u, &ok
    );
    if (!ok || result.type != 1u ||
        result.as.i64 != (int64_t)main_stat.st_mtime) {
        release_cli_value(result);
        jinx_oracle_script_context_clear();
        return fail("getlastmod did not use main script stat");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "getmyinode", NULL, 0u, &ok
    );
    if (!ok || result.type != 1u ||
        result.as.i64 != (int64_t)main_stat.st_ino) {
        release_cli_value(result);
        jinx_oracle_script_context_clear();
        return fail("getmyinode did not use main script inode");
    }
    release_cli_value(result);

    jinx_oracle_script_context_clear();

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "get_included_files", NULL, 0u, &ok
    );
    release_cli_value(result);
    if (ok) {
        return fail("get_included_files must fault without script context");
    }

    printf("PASS: native script context drives get_included_files/get_required_files/getlastmod/getmyinode\n");
    return 0;
}

static int command_oracle_constant_smoke(void) {
    const char *constant_name = "__JINX_NATIVE_RUNTIME_CONSTANT_SMOKE__";
    JinxValue define_args[2];
    JinxValue lookup_args[1];
    JinxValue result;
    int ok = 0;

    define_args[0] = jinx_value_string(
        constant_name, (uint32_t)strlen(constant_name)
    );
    define_args[1] = jinx_value_int(73);

    result = jinx_call_builtin_through_oracle_checked(
        "define", define_args, 2u, &ok
    );
    if (!ok || result.type != 2u || result.as.i64 == 0) {
        release_cli_value(result);
        return fail("native define did not create runtime constant");
    }
    release_cli_value(result);

    lookup_args[0] = define_args[0];
    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "defined", lookup_args, 1u, &ok
    );
    if (!ok || result.type != 2u || result.as.i64 == 0) {
        release_cli_value(result);
        return fail("native defined did not see runtime constant");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "constant", lookup_args, 1u, &ok
    );
    if (!ok || result.type != 1u || result.as.i64 != 73) {
        release_cli_value(result);
        return fail("native constant did not return runtime constant value");
    }
    release_cli_value(result);

    ok = 0;
    result = jinx_call_builtin_through_oracle_checked(
        "get_defined_constants", NULL, 0u, &ok
    );
    if (!ok || !jinx_oracle_value_is_zend_array(result)) {
        release_cli_value(result);
        return fail("native get_defined_constants did not return registry array");
    }
    {
        JinxZendArray *array = jinx_oracle_zend_array_ptr(result);
        JinxZendValue *slot = jinx_zend_array_find(
            array, constant_name, strlen(constant_name)
        );
        if (slot == NULL || slot->type != JINX_ZEND_LONG ||
            slot->value.lval != 73) {
            release_cli_value(result);
            return fail("runtime constant missing from get_defined_constants");
        }
    }
    release_cli_value(result);

    printf("PASS: native define/defined/constant/get_defined_constants share one runtime registry\n");
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

static int command_oracle_call(int argc, char **argv, int output_mode) {
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

    {
        int oracle_ok = 0;
        result = jinx_call_builtin_through_oracle_checked(
            name,
            args,
            (size_t)supplied_argc,
            &oracle_ok
        );

        if (!oracle_ok) {
            fprintf(stderr, "null/fault: %s\n", name);
            exit_code = 1;
        } else if (output_mode == 1) {
            print_value_hex_line(result);
        } else if (output_mode == 2) {
            printf("return=");
            print_value_line(result);
            for (int i = 0; i < supplied_argc; i++) {
                printf("arg%d=", i);
                print_value_line(args[i]);
            }
        } else {
            print_value_line(result);
        }
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

    if (strcmp(argv[1], "oracle-constant-smoke") == 0) {
        return command_oracle_constant_smoke();
    }

    if (strcmp(argv[1], "oracle-frame-smoke") == 0) {
        return command_oracle_frame_smoke();
    }

    if (strcmp(argv[1], "oracle-error-smoke") == 0) {
        return command_oracle_error_smoke();
    }

    if (strcmp(argv[1], "oracle-posix-error-smoke") == 0) {
        return command_oracle_posix_error_smoke();
    }

    if (strcmp(argv[1], "oracle-ini-smoke") == 0) {
        return command_oracle_ini_smoke();
    }

    if (strcmp(argv[1], "oracle-runtime-state-smoke") == 0) {
        return command_oracle_runtime_state_smoke();
    }

    if (strcmp(argv[1], "oracle-strtok-smoke") == 0) {
        return command_oracle_strtok_smoke();
    }

    if (strcmp(argv[1], "oracle-script-context-smoke") == 0) {
        return command_oracle_script_context_smoke(argc, argv);
    }

    if (strcmp(argv[1], "oracle-call") == 0) {
        return command_oracle_call(argc, argv, 0);
    }

    if (strcmp(argv[1], "oracle-call-hex") == 0) {
        return command_oracle_call(argc, argv, 1);
    }

    if (strcmp(argv[1], "oracle-call-refs") == 0) {
        return command_oracle_call(argc, argv, 2);
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

    return command_php_frontend(argc, argv);
}
