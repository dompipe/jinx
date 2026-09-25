#ifndef JINX_PHP_MANUAL_MANIFEST_H
#define JINX_PHP_MANUAL_MANIFEST_H

#include <string.h>

/*
 * PHP manual implementation manifest for the native ./jinx executable.
 *
 * This file is intentionally source-code, not prose-only documentation. It is
 * compiled into the native jinx executable through scripts/build-native-jinx.sh
 * and records which PHP-manual behavior families have exact native handlers,
 * which ones are approximation carriers, and which ones still need exact C /
 * Oracle / PASM lowering work.
 *
 * Manual source policy:
 *   1. Read the PHP manual page for a function or function family first.
 *   2. Record the manual contract here: prototype shape, return family,
 *      notable edge behavior, and native handler state.
 *   3. Add or replace the native behavior in build/oracle-asm/
 *      jinx_oracle_asm_runtime.h or a future split handler file.
 *   4. Only move a family to exact/manual-complete when the JINX return value
 *      follows the documented PHP behavior for the supported JinxValue types.
 */

typedef enum JinxPhpManualHandlerState {
    JINX_PHP_MANUAL_EXACT = 1,
    JINX_PHP_MANUAL_PARTIAL = 2,
    JINX_PHP_MANUAL_PLACEHOLDER = 3,
    JINX_PHP_MANUAL_UNSAFE_NATIVE = 4
} JinxPhpManualHandlerState;

typedef struct JinxPhpManualHandlerSpec {
    const char *pattern;
    const char *manual_page;
    const char *prototype;
    const char *return_family;
    const char *native_handler;
    JinxPhpManualHandlerState state;
    const char *notes;
} JinxPhpManualHandlerSpec;

static const JinxPhpManualHandlerSpec jinx_php_manual_handler_specs[] = {
    {
        "strlen",
        "https://www.php.net/manual/en/function.strlen.php",
        "strlen(string $string): int",
        "int byte length",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Uses the string byte length held in JinxValue.flags. PHP strlen is byte-count oriented, not character-count oriented."
    },
    {
        "count",
        "https://www.php.net/manual/en/function.count.php",
        "count(Countable|array $value, int $mode = COUNT_NORMAL): int",
        "int element count",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PARTIAL,
        "Exact for JinxValue array-count stand-ins. Recursive mode and Countable object behavior still need object/runtime support."
    },
    {
        "abs",
        "https://www.php.net/manual/en/function.abs.php",
        "abs(int|float $num): int|float",
        "int|float absolute value",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PARTIAL,
        "Native handler currently preserves integer path. Float-preserving return needs exact type mirroring."
    },
    {
        "math-trig-log",
        "https://www.php.net/manual/en/ref.math.php",
        "acos/acosh/asin/asinh/atan/atan2/atanh/ceil/floor/sqrt/sin/sinh/tan/tanh/exp/expm1/log/log10",
        "float",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PARTIAL,
        "Backed by C libm for supported scalar arguments. PHP warning/NAN edge behavior is not completely mirrored yet."
    },
    {
        "str_contains",
        "https://www.php.net/manual/en/function.str-contains.php",
        "str_contains(string $haystack, string $needle): bool",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PARTIAL,
        "Needs exact empty-needle and binary-safe substring behavior for native JinxValue strings."
    },
    {
        "str_starts_with",
        "https://www.php.net/manual/en/function.str-starts-with.php",
        "str_starts_with(string $haystack, string $needle): bool",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PARTIAL,
        "Needs exact empty-needle and byte-prefix behavior."
    },
    {
        "str_ends_with",
        "https://www.php.net/manual/en/function.str-ends-with.php",
        "str_ends_with(string $haystack, string $needle): bool",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PARTIAL,
        "Needs exact empty-needle and byte-suffix behavior."
    },
    {
        "ctype_",
        "https://www.php.net/manual/en/ref.ctype.php",
        "ctype_* functions",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PARTIAL,
        "Native bool carrier exists. Exact locale/byte-class behavior needs per-function handlers."
    },
    {
        "string-transform",
        "https://www.php.net/manual/en/ref.strings.php",
        "basename/bin2hex/chr/dirname/md5/sha1/strtolower/strtoupper/trim/etc.",
        "string|array|bool|int depending on function",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PLACEHOLDER,
        "Currently grouped as coarse native carriers. Replace with manual-specific C handlers before claiming exact behavior."
    },
    {
        "array_",
        "https://www.php.net/manual/en/ref.array.php",
        "array_* family",
        "array|bool|int|string depending on function",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PLACEHOLDER,
        "Current JinxValue only has array-count stand-ins. Exact array behavior needs native array storage."
    },
    {
        "class-object-reflection",
        "https://www.php.net/manual/en/ref.classobj.php",
        "class_exists/interface_exists/trait_exists/get_class/etc.",
        "bool|string|array depending on function",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PLACEHOLDER,
        "Needs class table and object model before exact PHP behavior can be claimed."
    },
    {
        "filesystem-stream-process-network-session-db",
        "https://www.php.net/manual/en/refs.fileprocess.file.php",
        "filesystem, stream, process, network, session, database side-effect functions",
        "mixed",
        "fail-closed or explicit sandbox handler",
        JINX_PHP_MANUAL_UNSAFE_NATIVE,
        "Do not implement as blind host calls. These need explicit sandbox/security policy and deterministic test fixtures."
    }
};

