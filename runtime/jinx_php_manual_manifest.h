#ifndef JINX_PHP_MANUAL_MANIFEST_H
#define JINX_PHP_MANUAL_MANIFEST_H

#include <string.h>

/*
 * PHP manual implementation manifest for the native ./jinx executable.
 *
 * States are deliberately explicit:
 *   exact           implemented natively for the supported JinxValue model;
 *   php-fallback    correctness is delegated to original PHP, not fake native;
 *   sandbox-blocked side-effect/native host calls are blocked until sandboxed.
 */

typedef enum JinxPhpManualHandlerState {
    JINX_PHP_MANUAL_EXACT = 1,
    JINX_PHP_MANUAL_PARTIAL = 2,
    JINX_PHP_MANUAL_PLACEHOLDER = 3,
    JINX_PHP_MANUAL_UNSAFE_NATIVE = 4,
    JINX_PHP_MANUAL_PHP_FALLBACK = 5,
    JINX_PHP_MANUAL_SANDBOX_BLOCKED = 6
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
        JINX_PHP_MANUAL_EXACT,
        "Exact for the native JinxValue array-count model. Full PHP arrays/Countable are routed through PHP fallback until native containers exist."
    },
    {
        "abs",
        "https://www.php.net/manual/en/function.abs.php",
        "abs(int|float $num): int|float",
        "int|float absolute value",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Preserves int versus float return family for native int/float JinxValue inputs."
    },
    {
        "math-trig-log",
        "https://www.php.net/manual/en/ref.math.php",
        "acos/acosh/asin/asinh/atan/atan2/atanh/ceil/floor/sqrt/sin/sinh/cos/cosh/tan/tanh/exp/expm1/log/log10/log1p",
        "float",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Backed by C libm for native scalar JinxValue inputs. PHP warning surface is outside the current scalar native value model."
    },
    {
        "math-core",
        "https://www.php.net/manual/en/ref.math.php",
        "fmod/fdiv/intdiv/deg2rad/rad2deg/pi/hypot/is_finite/is_infinite/is_nan/pow(native int|float)/fpow(float)/round(integer PHP_ROUND_* modes 1-8)",
        "int|float|bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Backed by C arithmetic and libm for native scalar JinxValue inputs; exceptional warning/error paths stay outside the current scalar native value model."
    },
    {
        "str_contains",
        "https://www.php.net/manual/en/function.str-contains.php",
        "str_contains(string $haystack, string $needle): bool",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Binary-safe byte substring check, including the documented empty-needle true case."
    },
    {
        "str_starts_with",
        "https://www.php.net/manual/en/function.str-starts-with.php",
        "str_starts_with(string $haystack, string $needle): bool",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Binary-safe byte prefix check, including the documented empty-needle true case."
    },
    {
        "str_ends_with",
        "https://www.php.net/manual/en/function.str-ends-with.php",
        "str_ends_with(string $haystack, string $needle): bool",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Binary-safe byte suffix check, including the documented empty-needle true case."
    },
    {
        "ctype_",
        "https://www.php.net/manual/en/ref.ctype.php",
        "ctype_* functions",
        "bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Native libc ctype byte-class checks for string JinxValue inputs using the process's current locale, matching PHP ctype's locale-sensitive contract; empty strings return false."
    },
    {
        "scalar-core",
        "https://www.php.net/manual/en/ref.var.php",
        "is_null/is_bool/is_int/is_float/is_string/is_array/is_scalar/is_numeric/is_countable/is_iterable/is_object/is_resource/gettype/get_debug_type/boolval/intval/floatval/doubleval/strval/settype(scalar int|integer|float|double|string|bool|boolean|null targets)",
        "bool|int|float|string",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Exact for the current native JinxValue scalar and array carriers, including numeric string conversion and scalar/array type inspection. Positive object/resource/Traversable/Countable-object cases remain with the class/resource fallback until those carriers exist. settype is native for scalar target names int/integer, float/double, string, bool/boolean, and null using writable Oracle reference arguments; array/object/resource conversions remain outside this scalar subset."
    },
    {
        "crypto-php-fallback",
        "https://www.php.net/manual/en/refs.crypto.php",
        "hash/md5/sha1/crypt/password_*/random_*/openssl_* selected pure value functions",
        "string|bool|int|array depending on function",
        "WebNativeFunctions::callPhpCryptoFallback",
        JINX_PHP_MANUAL_PHP_FALLBACK,
        "Crypto-sensitive behavior uses original PHP directly for correctness until exact native crypto handlers exist. This is not native ASM-complete."
    },
    {
        "string-byte-transform",
        "https://www.php.net/manual/en/ref.strings.php",
        "strtolower/strtoupper/lcfirst/ucfirst/strrev/trim/ltrim/rtrim/chop/chr/ord/substr/strpos/stripos/strrpos/strripos/strstr/strchr/stristr/strrchr/strspn/strcspn/ucwords/str_repeat/str_increment/str_decrement/bin2hex/hex2bin/str_rot13/addslashes/stripslashes/quotemeta/strpbrk/chunk_split",
        "string|int|bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Exact for ASCII byte transforms, default trim characters, bounded byte primitives, and valid-offset forward/backward byte searches in the current native JinxValue string model."
    },
    {
        "string-byte-compare",
        "https://www.php.net/manual/en/ref.strings.php",
        "strcmp/strcasecmp/strncmp/strncasecmp/strnatcmp/strnatcasecmp/substr_compare/substr_count",
        "int",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Exact byte comparisons and non-overlapping substring counts for the current native JinxValue string model; exceptional empty-needle paths remain outside the scalar CLI model."
    },
    {
        "string-transform",
        "https://www.php.net/manual/en/ref.strings.php",
        "basename/bin2hex/chr/dirname/strtolower/strtoupper/trim/etc.",
        "string|array|bool|int depending on function",
        "WebNativeFunctions / original PHP fallback for exact behavior",
        JINX_PHP_MANUAL_PHP_FALLBACK,
        "Complex string transforms use original PHP for exact behavior until each function has a native manual-derived handler."
    },
    {
        "array_",
        "https://www.php.net/manual/en/ref.array.php",
        "array_* family",
        "array|bool|int|string depending on function",
        "WebNativeFunctions / original PHP fallback for exact behavior",
        JINX_PHP_MANUAL_PHP_FALLBACK,
        "Array functions use original PHP for exact behavior until native array storage exists. The native array-count stand-in remains for traversal only."
    },
    {
        "class-object-reflection",
        "https://www.php.net/manual/en/ref.classobj.php",
        "class_exists/interface_exists/trait_exists/get_class/etc.",
        "bool|string|array depending on function",
        "WebNativeFunctions / original PHP fallback for exact behavior",
        JINX_PHP_MANUAL_PHP_FALLBACK,
        "Class/object/reflection behavior uses original PHP until JINX has native class tables and object storage."
    },
    {
        "filesystem-stream-process-network-session-db",
        "https://www.php.net/manual/en/refs.fileprocess.file.php",
        "filesystem, stream, process, network, session, database side-effect functions",
        "mixed",
        "sandbox-blocked",
        JINX_PHP_MANUAL_SANDBOX_BLOCKED,
        "Resolved by policy: these are blocked from blind native execution until an explicit sandbox/security policy exists."
    },
    {
        "pure-value-core",
        "https://www.php.net/manual/en/refs.basic.text.php",
        "base64_encode/base64_decode/urlencode/urldecode/rawurlencode/rawurldecode/basename/dirname/base_convert/bindec/hexdec/octdec/decbin/dechex/decoct/crc32/checkdate/nl2br/number_format/addcslashes/stripcslashes/str_pad/str_replace/str_ireplace/strtr(3-arg)/levenshtein/htmlspecialchars/htmlspecialchars_decode/sprintf/printf/wordwrap/convert_uuencode/convert_uudecode/soundex/quoted_printable_encode/quoted_printable_decode/similar_text(with optional percent by-ref)/metaphone/strcoll/substr_replace(scalar)/utf8_encode/utf8_decode(valid UTF-8)/hebrev(legacy bytes)/image_type_to_mime_type/image_type_to_extension(PHP 8.4 fixed types)/version_compare(PHP 8.4 ordering and operators)/ip2long/long2ip/inet_pton/inet_ntop",
        "string|int|bool",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Native Oracle ASM handlers for pure scalar/string behavior in the current JinxValue model; host-state and container forms remain outside this family."
    },
    {
        "zend-array-native-core",
        "https://www.php.net/manual/en/ref.array.php",
        "min/max(numeric scalar variadic or numeric carried array)/count/sizeof/current/pos/key/next/prev/reset/end(carried arrays)/in_array(scalar)/array_search(scalar)/array_key_exists/key_exists/array_is_list/array_values/array_keys/array_key_first/array_key_last/array_sum/array_product/array_reverse/array_slice/array_merge/array_merge_recursive/array_replace/array_replace_recursive/array_flip/array_change_key_case/array_fill_keys/array_combine/array_count_values/array_column/array_chunk/array_pad/array_unique(default SORT_STRING)/array_filter(null or named Oracle string callback; modes 0/1/2)/array_map(null or named Oracle string callback)/array_reduce(named Oracle string callback)/array_find/array_find_key/array_any/array_all(named Oracle string callback)/array_walk/array_walk_recursive(named Oracle callback with first-argument writeback; recursive arrays; optional userdata)/array_rand(shared MT19937 state; carried non-empty arrays; one or N valid keys)/array_push/array_pop/array_shift/array_unshift/array_splice/array_diff/array_diff_assoc/array_diff_key/array_intersect/array_intersect_assoc/array_intersect_key/array_diff_uassoc/array_diff_ukey/array_udiff/array_udiff_assoc/array_udiff_uassoc/array_intersect_uassoc/array_intersect_ukey/array_uintersect/array_uintersect_assoc/array_uintersect_uassoc(named pure Oracle comparator callbacks; scalar comparator-compatible values/keys)/sort/rsort/asort/arsort/ksort/krsort(scalar values; SORT_REGULAR|SORT_NUMERIC|SORT_STRING|SORT_NATURAL plus SORT_FLAG_CASE; excludes SORT_LOCALE_STRING)/natsort/natcasesort/usort/uasort/uksort(named pure Oracle comparator callbacks)/shuffle(shared MT19937 state; drops keys/reindexes; resets internal pointer)/array_multisort(equal-length carried arrays; per-column SORT_ASC|SORT_DESC and non-locale scalar sort modes)",
        "array|bool|int|string|float",
        "jinx_oracle_zend_array_dispatch_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Native carried JinxZendArray handlers with tombstone-aware iteration, key preservation, numeric aggregation, slicing, merging, replacement, flipping, case conversion, key filling, combining, and value counting. array_key_exists/key_exists use PHP's (key, array) order, and sizeof is the count alias. The carried-array internal pointer mirrors Zend raw bucket positions for current/pos/key/next/prev/reset/end, including invalid before-start/after-end sentinel behavior; object-property pointer forms remain outside this subset. min/max are exact here for PHP-parity-tested native int/float scalar variadics and single carried arrays, preserving the first winning value/type on ties; PHP mixed-type/object comparison rules remain outside this subset. Native sorting covers scalar carried values/keys with stable PHP-style reindex-or-preserve behavior and SORT_REGULAR, SORT_NUMERIC, SORT_STRING, SORT_NATURAL, and SORT_FLAG_CASE; SORT_LOCALE_STRING remains outside the built-in flag sort subset. usort/uasort/uksort are native for pure named Oracle comparator callbacks, preserving PHP 8 stable ties; closures/object callables and comparator side-effect ordering remain outside this subset. array_multisort is native for equal-length carried arrays with scalar values, stable lexicographic column tie-breaking, per-column SORT_ASC/SORT_DESC, and the same non-locale sort modes; numeric keys are reindexed and string keys preserved. Named-callback array helpers route string callable names back through checked Oracle dispatch: array_map supports null or named callbacks (one-array key preservation and multi-array max-length/null padding), array_reduce supports named callbacks with a persistent accumulator, array_filter supports null or named callbacks for modes 0/1/2, and PHP 8.4 array_find/array_find_key/array_any/array_all pass (value,key). Closures and object/array callables remain outside this subset. array_walk and array_walk_recursive support named Oracle callbacks with writable first-argument callback argv, copy the callback-updated value back into each bucket, pass optional userdata, and recurse through carried nested arrays with cycle detection; structural mutation during iteration is outside this subset. array_rand uses the shared native MT19937 state and is PHP-parity-tested for explicitly seeded one-key and multi-key selection on carried arrays; invalid count/empty-array exception transport remains outside the scalar carrier. shuffle uses that same shared MT19937 stream, performs PHP-style Fisher-Yates, discards original keys, reindexes 0..n-1, and resets the carried array internal pointer; seeded cross-call parity with mt_rand is covered. The ten user-comparator diff/intersect variants are native for pure named Oracle comparator callbacks on scalar comparator-compatible values/keys; the implementation preserves first-array keys and enforces diff-any versus intersect-all matching across multiple arrays, while comparator call ordering/side effects are not claimed."
    },
    {
        "zend-container-value-core",
        "https://www.php.net/manual/en/refs.basic.text.php",
        "count_chars/str_word_count(0/1/2)/implode/join/vsprintf/vprintf/explode/str_split/str_getcsv(explicit valid controls)/strip_tags(string|array|null allow-list)/parse_url(common legacy forms, default array and components 0..7)/http_build_query(array, nested arrays, numeric prefix, explicit/default & separator, RFC1738/RFC3986)/parse_str(common &, URL-decoded names/values, name mangling, bracket arrays by-ref)/pathinfo(naive path array and flags 1/2/4/8)/localeconv(current host locale 18-field array)/range(integer)/array_fill",
        "string|array|bool",
        "jinx_oracle_zend_array_dispatch_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Native container-producing or container-consuming value helpers using the JinxZendArray carrier. Range is exact for the integer form implemented here. parse_url exactness here covers the PHP-parity-tested common legacy forms and components 0..7; malformed legacy URL oddities and invalid-component exception parity remain outside this exact subset. http_build_query exactness here covers carried arrays/nested arrays with the tested scalar conversions, top-level numeric prefixing, '&' or explicit separators, and RFC1738/RFC3986 encoding; object properties and INI-driven separator variations remain outside this subset. parse_str exactness here covers the PHP-parity-tested '&' separator, URL decoding, top-level space/dot name mangling, and nested bracket-array forms; max_input_vars, alternate arg_separator.input settings, and malformed bracket edge cases remain outside this subset. pathinfo exactness here covers the PHP-parity-tested naive path forms, last-extension behavior, dot-files, no-extension omission, and component flags; locale-dependent multibyte parsing and platform-specific Windows separator behavior remain platform-bound. localeconv mirrors libc's current process locale and returns the documented 18-field structure including grouping arrays; values are intentionally host/locale dependent just as in PHP."
    },
    {
        "mt19937-core",
        "https://www.php.net/manual/en/ref.random.php",
        "mt_srand/srand/mt_rand/rand/mt_getrandmax/getrandmax",
        "void|int",
        "jinx_oracle_mt_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Shared native MT19937 state. PHP 8.4 parity is covered for an explicit seed, unbounded mt_rand, bounded mt_rand, rand with reversed bounds, and both max helpers. Automatic seeding is supported; nondeterministic seed-value equality is intentionally not a parity assertion."
    },
    {
        "calendar-core",
        "https://www.php.net/manual/en/ref.calendar.php",
        "gregoriantojd/juliantojd/jdtogregorian/jdtojulian/frenchtojd/jdtofrench/jewishtojd/jdtojewish(numeric form)/jddayofweek/jdmonthname/cal_to_jd/cal_from_jd/cal_info/cal_days_in_month/easter_days/easter_date",
        "int|string|array",
        "jinx_oracle_asm_call_builtin + jinx_oracle_zend_array_dispatch_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Native PHP-calendar arithmetic for Gregorian, Julian, French Republican, and Jewish conversions, weekday/month display helpers, and carried-array cal_from_jd/cal_info results. PHP-derived parity covers representative boundary/leap cases, all four calendar IDs for cal_to_jd/cal_days_in_month, every jdmonthname mode, jddayofweek number/long/short modes, four-calendar cal_from_jd field sets, and cal_info single/all-calendar metadata. Easter calculations use PHP 8.4's Julian/Gregorian cutoff methods; easter_date mirrors PHP's process-local mktime/DST behavior and explicit-year range rules. Hebrew-form jdtojewish(..., true), including ISO-8859-8 Hebrew-number formatting flags, remains outside this native subset; invalid-ID exception transport remains outside the scalar carrier."
    },
    {
        "zlib-pure-core",
        "https://www.php.net/manual/en/ref.zlib.php",
        "gzcompress/gzuncompress/gzdeflate/gzinflate/gzencode/gzdecode/zlib_encode/zlib_decode",
        "string|false",
        "jinx_oracle_asm_call_builtin",
        JINX_PHP_MANUAL_EXACT,
        "Native one-shot zlib compression/decompression using the system zlib library linked into ./jinx. PHP 8.4 window modes are preserved: raw=-15, deflate=15, gzip=31, and zlib_decode auto-detect=47 with raw retry. Compression levels -1..9, explicit zlib_encode encoding, decoder max_length, binary-safe output, and PHP-derived byte parity are covered; PHP warning/ValueError transport remains outside the scalar carrier."
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
        case JINX_PHP_MANUAL_PHP_FALLBACK:
            return "php-fallback";
        case JINX_PHP_MANUAL_SANDBOX_BLOCKED:
            return "sandbox-blocked";
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
        strcmp(name, "cos") == 0 || strcmp(name, "cosh") == 0 ||
        strcmp(name, "tan") == 0 || strcmp(name, "tanh") == 0 ||
        strcmp(name, "exp") == 0 || strcmp(name, "expm1") == 0 ||
        strcmp(name, "log") == 0 || strcmp(name, "log10") == 0 ||
        strcmp(name, "log1p") == 0;
}

