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
$systemTempDirectory = sys_get_temp_dir();
$phpSapiName = PHP_SAPI;
$phpVersion = PHP_VERSION;
$zendVersion = zend_version();
$phpIniLoadedFile = php_ini_loaded_file();
$phpIniScannedFiles = php_ini_scanned_files();
$posixUname = function_exists('posix_uname') ? posix_uname() : false;
$posixUnameHasDomainname = is_array($posixUname) && array_key_exists('domainname', $posixUname);

$assertActive = defined('ASSERT_ACTIVE') ? (int)@assert_options(ASSERT_ACTIVE) : 1;
$assertWarning = defined('ASSERT_WARNING') ? (int)@assert_options(ASSERT_WARNING) : 1;
$assertBail = defined('ASSERT_BAIL') ? (int)@assert_options(ASSERT_BAIL) : 0;
$assertException = defined('ASSERT_EXCEPTION') ? (int)@assert_options(ASSERT_EXCEPTION) : 1;
$dateDefaultLatitude = (float)ini_get('date.default_latitude');
$dateDefaultLongitude = (float)ini_get('date.default_longitude');
$dateSunriseZenith = (float)ini_get('date.sunrise_zenith');
$dateSunsetZenith = (float)ini_get('date.sunset_zenith');
$iconvInputEncoding = function_exists('iconv_get_encoding')
    ? (string)(iconv_get_encoding('input_encoding') ?: 'UTF-8')
    : 'UTF-8';
$iconvOutputEncoding = function_exists('iconv_get_encoding')
    ? (string)(iconv_get_encoding('output_encoding') ?: 'UTF-8')
    : 'UTF-8';
$iconvInternalEncoding = function_exists('iconv_get_encoding')
    ? (string)(iconv_get_encoding('internal_encoding') ?: 'UTF-8')
    : 'UTF-8';
$defaultCharset = (string)(ini_get('default_charset') ?: 'UTF-8');

$interfaces = get_declared_interfaces();
$traits = get_declared_traits();
$definedFunctions = get_defined_functions();
$internalFunctions = $definedFunctions['internal'] ?? [];
$extensions = get_loaded_extensions();
$pdoDrivers = function_exists('pdo_drivers') ? (pdo_drivers() ?: []) : [];
sort($pdoDrivers, SORT_STRING);
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

