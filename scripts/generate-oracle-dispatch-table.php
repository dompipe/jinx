<?php

declare(strict_types=1);

if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Usage: php scripts/generate-oracle-dispatch-table.php oracle_asm_index.json output.c [limit]\n");
    exit(1);
}

$indexPath = $argv[1];
$outputPath = $argv[2];
$limit = isset($argv[3]) ? max(1, (int) $argv[3]) : null;

if (!is_file($indexPath)) {
    fwrite(STDERR, "Missing Oracle ASM index: {$indexPath}\n");
    exit(1);
}

$index = json_decode((string) file_get_contents($indexPath), true);

if (!is_array($index)) {
    fwrite(STDERR, "Invalid Oracle ASM index JSON\n");
    exit(1);
}

$baseDir = dirname($indexPath);
$rows = [];
$headers = [];
$symbols = [];
$signatures = [];
$manifestPath = $index['manifest']['source_manifest'] ?? '';
if (!is_string($manifestPath) || $manifestPath === '') {
    generation_fail('Oracle index must identify its source_manifest');
}
if (!is_file($manifestPath)) {
    $manifestPath = dirname(__DIR__) . '/' . $manifestPath;
}
if (!is_file($manifestPath)) {
    generation_fail('Missing source manifest: ' . $manifestPath);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['functions'] ?? [] as $function) {
    $name = $function['name'] ?? '';
    if (!is_string($name) || $name === '' || isset($signatures[strtolower($name)])) {
        generation_fail('Empty or duplicate callable in source manifest: ' . (string) $name);
    }
    $arity = $function['arity'] ?? null;
    if (!is_array($arity) || !is_int($arity['required'] ?? null) ||
        !is_int($arity['total'] ?? null) || !is_bool($arity['variadic'] ?? null) ||
        $arity['required'] < 0 || $arity['total'] < $arity['required']) {
        generation_fail('Invalid callable arity: ' . $name);
    }
    $signatures[strtolower($name)] = $function;
}
if ($signatures === []) generation_fail('Source manifest has no callables');

foreach (($index['files'] ?? []) as $file) {
    if (!is_array($file)) {
        generation_fail('Invalid Oracle index file entry');
    }

    $outputFile = $file['output_file'] ?? null;

    if (!is_string($outputFile) || $outputFile === '') {
        generation_fail('Missing output_file in Oracle index');
    }

    $headerPath = $baseDir . '/' . $outputFile;

    if (!is_file($headerPath)) {
        generation_fail('Missing Oracle wrapper header: ' . $headerPath);
    }

    if (isset($headers[$outputFile])) generation_fail('Duplicate Oracle wrapper header: ' . $outputFile);

    $headers[$outputFile] = true;

    $text = (string) file_get_contents($headerPath);

    preg_match_all(
        '/\/\*\s*Callable:\s*([^*]+?)\s*\*\/\s*static\s+inline\s+JinxValue\s+(jinx_ora_[A-Za-z0-9_]+)\s*\([^)]*\)\s*\{(.*?)\n\}/s',
        $text,
        $matches,
        PREG_SET_ORDER
    );

    if (count($matches) !== ($file['callables'] ?? null)) {
        generation_fail('Stale callable count in Oracle index: ' . $outputFile);
    }

    foreach ($matches as $match) {
        $name = trim($match[1]);
        $wrapper = trim($match[2]);

        $key = strtolower($name);
        if (isset($rows[$key]) || isset($symbols[$wrapper])) {
            generation_fail('Duplicate callable or wrapper symbol: ' . $name);
        }
        $signature = $signatures[$key] ?? null;
        if ($signature === null || $signature['name'] !== $name) {
            generation_fail('Wrapper callable missing or mismatched in source manifest: ' . $name);
        }
        preg_match_all('/\(void\)JINX_ORA_CALL_(METHOD_BUILTIN|BUILTIN)\(ctx, "([^"]+)", (\d+)\)/', $match[3], $calls, PREG_SET_ORDER);
        $expectedKind = ($signature['kind'] ?? '') === 'method' ? 'METHOD_BUILTIN' : 'BUILTIN';
        if (count($calls) !== 1 || $calls[0][1] !== $expectedKind || stripcslashes($calls[0][2]) !== $name ||
            (int) $calls[0][3] !== $signature['arity']['total']) {
            generation_fail('Wrapper call target/kind/arity mismatch: ' . $name);
        }
        $symbols[$wrapper] = true;
        $rows[$key] = [
            'name' => $name,
            'wrapper' => $wrapper,
            'arity' => $signature['arity'],
        ];

        if ($limit !== null && count($rows) >= $limit) {
            break 2;
        }
    }
}

if ($rows === []) {
    fwrite(STDERR, "No wrappers found in generated Oracle ASM headers\n");
    exit(1);
}
if ($limit === null && array_diff_key($signatures, $rows) !== []) {
    generation_fail('Source manifest callables missing Oracle wrappers: ' . implode(', ', array_keys(array_diff_key($signatures, $rows))));
}