static inline int jinx_php_manual_name_is_math_core(const char *name) {
    return strcmp(name, "fmod") == 0 || strcmp(name, "fdiv") == 0 ||
        strcmp(name, "intdiv") == 0 ||
        strcmp(name, "deg2rad") == 0 || strcmp(name, "rad2deg") == 0 ||
        strcmp(name, "pi") == 0 || strcmp(name, "hypot") == 0 ||
        strcmp(name, "is_finite") == 0 || strcmp(name, "is_infinite") == 0 ||
        strcmp(name, "is_nan") == 0 || strcmp(name, "pow") == 0 ||
        strcmp(name, "fpow") == 0 || strcmp(name, "round") == 0;
}

static inline int jinx_php_manual_name_is_crypto(const char *name) {
    return strcmp(name, "crc32") == 0 || strcmp(name, "crypt") == 0 ||
        strcmp(name, "md5") == 0 || strcmp(name, "md5_file") == 0 ||
        strcmp(name, "sha1") == 0 || strcmp(name, "sha1_file") == 0 ||
        jinx_php_manual_name_starts(name, "hash") ||
        jinx_php_manual_name_starts(name, "password_") ||
        jinx_php_manual_name_starts(name, "random_") ||
        jinx_php_manual_name_starts(name, "openssl_") ||
        jinx_php_manual_name_starts(name, "sodium_");
}

