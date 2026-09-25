#ifndef JINX_PHP_MANUAL_MANIFEST_H
#define JINX_PHP_MANUAL_MANIFEST_H

/*
 * PHP manual implementation manifest for the native ./jinx executable.
 *
 * This file is intentionally source-code, not prose-only documentation.  It is
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
        "string-transform",
        "https://www.php.net/manual/en/ref.strings.php",
        "basename/bin2hex/chr/dirname/md5/sha1/strtolower/strtoupper/trim/etc.",
        "string|array|bool|int depending on function",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_PLACEHOLDER,
        "Currently grouped as coarse native carriers. Replace with manual-specific C handlers before claiming exact behavior."
    },
    {
        "array-functions",
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

#endif /* JINX_PHP_MANUAL_MANIFEST_H */