/*
 * Build a compile-time open-addressing index for callable lookup.
 *
 * The old generated runtime walked every entry in oracle_dispatch_table until
 * it found a case-insensitive name match. With thousands of wrappers that made
 * call cost depend on where a function happened to sit in the generated table.
 * Keep the table itself stable, but generate a 50%-or-lower load-factor hash
 * index that maps a case-insensitive FNV-1a name hash to the table row.
 */
/*
 * Assign compact numeric callable IDs. IDs 0..127 have a canonical one-byte
 * encoding; all remaining current callables use a canonical two-byte encoding.
 * Put common scalar/math/string/array globals first, then fill any unused hot
 * slots with other global functions before appending the remaining inventory.
 */
$hotNames = [
    'abs', 'acos', 'acosh', 'asin', 'asinh', 'atan', 'atan2', 'atanh',
    'ceil', 'cos', 'cosh', 'exp', 'expm1', 'fdiv', 'floor', 'fmod',
    'hypot', 'intdiv', 'log', 'log10', 'log1p', 'pi', 'pow', 'fpow',
    'round', 'sin', 'sinh', 'sqrt', 'tan', 'tanh',
    'strlen', 'count', 'sizeof', 'is_null', 'is_bool', 'is_int',
    'is_integer', 'is_long', 'is_float', 'is_double', 'is_string',
    'is_array', 'is_object', 'is_resource', 'is_scalar', 'is_numeric',
    'boolval', 'intval', 'floatval', 'doubleval', 'strval',
    'strcmp', 'strcasecmp', 'strncmp', 'strncasecmp', 'str_contains',
    'str_starts_with', 'str_ends_with', 'strpos', 'stripos', 'strrpos',
    'strripos', 'substr', 'substr_count', 'substr_compare', 'strtolower',
    'strtoupper', 'lcfirst', 'ucfirst', 'ucwords', 'trim', 'ltrim', 'rtrim',
    'explode', 'implode', 'join', 'str_split', 'str_getcsv', 'str_replace',
    'str_ireplace', 'substr_replace', 'addslashes', 'stripslashes',
    'htmlspecialchars', 'htmlspecialchars_decode', 'htmlentities',
    'html_entity_decode', 'base64_encode', 'base64_decode',
    'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode',
    'json_encode', 'json_decode', 'json_validate', 'json_last_error',
    'json_last_error_msg', 'array_key_exists', 'in_array', 'array_search',
    'array_values', 'array_keys', 'array_sum', 'array_product', 'array_slice',
    'array_merge', 'array_merge_recursive', 'array_replace',
    'array_replace_recursive', 'array_reverse', 'array_flip', 'array_chunk',
    'array_column', 'array_unique', 'array_filter', 'array_map', 'array_reduce',
    'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice',
    'min', 'max', 'range', 'sort', 'rsort', 'asort', 'arsort', 'ksort',
    'krsort',
];

$orderedRows = [];
$selectedRows = [];
foreach ($hotNames as $hotName) {
    $key = strtolower($hotName);
    if (isset($rows[$key]) && !isset($selectedRows[$key])) {
        $orderedRows[] = $rows[$key];
        $selectedRows[$key] = true;
        if (count($orderedRows) >= 128) break;
    }
}
if (count($orderedRows) < 128) {
    foreach ($rows as $key => $row) {
        if (count($orderedRows) >= 128) break;
        if (isset($selectedRows[$key]) || str_contains((string)$row['name'], '::')) continue;
        $orderedRows[] = $row;
        $selectedRows[$key] = true;
    }
}
foreach ($rows as $key => $row) {
    if (isset($selectedRows[$key])) continue;
    $orderedRows[] = $row;
    $selectedRows[$key] = true;
}

if (count($orderedRows) > 32768) {
    generation_fail('Compact Oracle callable IDs support at most 32768 entries');
}

$idByName = [];
foreach ($orderedRows as $rowIndex => $row) {
    $idByName[strtolower((string)$row['name'])] = $rowIndex;
}

$hashSize = 1;
while ($hashSize < count($orderedRows) * 2) {
    $hashSize <<= 1;
}
$hashSlots = array_fill(0, $hashSize, -1);
foreach ($orderedRows as $rowIndex => $row) {
    $slot = oracle_dispatch_hash((string)$row['name']) & ($hashSize - 1);
    $probes = 0;
    while ($hashSlots[$slot] !== -1) {
        $slot = ($slot + 1) & ($hashSize - 1);
        if (++$probes >= $hashSize) {
            generation_fail('Oracle dispatch hash table overflow');
        }
    }
    $hashSlots[$slot] = $rowIndex;
}

$outDir = dirname($outputPath);
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$code = [];
$code[] = '/* Generated by scripts/generate-oracle-dispatch-table.php. Do not edit by hand. */';
$code[] = '#include "jinx_builtin_dispatch.h"';
$code[] = '#include "jinx_oracle_zend_array_builtins.h"';
$code[] = '';