static inline int jinx_php_manual_name_is_scalar_core(const char *name) {
    return strcmp(name, "is_null") == 0 || strcmp(name, "is_bool") == 0 ||
        strcmp(name, "is_int") == 0 || strcmp(name, "is_integer") == 0 ||
        strcmp(name, "is_long") == 0 || strcmp(name, "is_float") == 0 ||
        strcmp(name, "is_double") == 0 || strcmp(name, "is_real") == 0 ||
        strcmp(name, "is_string") == 0 || strcmp(name, "is_array") == 0 ||
        strcmp(name, "is_scalar") == 0 || strcmp(name, "is_numeric") == 0 ||
        strcmp(name, "is_countable") == 0 || strcmp(name, "is_iterable") == 0 ||
        strcmp(name, "is_object") == 0 || strcmp(name, "is_resource") == 0 ||
        strcmp(name, "gettype") == 0 || strcmp(name, "get_debug_type") == 0 ||
        strcmp(name, "boolval") == 0 || strcmp(name, "intval") == 0 ||
        strcmp(name, "floatval") == 0 || strcmp(name, "doubleval") == 0 ||
        strcmp(name, "strval") == 0 || strcmp(name, "settype") == 0;
}

static inline int jinx_php_manual_name_is_string_byte_transform(const char *name) {
    return strcmp(name, "strtolower") == 0 || strcmp(name, "strtoupper") == 0 ||
        strcmp(name, "lcfirst") == 0 || strcmp(name, "ucfirst") == 0 ||
        strcmp(name, "strrev") == 0 || strcmp(name, "trim") == 0 ||
        strcmp(name, "ltrim") == 0 || strcmp(name, "rtrim") == 0 ||
        strcmp(name, "chop") == 0 || strcmp(name, "chr") == 0 ||
        strcmp(name, "ord") == 0 || strcmp(name, "substr") == 0 ||
        strcmp(name, "strpos") == 0 || strcmp(name, "stripos") == 0 ||
        strcmp(name, "strrpos") == 0 || strcmp(name, "strripos") == 0 ||
        strcmp(name, "strstr") == 0 || strcmp(name, "strchr") == 0 ||
        strcmp(name, "stristr") == 0 || strcmp(name, "strrchr") == 0 ||
        strcmp(name, "strspn") == 0 || strcmp(name, "strcspn") == 0 ||
        strcmp(name, "ucwords") == 0 || strcmp(name, "str_repeat") == 0 ||
        strcmp(name, "str_increment") == 0 || strcmp(name, "str_decrement") == 0 ||
        strcmp(name, "bin2hex") == 0 ||
        strcmp(name, "hex2bin") == 0 || strcmp(name, "str_rot13") == 0 ||
        strcmp(name, "addslashes") == 0 || strcmp(name, "stripslashes") == 0 ||
        strcmp(name, "quotemeta") == 0 || strcmp(name, "strpbrk") == 0 ||
        strcmp(name, "chunk_split") == 0;
}