$iniOwners = [];
foreach ($extensions as $extension) {
    /*
     * ini_get_all($extension) warns for several built-in/Zend modules even
     * when get_loaded_extensions() reports them (for example Core,
     * Reflection, SPL, PDO, FFI, Phar, SimpleXML, and Zend OPcache).
     * ReflectionExtension reads the owning INI entries without treating
     * those loaded modules as missing extensions.
     */
    try {
        $owned = (new ReflectionExtension((string)$extension))->getINIEntries();
    } catch (ReflectionException) {
        $owned = [];
    }

    foreach (array_keys($owned) as $iniName) {
        $iniOwners[(string)$iniName] = (string)$extension;
    }
}
$iniRows = [];
foreach (ini_get_all(null, true) ?: [] as $iniName => $meta) {
    if (!is_array($meta)) {
        continue;
    }
    $globalValue = $meta['global_value'] ?? null;
    $localValue = $meta['local_value'] ?? null;
    $iniRows[] = [
        (string)$iniName,
        is_string($globalValue) ? $globalValue : null,
        is_string($localValue) ? $localValue : null,
        (int)($meta['access'] ?? 0),
        $iniOwners[(string)$iniName] ?? '',
    ];
}
usort($iniRows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

$htmlTranslationRows = [];
foreach (get_html_translation_table() as $from => $to) {
    $htmlTranslationRows[] = [(string)$from, (string)$to];
}

$htmlEntitiesRows = [];
foreach (get_html_translation_table(
    HTML_ENTITIES,
    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401,
    'UTF-8'
) as $from => $to) {
    $htmlEntitiesRows[] = [(string)$from, (string)$to];
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
/* Preserve PHP's public ordering for hash_algos()/hash_hmac_algos(). */
$hashAlgos = function_exists('hash_algos') ? hash_algos() : [];
$hashHmacAlgos = function_exists('hash_hmac_algos') ? hash_hmac_algos() : [];
$opensslCipherMethods = function_exists('openssl_get_cipher_methods')
    ? (openssl_get_cipher_methods(false) ?: [])
    : [];
$opensslCipherMethodsAliases = function_exists('openssl_get_cipher_methods')
    ? (openssl_get_cipher_methods(true) ?: [])
    : [];
$opensslMdMethods = function_exists('openssl_get_md_methods')
    ? (openssl_get_md_methods(false) ?: [])
    : [];
$opensslMdMethodsAliases = function_exists('openssl_get_md_methods')
    ? (openssl_get_md_methods(true) ?: [])
    : [];
$opensslCurveNames = function_exists('openssl_get_curve_names')
    ? (openssl_get_curve_names() ?: [])
    : [];
$opensslCertLocationRows = [];
if (function_exists('openssl_get_cert_locations')) {
    foreach (openssl_get_cert_locations() as $locationKey => $locationValue) {
        if (is_string($locationKey)) {
            $opensslCertLocationRows[] = [
                $locationKey,
                is_string($locationValue) ? $locationValue : '',
            ];
        }
    }
}
$streamWrappers = function_exists('stream_get_wrappers') ? stream_get_wrappers() : [];
$streamTransports = function_exists('stream_get_transports') ? stream_get_transports() : [];
$streamFilters = function_exists('stream_get_filters') ? stream_get_filters() : [];

$passwordAlgos = function_exists('password_algos') ? password_algos() : [];
$timezoneIdentifiers = function_exists('timezone_identifiers_list')
    ? timezone_identifiers_list()
    : [];
$timezoneVersion = function_exists('timezone_version_get')
    ? timezone_version_get()
    : '';

$timezoneLocationRows = [];
if (function_exists('timezone_location_get') && class_exists('DateTimeZone')) {
    foreach ($timezoneIdentifiers as $timezoneId) {
        try {
            $timezone = new DateTimeZone((string)$timezoneId);
            $location = timezone_location_get($timezone);
        } catch (Throwable) {
            $location = false;
        }
        if (!is_array($location)) continue;
        $timezoneLocationRows[] = [
            (string)$timezoneId,
            (string)($location['country_code'] ?? ''),
            (float)($location['latitude'] ?? 0.0),
            (float)($location['longitude'] ?? 0.0),
            (string)($location['comments'] ?? ''),
        ];
    }
}

$timezoneAbbrResolveRows = [];
$timezoneAbbrRows = [];
if (function_exists('timezone_abbreviations_list') && function_exists('timezone_name_from_abbr')) {
    $abbrTable = timezone_abbreviations_list();
    $seenResolve = [];

    foreach ($abbrTable as $abbr => $entries) {
        $abbr = (string)$abbr;
        $default = timezone_name_from_abbr($abbr);
        $key = strtolower($abbr) . "|-1|-1";
        if (!isset($seenResolve[$key])) {
            $seenResolve[$key] = true;
            $timezoneAbbrResolveRows[] = [$abbr, -1, -1, $default === false ? null : (string)$default];
        }

        if (!is_array($entries)) continue;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $offset = (int)($entry['offset'] ?? 0);
            $dst = !empty($entry['dst']) ? 1 : 0;
            $timezoneId = array_key_exists('timezone_id', $entry) &&
                $entry['timezone_id'] !== null
                ? (string)$entry['timezone_id']
                : null;
            $timezoneAbbrRows[] = [
                $abbr,
                $dst,
                $offset,
                $timezoneId,
            ];
            $key = strtolower($abbr) . "|" . $offset . "|" . $dst;
            if (isset($seenResolve[$key])) continue;
            $seenResolve[$key] = true;
            $resolved = timezone_name_from_abbr($abbr, $offset, $dst);
            $timezoneAbbrResolveRows[] = [
                $abbr,
                $offset,
                $dst,
                $resolved === false ? null : (string)$resolved,
            ];
        }
    }

    $globalPairs = [];
    foreach ($abbrTable as $entries) {
        if (!is_array($entries)) continue;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $offset = (int)($entry['offset'] ?? 0);
            $dst = !empty($entry['dst']) ? 1 : 0;
            $pairKey = $offset . "|" . $dst;
            if (isset($globalPairs[$pairKey])) continue;
            $globalPairs[$pairKey] = true;
            $resolved = timezone_name_from_abbr('', $offset, $dst);
            $timezoneAbbrResolveRows[] = [
                '',
                $offset,
                $dst,
                $resolved === false ? null : (string)$resolved,
            ];
        }
    }

    usort(
        $timezoneAbbrResolveRows,
        static fn(array $a, array $b): int =>
            [strtolower($a[0]), $a[1], $a[2]] <=> [strtolower($b[0]), $b[1], $b[2]]
    );
}
$splClassRows = [];
if (function_exists('spl_classes')) {
    foreach (spl_classes() as $classKey => $classValue) {
        if (is_string($classKey) && is_string($classValue)) {
            $splClassRows[] = [$classKey, $classValue];
        }
    }
}

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
    $reflection = new ReflectionClass($class);
    $properties = [];
    foreach ($reflection->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() === $class) {
            $properties[] = $property->getName();
        }
    }
    sort($properties, SORT_STRING);
    $classVars = get_class_vars($class) ?: [];
    foreach (['parents'=>$parents,'implements'=>$implements,'uses'=>$uses,'methods'=>$methods,'properties'=>$properties] as $kind=>$values) {
        $sym = 'jinx_meta_' . $idx . '_' . $kind;
        $arrays[] = 'static const char *const ' . $sym . '[] = {' .
            ($values ? implode(', ', array_map('cstr', $values)) . ', ' : '') . 'NULL };';
    }
    $rows[] = sprintf(
        '    { %s, jinx_meta_%d_parents, %d, jinx_meta_%d_implements, %d, jinx_meta_%d_uses, %d, jinx_meta_%d_methods, %d, jinx_meta_%d_properties, %d },',
        cstr($class),
        $idx, count($parents),
        $idx, count($implements),
        $idx, count($uses),
        $idx, count($methods),
        $idx, count($properties)
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
$code[] = '#define JINX_NATIVE_PHP_SYS_TEMP_DIR ' . cstr($systemTempDirectory);
$code[] = '#define JINX_NATIVE_PHP_SAPI_NAME ' . cstr($phpSapiName);
$code[] = '#define JINX_NATIVE_PHP_VERSION ' . cstr($phpVersion);
$code[] = '#define JINX_NATIVE_ZEND_VERSION ' . cstr($zendVersion);
$code[] = '#define JINX_NATIVE_TIMEZONE_VERSION ' . cstr((string)$timezoneVersion);
$code[] = '#define JINX_NATIVE_PHP_INI_LOADED_FILE_AVAILABLE ' . ($phpIniLoadedFile === false ? '0' : '1');
$code[] = '#define JINX_NATIVE_PHP_INI_LOADED_FILE ' . cstr($phpIniLoadedFile === false ? '' : $phpIniLoadedFile);
$code[] = '#define JINX_NATIVE_PHP_INI_SCANNED_FILES_AVAILABLE ' . ($phpIniScannedFiles === false ? '0' : '1');
$code[] = '#define JINX_NATIVE_PHP_INI_SCANNED_FILES ' . cstr($phpIniScannedFiles === false ? '' : $phpIniScannedFiles);
$code[] = '#define JINX_NATIVE_POSIX_UNAME_HAS_DOMAINNAME ' . ($posixUnameHasDomainname ? '1' : '0');
$code[] = '#define JINX_NATIVE_ASSERT_ACTIVE ' . $assertActive;
$code[] = '#define JINX_NATIVE_ASSERT_WARNING ' . $assertWarning;
$code[] = '#define JINX_NATIVE_ASSERT_BAIL ' . $assertBail;
$code[] = '#define JINX_NATIVE_ASSERT_EXCEPTION ' . $assertException;
$code[] = '#define JINX_NATIVE_DATE_DEFAULT_LATITUDE ' . sprintf('%.17g', $dateDefaultLatitude);
$code[] = '#define JINX_NATIVE_DATE_DEFAULT_LONGITUDE ' . sprintf('%.17g', $dateDefaultLongitude);
$code[] = '#define JINX_NATIVE_DATE_SUNRISE_ZENITH ' . sprintf('%.17g', $dateSunriseZenith);
$code[] = '#define JINX_NATIVE_DATE_SUNSET_ZENITH ' . sprintf('%.17g', $dateSunsetZenith);
$code[] = '#define JINX_NATIVE_ICONV_INPUT_ENCODING ' . cstr($iconvInputEncoding);
$code[] = '#define JINX_NATIVE_ICONV_OUTPUT_ENCODING ' . cstr($iconvOutputEncoding);
$code[] = '#define JINX_NATIVE_ICONV_INTERNAL_ENCODING ' . cstr($iconvInternalEncoding);
$code[] = '#define JINX_NATIVE_DEFAULT_CHARSET ' . cstr($defaultCharset);
$code[] = '#define JINX_NATIVE_PHP_ERROR_REPORTING ' . (string)error_reporting() . 'LL';
$code[] = '#define JINX_NATIVE_PHP_INCLUDE_PATH ' . cstr($includePath);
$code[] = '';
$code[] = 'typedef struct JinxNativeClassMeta { const char *name; const char *const *parents; size_t parent_count; const char *const *implements; size_t implements_count; const char *const *uses; size_t uses_count; const char *const *methods; size_t method_count; const char *const *properties; size_t property_count; } JinxNativeClassMeta;';
$code[] = 'typedef struct JinxNativeConstantMeta { const char *name; unsigned type; long long i64; double f64; const char *str; } JinxNativeConstantMeta;';
$code[] = 'typedef struct JinxNativeExtensionMeta { const char *name; const char *const *functions; size_t function_count; } JinxNativeExtensionMeta;';
$code[] = 'typedef struct JinxNativeFilterMeta { const char *name; int id; } JinxNativeFilterMeta;';
$code[] = 'typedef struct JinxNativeStringPair { const char *name; const char *value; } JinxNativeStringPair;';
$code[] = 'typedef struct JinxNativeIniMeta { const char *name; const char *global_value; const char *local_value; int access; const char *extension; } JinxNativeIniMeta;';
$code[] = 'typedef struct JinxNativeClassVarsMeta { const char *class_name; const JinxNativeConstantMeta *vars; size_t var_count; int complete; } JinxNativeClassVarsMeta;';
$code[] = 'typedef struct JinxNativeTimezoneAbbrResolve { const char *abbr; long offset; int dst; const char *timezone_id; } JinxNativeTimezoneAbbrResolve;';
$code[] = 'typedef struct JinxNativeTimezoneAbbrEntry { const char *abbr; int dst; long offset; const char *timezone_id; } JinxNativeTimezoneAbbrEntry;';
$code[] = 'typedef struct JinxNativeTimezoneLocation { const char *timezone_id; const char *country_code; double latitude; double longitude; const char *comments; } JinxNativeTimezoneLocation;';
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
$emitStringArray('jinx_native_pdo_drivers', $pdoDrivers);
$emitStringArray('jinx_native_enum_names', $enums);
$emitStringArray('jinx_native_hash_algos', $hashAlgos);
$emitStringArray('jinx_native_hash_hmac_algos', $hashHmacAlgos);
$emitStringArray('jinx_native_openssl_cipher_methods', $opensslCipherMethods);
$emitStringArray('jinx_native_openssl_cipher_methods_aliases', $opensslCipherMethodsAliases);
$emitStringArray('jinx_native_openssl_md_methods', $opensslMdMethods);
$emitStringArray('jinx_native_openssl_md_methods_aliases', $opensslMdMethodsAliases);
$emitStringArray('jinx_native_openssl_curve_names', $opensslCurveNames);
$emitStringArray('jinx_native_stream_wrappers', $streamWrappers);
$emitStringArray('jinx_native_stream_transports', $streamTransports);
$emitStringArray('jinx_native_stream_filters', $streamFilters);
$emitStringArray('jinx_native_password_algos', $passwordAlgos);
$emitStringArray('jinx_native_timezone_identifiers', $timezoneIdentifiers);

$code[] = 'static const JinxNativeTimezoneAbbrResolve jinx_native_timezone_abbr_resolve[] = {';
foreach ($timezoneAbbrResolveRows as [$abbr, $offset, $dst, $timezoneId]) {
    $code[] = sprintf(
        '    { %s, %dL, %d, %s },',
        cstr((string)$abbr),
        (int)$offset,
        (int)$dst,
        $timezoneId === null ? 'NULL' : cstr((string)$timezoneId)
    );
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_timezone_abbr_resolve_count = ' . count($timezoneAbbrResolveRows) . 'u;';
$code[] = '';
$code[] = 'static const JinxNativeTimezoneAbbrEntry jinx_native_timezone_abbr_entries[] = {';
foreach ($timezoneAbbrRows as [$abbr, $dst, $offset, $timezoneId]) {
    $code[] = sprintf(
        '    { %s, %d, %dL, %s },',
        cstr((string)$abbr),
        (int)$dst,
        (int)$offset,
        $timezoneId === null ? 'NULL' : cstr((string)$timezoneId)
    );
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_timezone_abbr_entries_count = ' . count($timezoneAbbrRows) . 'u;';
$code[] = '';
$code[] = 'static const JinxNativeTimezoneLocation jinx_native_timezone_locations[] = {';
foreach ($timezoneLocationRows as [$timezoneId, $countryCode, $latitude, $longitude, $comments]) {
    $code[] = sprintf(
        '    { %s, %s, %.17g, %.17g, %s },',
        cstr((string)$timezoneId),
        cstr((string)$countryCode),
        (float)$latitude,
        (float)$longitude,
        cstr((string)$comments)
    );
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_timezone_locations_count = ' . count($timezoneLocationRows) . 'u;';
$code[] = '';

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

$code[] = 'static const JinxNativeStringPair jinx_native_spl_classes[] = {';
foreach ($splClassRows as [$classKey, $classValue]) {
    $code[] = '    { ' . cstr($classKey) . ', ' . cstr($classValue) . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_spl_classes_count = sizeof(jinx_native_spl_classes) / sizeof(jinx_native_spl_classes[0]);';
$code[] = '';

$code[] = 'static const JinxNativeStringPair jinx_native_openssl_cert_locations[] = {';
foreach ($opensslCertLocationRows as [$locationKey, $locationValue]) {
    $code[] = '    { ' . cstr($locationKey) . ', ' . cstr($locationValue) . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_openssl_cert_locations_count = sizeof(jinx_native_openssl_cert_locations) / sizeof(jinx_native_openssl_cert_locations[0]);';
$code[] = '';

$code[] = 'static const JinxNativeIniMeta jinx_native_ini_metadata[] = {';
foreach ($iniRows as [$iniName, $globalValue, $localValue, $access, $extension]) {
    $code[] = '    { '
        . cstr($iniName) . ', '
        . ($globalValue === null ? 'NULL' : cstr($globalValue)) . ', '
        . ($localValue === null ? 'NULL' : cstr($localValue)) . ', '
        . (int)$access . ', '
        . cstr($extension)
        . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_ini_metadata_count = sizeof(jinx_native_ini_metadata) / sizeof(jinx_native_ini_metadata[0]);';
$code[] = '';

$code[] = 'static const JinxNativeStringPair jinx_native_html_translation_default[] = {';
foreach ($htmlTranslationRows as [$from, $to]) {
    $code[] = '    { ' . cstr($from) . ', ' . cstr($to) . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_html_translation_default_count = sizeof(jinx_native_html_translation_default) / sizeof(jinx_native_html_translation_default[0]);';
$code[] = '';

$code[] = 'static const JinxNativeStringPair jinx_native_html_entities_html401[] = {';
foreach ($htmlEntitiesRows as [$from, $to]) {
    $code[] = '    { ' . cstr($from) . ', ' . cstr($to) . ' },';
}
$code[] = '};';
$code[] = 'static const size_t jinx_native_html_entities_html401_count = sizeof(jinx_native_html_entities_html401) / sizeof(jinx_native_html_entities_html401[0]);';
$code[] = '';

$classVarSymbols = [];
foreach ($classVarMetaRows as $idx => [$className, $items, $complete]) {
    $symbol = 'jinx_native_class_vars_' . $idx;
    $classVarSymbols[] = [$className, $symbol, count($items), $complete];
    $code[] = 'static const JinxNativeConstantMeta ' . $symbol . '[] = {';
    if ($items === []) {
        $code[] = '    { NULL, 0u, 0LL, 0.0, NULL },';
    } else {
        foreach ($items as [$name, $type, $i64, $f64, $str]) {
            $code[] = '    { ' . cstr((string)$name) . ', ' . (int)$type . ', ' . $i64 . ', ' . $f64 . ', ' . $str . ' },';
        }
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
    "PASS: generated native core metadata: %d classes, %d interfaces, %d traits, %d enums, %d functions, %d extensions, %d hash algos, %d HMAC algos, %d scalar constants -> %s\n",
    count($classes),
    count($interfaces),
    count($traits),
    count($enums),
    count($internalFunctions),
    count($extensions),
    count($hashAlgos),
    count($hashHmacAlgos),
    count($constants),
    $out
);