foreach (array_keys($headers) as $header) {
    $code[] = '#include "../build/oracle-asm/' . c_escape($header) . '"';
}

$code[] = '';
$code[] = '#include <string.h>';
$code[] = '';
$code[] = 'static int jinx_oracle_name_is_zend_array_builtin(const char *name) {';
$code[] = '    return name != NULL && (';
$code[] = '        strcmp(name, "min") == 0 || strcmp(name, "max") == 0 ||';
$code[] = '        strcmp(name, "count") == 0 ||';
$code[] = '        strcmp(name, "sizeof") == 0 ||';
$code[] = '        strcmp(name, "current") == 0 ||';
$code[] = '        strcmp(name, "pos") == 0 ||';
$code[] = '        strcmp(name, "key") == 0 ||';
$code[] = '        strcmp(name, "next") == 0 ||';
$code[] = '        strcmp(name, "prev") == 0 ||';
$code[] = '        strcmp(name, "reset") == 0 ||';
$code[] = '        strcmp(name, "end") == 0 ||';
$code[] = '        strcmp(name, "array_is_list") == 0 ||';
$code[] = '        strcmp(name, "array_values") == 0 ||';
$code[] = '        strcmp(name, "array_keys") == 0 ||';
$code[] = '        strcmp(name, "array_key_first") == 0 ||';
$code[] = '        strcmp(name, "array_key_last") == 0 ||';
$code[] = '        strcmp(name, "array_sum") == 0 ||';
$code[] = '        strcmp(name, "array_product") == 0 ||';
$code[] = '        strcmp(name, "array_reverse") == 0 ||';
$code[] = '        strcmp(name, "array_slice") == 0 ||';
$code[] = '        strcmp(name, "array_merge") == 0 ||
        strcmp(name, "array_merge_recursive") == 0 ||';
$code[] = '        strcmp(name, "array_replace") == 0 ||
        strcmp(name, "array_replace_recursive") == 0 ||';
$code[] = '        strcmp(name, "array_flip") == 0 ||';
$code[] = '        strcmp(name, "array_change_key_case") == 0 ||';
$code[] = '        strcmp(name, "array_fill_keys") == 0 ||';
$code[] = '        strcmp(name, "array_combine") == 0 ||';
$code[] = '        strcmp(name, "array_count_values") == 0 ||';
$code[] = '        strcmp(name, "array_column") == 0 ||';
$code[] = '        strcmp(name, "array_chunk") == 0 ||';
$code[] = '        strcmp(name, "array_pad") == 0 ||';
$code[] = '        strcmp(name, "array_unique") == 0 ||';
$code[] = '        strcmp(name, "array_filter") == 0 ||';
$code[] = '        strcmp(name, "array_push") == 0 ||';
$code[] = '        strcmp(name, "array_pop") == 0 ||';
$code[] = '        strcmp(name, "array_shift") == 0 ||';
$code[] = '        strcmp(name, "array_unshift") == 0 ||
        strcmp(name, "array_splice") == 0 ||';
$code[] = '        strcmp(name, "array_diff") == 0 ||';
$code[] = '        strcmp(name, "array_diff_assoc") == 0 ||';
$code[] = '        strcmp(name, "array_diff_key") == 0 ||';
$code[] = '        strcmp(name, "array_intersect") == 0 ||';
$code[] = '        strcmp(name, "array_intersect_assoc") == 0 ||';
$code[] = '        strcmp(name, "array_intersect_key") == 0';
$code[] = '    );';
$code[] = '}';
$code[] = '';
$code[] = 'static int jinx_oracle_name_is_zend_container_builtin(const char *name) {';
$code[] = '    return name != NULL && (';
$code[] = '        strcmp(name, "localeconv") == 0 ||';
$code[] = '        strcmp(name, "array_key_exists") == 0 ||';
$code[] = '        strcmp(name, "key_exists") == 0 ||';
$code[] = '        strcmp(name, "cal_info") == 0 ||';
$code[] = '        strcmp(name, "cal_from_jd") == 0 ||
        strcmp(name, "pathinfo") == 0 ||
        strcmp(name, "parse_str") == 0 ||
        strcmp(name, "http_build_query") == 0 ||
        strcmp(name, "parse_url") == 0 ||
        strcmp(name, "count_chars") == 0 ||
        strcmp(name, "str_getcsv") == 0 ||
        strcmp(name, "strip_tags") == 0 ||';