static inline int jinx_php_manual_name_is_pure_value_core(const char *name) {
    return strcmp(name, "base64_encode") == 0 || strcmp(name, "base64_decode") == 0 ||
        strcmp(name, "urlencode") == 0 || strcmp(name, "urldecode") == 0 ||
        strcmp(name, "rawurlencode") == 0 || strcmp(name, "rawurldecode") == 0 ||
        strcmp(name, "basename") == 0 || strcmp(name, "dirname") == 0 ||
        strcmp(name, "base_convert") == 0 || strcmp(name, "bindec") == 0 ||
        strcmp(name, "hexdec") == 0 || strcmp(name, "octdec") == 0 ||
        strcmp(name, "decbin") == 0 || strcmp(name, "dechex") == 0 ||
        strcmp(name, "decoct") == 0 || strcmp(name, "crc32") == 0 ||
        strcmp(name, "checkdate") == 0 || strcmp(name, "nl2br") == 0 ||
        strcmp(name, "number_format") == 0 || strcmp(name, "addcslashes") == 0 ||
        strcmp(name, "stripcslashes") == 0 || strcmp(name, "str_pad") == 0 ||
        strcmp(name, "str_replace") == 0 || strcmp(name, "str_ireplace") == 0 ||
        strcmp(name, "strtr") == 0 || strcmp(name, "levenshtein") == 0 ||
        strcmp(name, "htmlspecialchars") == 0 || strcmp(name, "htmlspecialchars_decode") == 0 ||
        strcmp(name, "sprintf") == 0 || strcmp(name, "printf") == 0 ||
        strcmp(name, "wordwrap") == 0 ||
        strcmp(name, "convert_uuencode") == 0 || strcmp(name, "convert_uudecode") == 0 ||
        strcmp(name, "soundex") == 0 || strcmp(name, "quoted_printable_encode") == 0 ||
        strcmp(name, "quoted_printable_decode") == 0 || strcmp(name, "similar_text") == 0 ||
        strcmp(name, "metaphone") == 0 || strcmp(name, "strcoll") == 0 ||
        strcmp(name, "substr_replace") == 0 ||
        strcmp(name, "utf8_encode") == 0 || strcmp(name, "utf8_decode") == 0 ||
        strcmp(name, "hebrev") == 0 ||
        strcmp(name, "image_type_to_mime_type") == 0 ||
        strcmp(name, "image_type_to_extension") == 0 ||
        strcmp(name, "version_compare") == 0 ||
        strcmp(name, "ip2long") == 0 || strcmp(name, "long2ip") == 0 ||
        strcmp(name, "inet_pton") == 0 || strcmp(name, "inet_ntop") == 0;
}

