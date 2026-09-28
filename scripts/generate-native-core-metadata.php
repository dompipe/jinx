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

$cfgRows = [];
foreach (array_keys(ini_get_all(null, false) ?: []) as $cfgName) {
    $cfgValue = get_cfg_var((string)$cfgName);
    if (is_string($cfgValue)) {
        $cfgRows[] = [(string)$cfgName, $cfgValue];
    }
}
usort($cfgRows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

$htmlTranslationRows = [];
foreach (get_html_translation_table() as $from => $to) {
    $htmlTranslationRows[] = [(string)$from, (string)$to];
}

$filterRows = [];
if (function_exists('filter_list') && function_exists('filter_id')) {
    foreach (filter_list() as $filterName) {
        $filterId = filter_id($filterName);
        if (is_int($filterId)) {
            $filterRows[] = [$filterName, $filterId];
        }
    }
    usort($filterRows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
}
$includePath = get_include_path();

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
    $methods = array_values(get_class_methods($class) ?: []);
    $classVars = get_class_vars($class) ?: [];
    foreach (['parents'=>$parents,'implements'=>$implements,'uses'=>$uses,'methods'=>$methods] as $kind=>$values) {
        $sym = 'jinx_meta_' . $idx . '_' . $kind;
        $arrays[] = 'static const char *const ' . $sym . '[] = {' .
            ($values ? implode(', ', array_map('cstr', $values)) . ', ' : '') . 'NULL };';
    }
    $rows[] = sprintf(
        '    { %s, jinx_meta_%d_parents, %d, jinx_meta_%d_implements, %d, jinx_meta_%d_uses, %d, jinx_meta_%d_methods, %d },',
        cstr($class),
        $idx, count($parents),
        $idx, count($implements),
        $idx, count($uses),
        $idx, count($methods)
    );
}

$classVarMetaRows = [];
foreach ($classes as $class) {
    $vars = get_class_vars($class) ?: [];
    $complete = true;
    $items = [];
    foreach ($vars as $name => $value) {
        if (is_int($value)) {
            $items[] = [$name, 1, cint64($value), '0.0', 'NULL'];
        } elseif (is_bool($value)) {
            $items[] = [$name, 2, $value ? '1LL' : '0LL', '0.0', 'NULL'];
        } elseif (is_float($value) && is_finite($value)) {
            $items[] = [$name, 5, '0LL', sprintf('%.17g', $value), 'NULL'];
        } elseif (is_string($value)) {
            $items[] = [$name, 3, '0LL', '0.0', cstr($value)];
        } elseif ($value === null) {
            $items[] = [$name, 0, '0LL', '0.0', 'NULL'];
        } else {
            $complete = false;
        }
    }
    $classVarMetaRows[] = [$class, $items, $complete];
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
$code[] = '#define JINX_NATIVE_PHP_INCLUDE_PATH ' . cstr($includePath);
$code[] = '';
$code[] = 'typedef struct JinxNativeClassMeta { const char *name; const char *const *parents; size_t parent_count; const char *const *implements; size_t implements_count; const char *const *uses; size_t uses_count; const char *const *methods; size_t method_count; } JinxNativeClassMeta;';
$code[] = 'typedef struct JinxNativeConstantMeta { const char *name; unsigned type; long long i64; double f64; const char *str; } JinxNativeConstantMeta;';
$code[] = 'typedef struct JinxNativeExtensionMeta { const char *name; const char *const *functions; size_t function_count; } JinxNativeExtensionMeta;';
$code[] = 'typedef struct JinxNativeFilterMeta { const char *name; int id; } JinxNativeFilterMeta;';
$code[] = 'typedef struct JinxNativeStringPair { const char *name; const char *value; } JinxNativeStringPair;';
$code[] = 'typedef struct JinxNativeClassVarsMeta { const char *class_name; const JinxNativeConstantMeta *vars; size_t var_count; int complete; } JinxNativeClassVarsMeta;';
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
$code[] = 'static const JinxNativeFilterMeta jinx_native_filter_metadata[] = {';
foreach ($filterRows as [$filterName, $filterId]) {
    $code[] = '    { ' . cstr($filterName) . ', ' . (int)$filterId . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_filter_metadata_count = sizeof(jinx_native_filter_metadata) / sizeof(jinx_native_filter_metadata[0]);';
$code[] = '';

$code[] = 'static const JinxNativeStringPair jinx_native_cfg_metadata[] = {';
foreach ($cfgRows as [$cfgName, $cfgValue]) {
    $code[] = '    { ' . cstr($cfgName) . ', ' . cstr($cfgValue) . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_cfg_metadata_count = sizeof(jinx_native_cfg_metadata) / sizeof(jinx_native_cfg_metadata[0]);';
$code[] = '';

$code[] = 'static const JinxNativeStringPair jinx_native_html_translation_default[] = {';
foreach ($htmlTranslationRows as [$from, $to]) {
    $code[] = '    { ' . cstr($from) . ', ' . cstr($to) . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_html_translation_default_count = sizeof(jinx_native_html_translation_default) / sizeof(jinx_native_html_translation_default[0]);';
$code[] = '';

$classVarSymbols = [];
foreach ($classVarMetaRows as $idx => [$className, $items, $complete]) {
    $symbol = 'jinx_native_class_vars_' . $idx;
    $classVarSymbols[] = [$className, $symbol, count($items), $complete];
    $code[] = 'static const JinxNativeConstantMeta ' . $symbol . '[] = {';
    foreach ($items as [$name, $type, $i64, $f64, $str]) {
        $code[] = '    { ' . cstr((string)$name) . ', ' . (int)$type . ', ' . $i64 . ', ' . $f64 . ', ' . $str . ' },';
    }
    $code[] = '};';
}
$code[] = 'static const JinxNativeClassVarsMeta jinx_native_class_vars_metadata[] = {';
foreach ($classVarSymbols as [$className, $symbol, $count, $complete]) {
    $code[] = '    { ' . cstr($className) . ', ' . $symbol . ', ' . $count . 'u, ' . ($complete ? '1' : '0') . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_class_vars_metadata_count = sizeof(jinx_native_class_vars_metadata) / sizeof(jinx_native_class_vars_metadata[0]);';
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