$code[] = '        strcmp(name, "in_array") == 0 ||';
$code[] = '        strcmp(name, "array_search") == 0 ||';
$code[] = '        strcmp(name, "str_word_count") == 0 ||';
$code[] = '        strcmp(name, "explode") == 0 ||';
$code[] = '        strcmp(name, "str_split") == 0 ||';
$code[] = '        strcmp(name, "vsprintf") == 0 ||';
$code[] = '        strcmp(name, "vprintf") == 0 ||';
$code[] = '        strcmp(name, "implode") == 0 ||';
$code[] = '        strcmp(name, "join") == 0 ||';
$code[] = '        strcmp(name, "range") == 0 ||';
$code[] = '        strcmp(name, "array_fill") == 0 ||';
$code[] = '        strcmp(name, "sort") == 0 ||';
$code[] = '        strcmp(name, "rsort") == 0 ||';
$code[] = '        strcmp(name, "asort") == 0 ||';
$code[] = '        strcmp(name, "arsort") == 0 ||';
$code[] = '        strcmp(name, "ksort") == 0 ||';
$code[] = '        strcmp(name, "krsort") == 0 ||';
$code[] = '        strcmp(name, "natsort") == 0 ||';
$code[] = '        strcmp(name, "natcasesort") == 0 ||';
$code[] = '        strcmp(name, "usort") == 0 ||';
$code[] = '        strcmp(name, "uasort") == 0 ||';
$code[] = '        strcmp(name, "uksort") == 0 ||';
$code[] = '        strcmp(name, "shuffle") == 0 ||';
$code[] = '        strcmp(name, "array_multisort") == 0 ||';
$code[] = '        strcmp(name, "array_map") == 0 ||';
$code[] = '        strcmp(name, "array_reduce") == 0 ||';
$code[] = '        strcmp(name, "array_filter") == 0 ||';
$code[] = '        strcmp(name, "array_find") == 0 ||';
$code[] = '        strcmp(name, "array_find_key") == 0 ||';
$code[] = '        strcmp(name, "array_any") == 0 ||';
$code[] = '        strcmp(name, "array_all") == 0 ||';
$code[] = '        strcmp(name, "array_diff_uassoc") == 0 ||';
$code[] = '        strcmp(name, "array_diff_ukey") == 0 ||';
$code[] = '        strcmp(name, "array_udiff") == 0 ||';
$code[] = '        strcmp(name, "array_udiff_assoc") == 0 ||';
$code[] = '        strcmp(name, "array_udiff_uassoc") == 0 ||';
$code[] = '        strcmp(name, "array_intersect_uassoc") == 0 ||';
$code[] = '        strcmp(name, "array_intersect_ukey") == 0 ||';
$code[] = '        strcmp(name, "array_uintersect") == 0 ||';
$code[] = '        strcmp(name, "array_uintersect_assoc") == 0 ||';
$code[] = '        strcmp(name, "array_uintersect_uassoc") == 0 ||';
$code[] = '        strcmp(name, "array_walk") == 0 ||';
$code[] = '        strcmp(name, "array_walk_recursive") == 0 ||';
$code[] = '        strcmp(name, "array_rand") == 0';
$code[] = '    );';
$code[] = '}';
$code[] = '';
$code[] = 'static int jinx_oracle_zend_null_result_is_valid(const char *name, JinxValue *args, size_t argc) {';
$code[] = '    if (name == NULL) return 0;';
$code[] = '    if (strcmp(name, "parse_str") == 0) return argc >= 2u;';
$code[] = '    if (strcmp(name, "parse_url") == 0) {';
$code[] = '        if (argc < 2u || args == NULL) return 0;';
$code[] = '        int64_t component = jinx_oracle_intish(args[1]);';
$code[] = '        return component >= 0 && component <= 7;';
$code[] = '    }';
$code[] = '    return strcmp(name, "array_key_first") == 0 ||';
$code[] = '        strcmp(name, "array_key_last") == 0 ||';
$code[] = '        strcmp(name, "array_pop") == 0 ||';
$code[] = '        strcmp(name, "array_shift") == 0 ||';
$code[] = '        strcmp(name, "array_find") == 0 ||';
$code[] = '        strcmp(name, "array_find_key") == 0 ||';
$code[] = '        strcmp(name, "array_reduce") == 0 ||';
$code[] = '        strcmp(name, "current") == 0 ||';
$code[] = '        strcmp(name, "pos") == 0 ||';
$code[] = '        strcmp(name, "key") == 0 ||';
$code[] = '        strcmp(name, "next") == 0 ||';
$code[] = '        strcmp(name, "prev") == 0 ||';
$code[] = '        strcmp(name, "reset") == 0 ||';
$code[] = '        strcmp(name, "end") == 0 ||';
$code[] = '        strcmp(name, "min") == 0 ||';
$code[] = '        strcmp(name, "max") == 0;';
$code[] = '}';
$code[] = '';
$code[] = 'static int jinx_oracle_zend_dispatch_result_ok(const char *name, JinxValue *args, size_t argc, JinxValue result) {';
$code[] = '    return result.type != 0u || jinx_oracle_zend_null_result_is_valid(name, args, argc);';
$code[] = '}';
$code[] = '';
$code[] = 'static const JinxOracleDispatchEntry oracle_dispatch_table[] = {';

foreach ($orderedRows as $row) {
    $code[] = sprintf(
        '    { "%s", %s, %du, %du, %d },',
        c_escape($row['name']),
        $row['wrapper'],
        $row['arity']['required'],
        $row['arity']['total'],
        $row['arity']['variadic'] ? 1 : 0
    );
}