static inline int jinx_php_manual_name_is_mt19937_core(const char *name) {
    return strcmp(name, "mt_srand") == 0 || strcmp(name, "srand") == 0 ||
        strcmp(name, "mt_rand") == 0 || strcmp(name, "rand") == 0 ||
        strcmp(name, "mt_getrandmax") == 0 || strcmp(name, "getrandmax") == 0;
}

static inline int jinx_php_manual_name_is_calendar_core(const char *name) {
    return strcmp(name, "gregoriantojd") == 0 || strcmp(name, "juliantojd") == 0 ||
        strcmp(name, "jdtogregorian") == 0 || strcmp(name, "jdtojulian") == 0 ||
        strcmp(name, "frenchtojd") == 0 || strcmp(name, "jdtofrench") == 0 ||
        strcmp(name, "jewishtojd") == 0 || strcmp(name, "jdtojewish") == 0 ||
        strcmp(name, "jddayofweek") == 0 || strcmp(name, "jdmonthname") == 0 ||
        strcmp(name, "cal_to_jd") == 0 || strcmp(name, "cal_from_jd") == 0 ||
        strcmp(name, "cal_info") == 0 || strcmp(name, "cal_days_in_month") == 0 ||
        strcmp(name, "easter_days") == 0 || strcmp(name, "easter_date") == 0;
}