static inline const char *jinx_php_manual_state_name(JinxPhpManualHandlerState state) {
    switch (state) {
        case JINX_PHP_MANUAL_EXACT:
            return "exact";
        case JINX_PHP_MANUAL_PARTIAL:
            return "partial";
        case JINX_PHP_MANUAL_PLACEHOLDER:
            return "placeholder";
        case JINX_PHP_MANUAL_UNSAFE_NATIVE:
            return "unsafe-native";
        default:
            return "unknown";
    }
}

static inline unsigned long jinx_php_manual_handler_spec_count(void) {
    return (unsigned long)(sizeof(jinx_php_manual_handler_specs) / sizeof(jinx_php_manual_handler_specs[0]));
}

static inline int jinx_php_manual_name_has(const char *name, const char *needle) {
    return name != 0 && needle != 0 && strstr(name, needle) != 0;
}

static inline int jinx_php_manual_name_starts(const char *name, const char *prefix) {
    return name != 0 && prefix != 0 && strncmp(name, prefix, strlen(prefix)) == 0;
}

static inline int jinx_php_manual_name_is_math_trig_log(const char *name) {
    return strcmp(name, "acos") == 0 || strcmp(name, "acosh") == 0 ||
        strcmp(name, "asin") == 0 || strcmp(name, "asinh") == 0 ||
        strcmp(name, "atan") == 0 || strcmp(name, "atan2") == 0 ||
        strcmp(name, "atanh") == 0 || strcmp(name, "ceil") == 0 ||
        strcmp(name, "floor") == 0 || strcmp(name, "sqrt") == 0 ||
        strcmp(name, "sin") == 0 || strcmp(name, "sinh") == 0 ||
        strcmp(name, "tan") == 0 || strcmp(name, "tanh") == 0 ||
        strcmp(name, "exp") == 0 || strcmp(name, "expm1") == 0 ||
        strcmp(name, "log") == 0 || strcmp(name, "log10") == 0;
}

static inline const JinxPhpManualHandlerSpec *jinx_php_manual_lookup(const char *name) {
    unsigned long i;

    if (name == 0) {
        return 0;
    }

    for (i = 0; i < jinx_php_manual_handler_spec_count(); i++) {
        const JinxPhpManualHandlerSpec *spec = &jinx_php_manual_handler_specs[i];
        if (strcmp(name, spec->pattern) == 0) {
            return spec;
        }
    }

    if (jinx_php_manual_name_is_math_trig_log(name)) {
        return &jinx_php_manual_handler_specs[3];
    }

    if (jinx_php_manual_name_starts(name, "ctype_")) {
        return &jinx_php_manual_handler_specs[7];
    }

    if (jinx_php_manual_name_starts(name, "array_")) {
        return &jinx_php_manual_handler_specs[9];
    }

    if (jinx_php_manual_name_has(name, "class") || jinx_php_manual_name_has(name, "Class") ||
        jinx_php_manual_name_has(name, "Reflection") || jinx_php_manual_name_has(name, "::")) {
        return &jinx_php_manual_handler_specs[10];
    }

    if (jinx_php_manual_name_has(name, "file") || jinx_php_manual_name_has(name, "stream") ||
        jinx_php_manual_name_has(name, "socket") || jinx_php_manual_name_has(name, "session") ||
        jinx_php_manual_name_has(name, "exec") || jinx_php_manual_name_has(name, "proc") ||
        jinx_php_manual_name_has(name, "curl") || jinx_php_manual_name_has(name, "pdo") ||
        jinx_php_manual_name_has(name, "mysqli") || jinx_php_manual_name_has(name, "mysql")) {
        return &jinx_php_manual_handler_specs[11];
    }

    return &jinx_php_manual_handler_specs[8];
}

static inline int jinx_php_manual_is_exact(const char *name) {
    const JinxPhpManualHandlerSpec *spec = jinx_php_manual_lookup(name);
    return spec != 0 && spec->state == JINX_PHP_MANUAL_EXACT;
}

#endif /* JINX_PHP_MANUAL_MANIFEST_H */