$code[] = '    { NULL, NULL, 0u, 0u, 0 }';
$code[] = '};';
$code[] = '';
$code[] = 'enum { JINX_ORACLE_DISPATCH_COUNT = ' . count($orderedRows) . ', JINX_ORACLE_DISPATCH_HASH_SIZE = ' . $hashSize . ' };';
$code[] = 'static const int oracle_dispatch_hash_slots[JINX_ORACLE_DISPATCH_HASH_SIZE] = {';
foreach (array_chunk($hashSlots, 16) as $chunk) {
    $code[] = '    ' . implode(', ', $chunk) . ',';
}
$code[] = '};';
$code[] = '';
$code[] = 'static uint32_t jinx_oracle_callable_hash(const char *name) {';
$code[] = '    uint32_t hash = 2166136261u;';
$code[] = '    while (*name) {';
$code[] = '        unsigned char c = (unsigned char)*name++;';
$code[] = '        if (c >= 65u && c <= 90u) c += 32u;';
$code[] = '        hash ^= (uint32_t)c;';
$code[] = '        hash *= 16777619u;';
$code[] = '    }';
$code[] = '    return hash;';
$code[] = '}';
$code[] = '';
$code[] = 'static int jinx_oracle_callable_name_equal(const char *left, const char *right) {';
$code[] = '    while (*left && *right) {';
$code[] = '        unsigned char a = (unsigned char)*left++, b = (unsigned char)*right++;';
$code[] = '        if (a >= 65u && a <= 90u) a += 32u;';
$code[] = '        if (b >= 65u && b <= 90u) b += 32u;';
$code[] = '        if (a != b) return 0;';
$code[] = '    }';
$code[] = '    return *left == *right;';
$code[] = '}';
$code[] = '';
/*
 * Direct one-byte hot-path execution. These cases preserve the same scalar
 * semantics as jinx_oracle_asm_call_builtin(), but avoid re-dispatching the
 * already-resolved callable through its string-name chain.
 */
$code[] = 'static int jinx_call_hot_builtin_id_checked(';
$code[] = '    JinxBuiltinId id, JinxValue *args, size_t argc, JinxValue *result, int *ok';
$code[] = ') {';
$code[] = '    JinxValue zero = jinx_oracle_zero_value();';
$code[] = '    JinxValue arg0 = (args != NULL && argc > 0u) ? args[0] : zero;';
$code[] = '    JinxValue arg1 = (args != NULL && argc > 1u) ? args[1] : zero;';
$code[] = '    JinxValue arg2 = (args != NULL && argc > 2u) ? args[2] : zero;';
$code[] = '    JinxValue value = zero;';
$code[] = '    if (result == NULL) return 0;';
$code[] = '    switch (id) {';

$directUnary = [
    'acos' => 'acos',
    'acosh' => 'acosh',
    'asin' => 'asin',
    'asinh' => 'asinh',
    'atan' => 'atan',
    'atanh' => 'atanh',
    'ceil' => 'ceil',
    'cos' => 'cos',
    'cosh' => 'cosh',
    'exp' => 'exp',
    'expm1' => 'expm1',
    'floor' => 'floor',
    'log10' => 'log10',
    'log1p' => 'log1p',
    'sin' => 'sin',
    'sinh' => 'sinh',
    'sqrt' => 'sqrt',
    'tan' => 'tan',
    'tanh' => 'tanh',
];
foreach ($directUnary as $name => $cfn) {
    if (!isset($idByName[$name]) || $idByName[$name] >= 128) continue;
    $code[] = '        case ' . $idByName[$name] . 'u:';
    $code[] = '            value = jinx_oracle_float_value(' . $cfn . '(jinx_oracle_floatish(arg0)));';
    $code[] = '            break;';
}

if (isset($idByName['abs']) && $idByName['abs'] < 128) {
    $code[] = '        case ' . $idByName['abs'] . 'u:';
    $code[] = '            if (arg0.type == 5u) {';
    $code[] = '                value = jinx_oracle_float_value(fabs(arg0.as.f64));';
    $code[] = '            } else {';
    $code[] = '                int64_t v = jinx_oracle_intish(arg0);';
    $code[] = '                value = v == INT64_MIN ? jinx_oracle_float_value(-(double)INT64_MIN)';
    $code[] = '                    : jinx_oracle_int_value(v < 0 ? -v : v);';
    $code[] = '            }';
    $code[] = '            break;';
}

$directBinaryFloat = [
    'atan2' => 'atan2',
    'fmod' => 'fmod',
    'hypot' => 'hypot',
];
foreach ($directBinaryFloat as $name => $cfn) {
    if (!isset($idByName[$name]) || $idByName[$name] >= 128) continue;
    $code[] = '        case ' . $idByName[$name] . 'u:';
    $code[] = '            value = jinx_oracle_float_value(' . $cfn . '(jinx_oracle_floatish(arg0), jinx_oracle_floatish(arg1)));';
    $code[] = '            break;';
}