static inline int jinx_php_manual_name_is_zlib_pure_core(const char *name) {
    return strcmp(name, "gzcompress") == 0 || strcmp(name, "gzuncompress") == 0 ||
        strcmp(name, "gzdeflate") == 0 || strcmp(name, "gzinflate") == 0 ||
        strcmp(name, "gzencode") == 0 || strcmp(name, "gzdecode") == 0 ||
        strcmp(name, "zlib_encode") == 0 || strcmp(name, "zlib_decode") == 0;
}

static inline int jinx_php_manual_name_is_zend_container_value_core(const char *name) {
    return strcmp(name, "count_chars") == 0 || strcmp(name, "str_word_count") == 0 ||
        strcmp(name, "implode") == 0 || strcmp(name, "join") == 0 ||
        strcmp(name, "vsprintf") == 0 || strcmp(name, "vprintf") == 0 ||
        strcmp(name, "explode") == 0 ||
        strcmp(name, "str_split") == 0 || strcmp(name, "str_getcsv") == 0 ||
        strcmp(name, "strip_tags") == 0 || strcmp(name, "parse_url") == 0 ||
        strcmp(name, "http_build_query") == 0 || strcmp(name, "parse_str") == 0 ||
        strcmp(name, "pathinfo") == 0 || strcmp(name, "localeconv") == 0 ||
        strcmp(name, "range") == 0 ||
        strcmp(name, "array_fill") == 0;
}

