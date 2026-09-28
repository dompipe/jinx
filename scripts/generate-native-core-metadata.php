<?php
declare(strict_types=1);

$out = $argv[1] ?? dirname(__DIR__) . '/runtime/jinx_native_core_metadata.generated.h';

function cstr(string $s): string {
    $out = '"';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ord = ord($s[$i]);
        if ($s[$i] === '\\' || $s[$i] === '"') {
            $out .= '\\' . $s[$i];
        } elseif ($ord >= 32 && $ord <= 126) {
            $out .= $s[$i];
        } else {
            /* Fixed-width octal avoids C's variable-width \\xNN escape. */
            $out .= sprintf('\\%03o', $ord);
        }
    }
    return $out . '"';
}

function cint64(int $value): string {
    if ($value === PHP_INT_MIN) {
        return '(-9223372036854775807LL - 1LL)';
    }
    return (string)$value . 'LL';
}

$defaultTimezone = date_default_timezone_get();

$interfaces = get_declared_interfaces();
$traits = get_declared_traits();
$definedFunctions = get_defined_functions();
$internalFunctions = $definedFunctions['internal'] ?? [];
$extensions = get_loaded_extensions();
$enums = [];
foreach (get_declared_classes() as $candidate) {
    if (function_exists('enum_exists') && enum_exists($candidate, false)) {
        $enums[] = $candidate;
    }
}
foreach ([$interfaces, $traits, $internalFunctions, $extensions, $enums] as &$list) {
    sort($list, SORT_STRING);
}
unset($list);

$extensionFunctionRows = [];
foreach ($extensions as $extension) {
    $funcs = get_extension_funcs($extension);
    if (!is_array($funcs)) $funcs = [];
    sort($funcs, SORT_STRING);
    $extensionFunctionRows[$extension] = $funcs;
}

$classes = get_declared_classes();
sort($classes, SORT_STRING);
$rows = [];
$arrays = [];
foreach ($classes as $idx => $class) {
    $parents = array_values(class_parents($class, false) ?: []);
    $implements = array_values(class_implements($class, false) ?: []);
    $uses = array_values(class_uses($class, false) ?: []);
    foreach (['parents'=>$parents,'implements'=>$implements,'uses'=>$uses] as $kind=>$values) {
        $sym = 'jinx_meta_' . $idx . '_' . $kind;
        $arrays[] = 'static const char *const ' . $sym . '[] = {' .
            ($values ? implode(', ', array_map('cstr', $values)) . ', ' : '') . 'NULL };';
    }
    $rows[] = sprintf(
        '    { %s, jinx_meta_%d_parents, %d, jinx_meta_%d_implements, %d, jinx_meta_%d_uses, %d },',
        cstr($class), $idx, count($parents), $idx, count($implements), $idx, count($uses)
    );
}

$constants = [];
foreach (get_defined_constants(true) as $group => $items) {
    foreach ($items as $name => $value) {
        if (is_int($value)) {
            $constants[] = [strtoupper($name), $name, 1, cint64($value), '0.0', 'NULL'];
        } elseif (is_bool($value)) {
            $constants[] = [strtoupper($name), $name, 2, $value ? '1LL' : '0LL', '0.0', 'NULL'];
        } elseif (is_float($value) && is_finite($value)) {
            $constants[] = [strtoupper($name), $name, 5, '0', sprintf('%.17g', $value), 'NULL'];
        } elseif (is_string($value)) {
            $constants[] = [strtoupper($name), $name, 3, '0', '0.0', cstr($value)];
        }
    }
}
usort($constants, static fn(array $a,array $b): int => $a[0] <=> $b[0]);

$code = [];
$code[] = '#ifndef JINX_NATIVE_CORE_METADATA_GENERATED_H';
$code[] = '#define JINX_NATIVE_CORE_METADATA_GENERATED_H';
$code[] = '#include <stddef.h>';
$code[] = '#define JINX_NATIVE_PHP_DEFAULT_TIMEZONE ' . cstr($defaultTimezone);
$code[] = '';
$code[] = 'typedef struct JinxNativeClassMeta { const char *name; const char *const *parents; size_t parent_count; const char *const *implements; size_t implements_count; const char *const *uses; size_t uses_count; } JinxNativeClassMeta;';
$code[] = 'typedef struct JinxNativeConstantMeta { const char *name; unsigned type; long long i64; double f64; const char *str; } JinxNativeConstantMeta;';
$code[] = 'typedef struct JinxNativeExtensionMeta { const char *name; const char *const *functions; size_t function_count; } JinxNativeExtensionMeta;';
$code[] = '';
array_push($code, ...$arrays);
$code[] = '';
$emitStringArray = static function (string $symbol, array $values) use (&$code): void {
    $code[] = 'static const char *const ' . $symbol . '[] = {';
    foreach ($values as $value) {
        $code[] = '    ' . cstr((string)$value) . ',';
    }
    $code[] = '    NULL';
    $code[] = '};';
    $code[] = 'static const size_t ' . $symbol . '_count = ' . count($values) . 'u;';
    $code[] = '';
};

$emitStringArray('jinx_native_interface_names', $interfaces);
$emitStringArray('jinx_native_trait_names', $traits);
$emitStringArray('jinx_native_internal_function_names', $internalFunctions);
$emitStringArray('jinx_native_extension_names', $extensions);
$emitStringArray('jinx_native_enum_names', $enums);

$extensionSymbols = [];
foreach ($extensionFunctionRows as $extension => $funcs) {
    $symbol = 'jinx_native_extension_functions_' . preg_replace('/[^A-Za-z0-9_]+/', '_', strtolower($extension));
    $extensionSymbols[$extension] = $symbol;
    $emitStringArray($symbol, $funcs);
}
$code[] = 'static const JinxNativeExtensionMeta jinx_native_extension_metadata[] = {';
foreach ($extensions as $extension) {
    $symbol = $extensionSymbols[$extension];
    $code[] = '    { ' . cstr($extension) . ', ' . $symbol . ', ' . count($extensionFunctionRows[$extension]) . 'u },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_extension_metadata_count = sizeof(jinx_native_extension_metadata) / sizeof(jinx_native_extension_metadata[0]);';
$code[] = '';

$code[] = 'static const JinxNativeClassMeta jinx_native_class_metadata[] = {';
array_push($code, ...$rows);
$code[] = '};';
$code[] = 'static const size_t jinx_native_class_metadata_count = sizeof(jinx_native_class_metadata) / sizeof(jinx_native_class_metadata[0]);';
$code[] = '';
$code[] = 'static const JinxNativeConstantMeta jinx_native_constant_metadata[] = {';
foreach ($constants as $c) {
    $code[] = sprintf('    { %s, %d, %s, %s, %s },', cstr($c[1]), $c[2], $c[3], $c[4], $c[5]);
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_constant_metadata_count = sizeof(jinx_native_constant_metadata) / sizeof(jinx_native_constant_metadata[0]);';
$code[] = '#endif';

file_put_contents($out, implode(PHP_EOL, $code) . PHP_EOL);
printf(
    "PASS: generated native core metadata: %d classes, %d interfaces, %d traits, %d enums, %d functions, %d extensions, %d scalar constants -> %s\n",
    count($classes),
    count($interfaces),
    count($traits),
    count($enums),
    count($internalFunctions),
    count($extensions),
    count($constants),
    $out
);