if (isset($idByName['fdiv']) && $idByName['fdiv'] < 128) {
    $code[] = '        case ' . $idByName['fdiv'] . 'u:';
    $code[] = '            value = jinx_oracle_float_value(jinx_oracle_floatish(arg0) / jinx_oracle_floatish(arg1));';
    $code[] = '            break;';
}

if (isset($idByName['intdiv']) && $idByName['intdiv'] < 128) {
    $code[] = '        case ' . $idByName['intdiv'] . 'u: {';
    $code[] = '            int64_t dividend = jinx_oracle_intish(arg0);';
    $code[] = '            int64_t divisor = jinx_oracle_intish(arg1);';
    $code[] = '            if (divisor == 0 || (dividend == INT64_MIN && divisor == -1)) {';
    $code[] = '                *result = zero;';
    $code[] = '                if (ok != NULL) *ok = 0;';
    $code[] = '                return 1;';
    $code[] = '            }';
    $code[] = '            value = jinx_oracle_int_value(dividend / divisor);';
    $code[] = '            break;';
    $code[] = '        }';
}

if (isset($idByName['pi']) && $idByName['pi'] < 128) {
    $code[] = '        case ' . $idByName['pi'] . 'u:';
    $code[] = '            value = jinx_oracle_float_value(jinx_oracle_pi());';
    $code[] = '            break;';
}

foreach (['pow' => 0, 'fpow' => 1] as $name => $forceFloat) {
    if (!isset($idByName[$name]) || $idByName[$name] >= 128) continue;
    $code[] = '        case ' . $idByName[$name] . 'u:';
    $code[] = '            value = jinx_oracle_pow_value(arg0, arg1, ' . $forceFloat . ');';
    $code[] = '            break;';
}

if (isset($idByName['round']) && $idByName['round'] < 128) {
    $code[] = '        case ' . $idByName['round'] . 'u: {';
    $code[] = '            int round_ok = 0;';
    $code[] = '            value = jinx_oracle_round_value(arg0, arg1, arg2, argc, &round_ok);';
    $code[] = '            if (!round_ok) {';
    $code[] = '                *result = zero;';
    $code[] = '                if (ok != NULL) *ok = 0;';
    $code[] = '                return 1;';
    $code[] = '            }';
    $code[] = '            break;';
    $code[] = '        }';
}

if (isset($idByName['log']) && $idByName['log'] < 128) {
    $code[] = '        case ' . $idByName['log'] . 'u: {';
    $code[] = '            double x = jinx_oracle_floatish(arg0);';
    $code[] = '            double base = argc >= 2u ? jinx_oracle_floatish(arg1) : 0.0;';
    $code[] = '            if (argc >= 2u && base <= 0.0) {';
    $code[] = '                *result = zero;';
    $code[] = '                if (ok != NULL) *ok = 0;';
    $code[] = '                return 1;';
    $code[] = '            }';
    $code[] = '            value = jinx_oracle_float_value(argc < 2u ? log(x)';
    $code[] = '                : (base == 1.0 ? NAN : (base == 2.0 ? log2(x)';
    $code[] = '                : (base == 10.0 ? log10(x) : log(x) / log(base)))));';
    $code[] = '            break;';
    $code[] = '        }';
}

$code[] = '        default:';
$code[] = '            return 0;';
$code[] = '    }';
$code[] = '    *result = value;';
$code[] = '    if (ok != NULL) *ok = 1;';
$code[] = '    return 1;';
$code[] = '}';
$code[] = '';