static inline int jinx_php_manual_name_is_zend_array_native_core(const char *name) {
    return strcmp(name, "min") == 0 || strcmp(name, "max") == 0 ||
        strcmp(name, "count") == 0 || strcmp(name, "sizeof") == 0 ||
        strcmp(name, "current") == 0 || strcmp(name, "pos") == 0 ||
        strcmp(name, "key") == 0 || strcmp(name, "next") == 0 ||
        strcmp(name, "prev") == 0 || strcmp(name, "reset") == 0 ||
        strcmp(name, "end") == 0 ||
        strcmp(name, "array_key_exists") == 0 || strcmp(name, "key_exists") == 0 ||
        strcmp(name, "array_is_list") == 0 || strcmp(name, "array_values") == 0 ||
        strcmp(name, "array_keys") == 0 || strcmp(name, "array_key_first") == 0 ||
        strcmp(name, "array_key_last") == 0 || strcmp(name, "array_sum") == 0 ||
        strcmp(name, "array_product") == 0 || strcmp(name, "array_reverse") == 0 ||
        strcmp(name, "array_slice") == 0 || strcmp(name, "array_merge") == 0 ||
        strcmp(name, "array_merge_recursive") == 0 || strcmp(name, "array_replace") == 0 ||
        strcmp(name, "array_replace_recursive") == 0 || strcmp(name, "array_flip") == 0 ||
        strcmp(name, "array_change_key_case") == 0 || strcmp(name, "array_fill_keys") == 0 ||
        strcmp(name, "array_combine") == 0 || strcmp(name, "array_count_values") == 0 || strcmp(name, "array_column") == 0 ||
        strcmp(name, "array_chunk") == 0 || strcmp(name, "array_pad") == 0 ||
        strcmp(name, "array_unique") == 0 || strcmp(name, "array_filter") == 0 ||
        strcmp(name, "array_push") == 0 || strcmp(name, "array_pop") == 0 ||
        strcmp(name, "array_shift") == 0 || strcmp(name, "array_unshift") == 0 ||
        strcmp(name, "array_splice") == 0 || strcmp(name, "in_array") == 0 || strcmp(name, "array_search") == 0 ||
        strcmp(name, "array_diff") == 0 || strcmp(name, "array_diff_assoc") == 0 ||
        strcmp(name, "array_diff_key") == 0 || strcmp(name, "array_intersect") == 0 ||
        strcmp(name, "array_intersect_assoc") == 0 || strcmp(name, "array_intersect_key") == 0 ||
        strcmp(name, "sort") == 0 || strcmp(name, "rsort") == 0 ||
        strcmp(name, "asort") == 0 || strcmp(name, "arsort") == 0 ||
        strcmp(name, "ksort") == 0 || strcmp(name, "krsort") == 0 ||
        strcmp(name, "natsort") == 0 || strcmp(name, "natcasesort") == 0 ||
        strcmp(name, "usort") == 0 || strcmp(name, "uasort") == 0 ||
        strcmp(name, "uksort") == 0 || strcmp(name, "shuffle") == 0 ||
        strcmp(name, "array_multisort") == 0 ||
        strcmp(name, "array_map") == 0 || strcmp(name, "array_reduce") == 0 ||
        strcmp(name, "array_find") == 0 || strcmp(name, "array_find_key") == 0 ||
        strcmp(name, "array_any") == 0 || strcmp(name, "array_all") == 0 ||
        strcmp(name, "array_walk") == 0 || strcmp(name, "array_walk_recursive") == 0 ||
        strcmp(name, "array_rand") == 0 ||
        strcmp(name, "array_diff_uassoc") == 0 || strcmp(name, "array_diff_ukey") == 0 ||
        strcmp(name, "array_udiff") == 0 || strcmp(name, "array_udiff_assoc") == 0 ||
        strcmp(name, "array_udiff_uassoc") == 0 || strcmp(name, "array_intersect_uassoc") == 0 ||
        strcmp(name, "array_intersect_ukey") == 0 || strcmp(name, "array_uintersect") == 0 ||
        strcmp(name, "array_uintersect_assoc") == 0 || strcmp(name, "array_uintersect_uassoc") == 0;
}