$code[] = 'static const JinxOracleDispatchEntry *jinx_lookup_oracle_entry(const char *name) {';
$code[] = '    if (name == NULL) return NULL;';
$code[] = '    size_t slot = (size_t)(jinx_oracle_callable_hash(name) & (JINX_ORACLE_DISPATCH_HASH_SIZE - 1u));';
$code[] = '    for (size_t probe = 0u; probe < JINX_ORACLE_DISPATCH_HASH_SIZE; probe++) {';
$code[] = '        int index = oracle_dispatch_hash_slots[slot];';
$code[] = '        if (index < 0) return NULL;';
$code[] = '        if (jinx_oracle_callable_name_equal(oracle_dispatch_table[index].name, name)) {';
$code[] = '            return &oracle_dispatch_table[index];';
$code[] = '        }';
$code[] = '        slot = (slot + 1u) & (JINX_ORACLE_DISPATCH_HASH_SIZE - 1u);';
$code[] = '    }';
$code[] = '    return NULL;';
$code[] = '}';
$code[] = '';
$code[] = 'JinxOracleWrapper jinx_lookup_oracle_wrapper(const char *name) {';
$code[] = '    const JinxOracleDispatchEntry *entry = jinx_lookup_oracle_entry(name);';
$code[] = '    return entry != NULL ? entry->wrapper : NULL;';
$code[] = '}';
$code[] = '';
$code[] = 'int jinx_lookup_oracle_arity(const char *name, uint32_t *required_args, uint32_t *total_args, int *variadic) {';
$code[] = '    const JinxOracleDispatchEntry *entry = jinx_lookup_oracle_entry(name);';
$code[] = '    if (entry == NULL) return 0;';
$code[] = '    if (required_args != NULL) *required_args = entry->required_args;';
$code[] = '    if (total_args != NULL) *total_args = entry->total_args;';
$code[] = '    if (variadic != NULL) *variadic = entry->variadic;';
$code[] = '    return 1;';
$code[] = '}';
$code[] = '';
$code[] = 'JinxBuiltinId jinx_resolve_builtin_id(const char *name) {';
$code[] = '    const JinxOracleDispatchEntry *entry = jinx_lookup_oracle_entry(name);';
$code[] = '    if (entry == NULL) return JINX_BUILTIN_ID_INVALID;';
$code[] = '    return (JinxBuiltinId)(entry - oracle_dispatch_table);';
$code[] = '}';
$code[] = '';
$code[] = 'const char *jinx_builtin_name_from_id(JinxBuiltinId id) {';
$code[] = '    return id < JINX_ORACLE_DISPATCH_COUNT ? oracle_dispatch_table[id].name : NULL;';
$code[] = '}';
$code[] = '';
$code[] = 'size_t jinx_encode_builtin_id(JinxBuiltinId id, uint8_t out[2]) {';
$code[] = '    if (out == NULL || id >= JINX_ORACLE_DISPATCH_COUNT) return 0u;';
$code[] = '    if (id < JINX_BUILTIN_HOT_ID_LIMIT) {';
$code[] = '        out[0] = (uint8_t)id;';
$code[] = '        return 1u;';
$code[] = '    }';
$code[] = '    out[0] = (uint8_t)(0x80u | ((id >> 8u) & 0x7fu));';
$code[] = '    out[1] = (uint8_t)(id & 0xffu);';
$code[] = '    return 2u;';
$code[] = '}';
$code[] = '';
$code[] = 'int jinx_decode_builtin_id(const uint8_t *bytes, size_t length, JinxBuiltinId *id, size_t *consumed) {';
$code[] = '    JinxBuiltinId decoded;';
$code[] = '    size_t used;';
$code[] = '    if (bytes == NULL || length == 0u || id == NULL) return 0;';
$code[] = '    if ((bytes[0] & 0x80u) == 0u) {';
$code[] = '        decoded = (JinxBuiltinId)bytes[0];';
$code[] = '        used = 1u;';
$code[] = '    } else {';
$code[] = '        if (length < 2u) return 0;';
$code[] = '        decoded = (JinxBuiltinId)((((uint16_t)bytes[0] & 0x7fu) << 8u) | (uint16_t)bytes[1]);';
$code[] = '        if (decoded < JINX_BUILTIN_HOT_ID_LIMIT) return 0;';
$code[] = '        used = 2u;';
$code[] = '    }';
$code[] = '    if (decoded >= JINX_ORACLE_DISPATCH_COUNT) return 0;';
$code[] = '    *id = decoded;';
$code[] = '    if (consumed != NULL) *consumed = used;';
$code[] = '    return 1;';
$code[] = '}';
$code[] = '';
$code[] = 'static JinxValue jinx_call_builtin_entry_checked(';
$code[] = '    const JinxOracleDispatchEntry *entry,';
$code[] = '    JinxValue *args,';
$code[] = '    size_t argc,';
$code[] = '    int *ok';
$code[] = ') {';
$code[] = '    if (ok != NULL) *ok = 0;';
$code[] = '';
$code[] = '    if (entry == NULL || argc > 64u || (argc != 0u && args == NULL) ||';
$code[] = '        argc < entry->required_args || (!entry->variadic && argc > entry->total_args)) {';
$code[] = '        return jinx_value_null();';
$code[] = '    }';
$code[] = '    {';
$code[] = '        JinxBuiltinId id = (JinxBuiltinId)(entry - oracle_dispatch_table);';
$code[] = '        JinxValue hot_result = jinx_value_null();';
$code[] = '        if (id < JINX_BUILTIN_HOT_ID_LIMIT &&';
$code[] = '            jinx_call_hot_builtin_id_checked(id, args, argc, &hot_result, ok)) {';
$code[] = '            return hot_result;';
$code[] = '        }';
$code[] = '    }';
$code[] = '    const char *name = entry->name;';
$code[] = '';
$code[] = '    if (argc == 0u && (strcmp(name, "array_merge") == 0 || strcmp(name, "array_merge_recursive") == 0)) {';
$code[] = '        return jinx_oracle_zend_array_dispatch_builtin_checked(name, args, argc, ok);';
$code[] = '    }';
$code[] = '';
$code[] = '    if (name != NULL && strcmp(name, "json_encode") == 0 && args != NULL && argc >= 1 &&';
$code[] = '        (argc < 2 || ((args[1].type == 1u || args[1].type == 2u) && args[1].as.i64 == 0))) {';
$code[] = '        return jinx_oracle_zend_array_dispatch_builtin_checked(name, args, argc, ok);';
$code[] = '    }';
$code[] = '';
$code[] = '    if (name != NULL && strcmp(name, "json_decode") == 0 && args != NULL && argc >= 1 &&';
$code[] = '        (argc < 4 || ((args[3].type == 1u || args[3].type == 2u) &&';
$code[] = '         (args[3].as.i64 & ~(JINX_JSON_OBJECT_AS_ARRAY | JINX_JSON_BIGINT_AS_STRING | JINX_JSON_INVALID_UTF8_IGNORE | JINX_JSON_INVALID_UTF8_SUBSTITUTE)) == 0))) {';
$code[] = '        return jinx_oracle_zend_array_dispatch_builtin_checked(name, args, argc, ok);';
$code[] = '    }';
$code[] = '';
$code[] = '    if (jinx_oracle_name_is_zend_container_builtin(name) && (args != NULL || argc == 0)) {';
$code[] = '        return jinx_oracle_zend_array_dispatch_builtin_checked(name, args, argc, ok);';
$code[] = '    }';
$code[] = '';
$code[] = '    if (argc >= 1 && args != NULL && jinx_oracle_name_is_zend_array_builtin(name) &&';
$code[] = '        jinx_oracle_value_is_zend_array(args[0])) {';
$code[] = '        return jinx_oracle_zend_array_dispatch_builtin_checked(name, args, argc, ok);';
$code[] = '    }';
$code[] = '';
$code[] = '    JinxOracleWrapper wrapper = entry->wrapper;';
$code[] = '';
$code[] = '    if (wrapper == NULL) {';
$code[] = '        return jinx_value_null();';
$code[] = '    }';
$code[] = '';
$code[] = '    JinxOracleAsmContext ctx;';
$code[] = '    jinx_ora_context_init(&ctx, args, (uint32_t) argc);';
$code[] = '';
$code[] = '    JinxValue result = wrapper(&ctx);';
$code[] = '';
$code[] = '    if (ctx.fault != NULL) {';
$code[] = '        return jinx_value_null();';
$code[] = '    }';
$code[] = '';
$code[] = '    if (ok != NULL) *ok = 1;';
$code[] = '    return result;';
$code[] = '}';
$code[] = '';
$code[] = 'JinxValue jinx_call_builtin_id_checked(';
$code[] = '    JinxBuiltinId id, JinxValue *args, size_t argc, int *ok';
$code[] = ') {';
$code[] = '    const JinxOracleDispatchEntry *entry = id < JINX_ORACLE_DISPATCH_COUNT';
$code[] = '        ? &oracle_dispatch_table[id] : NULL;';
$code[] = '    return jinx_call_builtin_entry_checked(entry, args, argc, ok);';
$code[] = '}';
$code[] = '';
$code[] = 'JinxValue jinx_call_builtin_through_oracle_checked(';
$code[] = '    const char *name, JinxValue *args, size_t argc, int *ok';
$code[] = ') {';
$code[] = '    return jinx_call_builtin_entry_checked(jinx_lookup_oracle_entry(name), args, argc, ok);';
$code[] = '}';
$code[] = '';
$code[] = 'JinxValue jinx_call_builtin_id(';
$code[] = '    JinxBuiltinId id, JinxValue *args, size_t argc';
$code[] = ') {';
$code[] = '    int ok = 0;';
$code[] = '    JinxValue result = jinx_call_builtin_id_checked(id, args, argc, &ok);';
$code[] = '    return ok ? result : jinx_value_null();';
$code[] = '}';
$code[] = '';
$code[] = 'JinxValue jinx_call_builtin_through_oracle(';
$code[] = '    const char *name,';
$code[] = '    JinxValue *args,';
$code[] = '    size_t argc';
$code[] = ') {';
$code[] = '    int ok = 0;';
$code[] = '    JinxValue result = jinx_call_builtin_through_oracle_checked(name, args, argc, &ok);';
$code[] = '    return ok ? result : jinx_value_null();';
$code[] = '}';
$code[] = '';

file_put_contents($outputPath, implode(PHP_EOL, $code));

printf(
    "PASS: generated Oracle dispatch table with %d wrappers at %s\n",
    count($rows),
    $outputPath
);

function oracle_dispatch_hash(string $name): int
{
    $hash = 2166136261;
    $length = strlen($name);
    for ($i = 0; $i < $length; $i++) {
        $byte = ord($name[$i]);
        if ($byte >= 65 && $byte <= 90) {
            $byte += 32;
        }
        $hash ^= $byte;
        $hash = ($hash * 16777619) & 0xffffffff;
    }
    return $hash;
}

function c_escape(string $value): string
{
    return addcslashes($value, "\\\"\n\r\t");
}

function generation_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