static inline int jinx_php_manual_name_is_string_byte_compare(const char *name) {
    return strcmp(name, "strcmp") == 0 || strcmp(name, "strcasecmp") == 0 ||
        strcmp(name, "strncmp") == 0 || strcmp(name, "strncasecmp") == 0 ||
        strcmp(name, "strnatcmp") == 0 || strcmp(name, "strnatcasecmp") == 0 ||
        strcmp(name, "substr_compare") == 0 || strcmp(name, "substr_count") == 0;
}

static inline const JinxPhpManualHandlerSpec *jinx_php_manual_spec_by_pattern(const char *pattern) {
    unsigned long i;

    if (pattern == 0) return 0;

    for (i = 0; i < jinx_php_manual_handler_spec_count(); i++) {
        if (strcmp(jinx_php_manual_handler_specs[i].pattern, pattern) == 0) {
            return &jinx_php_manual_handler_specs[i];
        }
    }

    return 0;
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

    if (jinx_php_manual_name_is_scalar_core(name)) {
        return jinx_php_manual_spec_by_pattern("scalar-core");
    }

    if (jinx_php_manual_name_is_zend_container_value_core(name)) {
        return jinx_php_manual_spec_by_pattern("zend-container-value-core");
    }

    if (jinx_php_manual_name_is_crypto(name)) {
        return jinx_php_manual_spec_by_pattern("crypto-php-fallback");
    }

    if (jinx_php_manual_name_is_string_byte_transform(name)) {
        return jinx_php_manual_spec_by_pattern("string-byte-transform");
    }

    if (jinx_php_manual_name_is_string_byte_compare(name)) {
        return jinx_php_manual_spec_by_pattern("string-byte-compare");
    }

    if (jinx_php_manual_name_is_math_trig_log(name)) {
        return jinx_php_manual_spec_by_pattern("math-trig-log");
    }

    if (jinx_php_manual_name_is_math_core(name)) {
        return jinx_php_manual_spec_by_pattern("math-core");
    }

    if (jinx_php_manual_name_is_mt19937_core(name)) {
        return jinx_php_manual_spec_by_pattern("mt19937-core");
    }

    if (jinx_php_manual_name_is_calendar_core(name)) {
        return jinx_php_manual_spec_by_pattern("calendar-core");
    }

    if (jinx_php_manual_name_is_zlib_pure_core(name)) {
        return jinx_php_manual_spec_by_pattern("zlib-pure-core");
    }

    if (jinx_php_manual_name_is_pure_value_core(name)) {
        return jinx_php_manual_spec_by_pattern("pure-value-core");
    }

    if (jinx_php_manual_name_starts(name, "ctype_")) {
        return jinx_php_manual_spec_by_pattern("ctype_");
    }

    if (jinx_php_manual_name_is_zend_array_native_core(name)) {
        return jinx_php_manual_spec_by_pattern("zend-array-native-core");
    }

    if (jinx_php_manual_name_starts(name, "array_")) {
        return jinx_php_manual_spec_by_pattern("array_");
    }

    if (jinx_php_manual_name_has(name, "class") || jinx_php_manual_name_has(name, "Class") ||
        jinx_php_manual_name_has(name, "Reflection") || jinx_php_manual_name_has(name, "::")) {
        return jinx_php_manual_spec_by_pattern("class-object-reflection");
    }

    if (jinx_php_manual_name_has(name, "file") || jinx_php_manual_name_has(name, "stream") ||
        jinx_php_manual_name_has(name, "socket") || jinx_php_manual_name_has(name, "session") ||
        jinx_php_manual_name_has(name, "exec") || jinx_php_manual_name_has(name, "proc") ||
        jinx_php_manual_name_has(name, "curl") || jinx_php_manual_name_has(name, "pdo") ||
        jinx_php_manual_name_has(name, "mysqli") || jinx_php_manual_name_has(name, "mysql")) {
        return jinx_php_manual_spec_by_pattern("filesystem-stream-process-network-session-db");
    }

    return jinx_php_manual_spec_by_pattern("string-transform");
}

static inline int jinx_php_manual_is_exact(const char *name) {
    const JinxPhpManualHandlerSpec *spec = jinx_php_manual_lookup(name);
    return spec != 0 && spec->state == JINX_PHP_MANUAL_EXACT;
}

#endif /* JINX_PHP_MANUAL_MANIFEST_H */
