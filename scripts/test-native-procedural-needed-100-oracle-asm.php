<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$targetFile = $root . '/oracle-sm/zend/procedural_needed_100.osm';

function fail100(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run100(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function jinx100(string $jinx, string $name, array $args = [], bool $hex = false, ?int &$code = null): string
{
    $cmd = escapeshellarg($jinx)
        . ' ' . ($hex ? 'oracle-call-hex' : 'oracle-call')
        . ' ' . escapeshellarg($name);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    return run100($cmd, $code);
}

function sample100(string $name): array
{
    if (in_array($name, ['array_all', 'array_any', 'array_find', 'array_find_key'], true)) {
        return ['za:sample', 's:is_numeric'];
    }
    if ($name === 'array_map') return ['s:abs', 'za:sample'];
    if ($name === 'array_reduce') return ['za:sample', 's:max', 'i:0'];
    if (in_array($name, [
        'array_diff_uassoc', 'array_diff_ukey', 'array_udiff', 'array_udiff_assoc',
        'array_intersect_uassoc', 'array_intersect_ukey',
        'array_uintersect', 'array_uintersect_assoc',
    ], true)) {
        return ['za:sample', 'za:sample', 's:strcmp'];
    }
    if (in_array($name, ['array_udiff_uassoc', 'array_uintersect_uassoc'], true)) {
        return ['za:sample', 'za:sample', 's:strcmp', 's:strcmp'];
    }
    if ($name === 'array_fill') return ['i:0', 'i:3', 'i:9'];
    if ($name === 'array_fill_keys') return ['za:sample', 'i:9'];
    if ($name === 'array_combine') return ['za:sample', 'za:sample'];
    if ($name === 'array_chunk') return ['za:sample', 'i:2'];
    if ($name === 'array_column') return ['za:sample', 's:name'];
    if ($name === 'array_rand') return ['za:sample', 'i:1'];
    if ($name === 'array_multisort') return ['za:sample'];
    if (in_array($name, ['array_walk', 'array_walk_recursive'], true)) return ['za:walk', 's:settype'];
    if (in_array($name, ['usort', 'uasort', 'uksort'], true)) return ['za:strings', 's:strcmp'];
    if (in_array($name, [
        'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort',
        'natsort', 'natcasesort', 'shuffle',
    ], true)) {
        return ['za:sample'];
    }
    if (str_starts_with($name, 'array_')) return ['za:sample'];

    if ($name === 'call_user_func') return ['s:strlen', 's:oracle'];
    if ($name === 'call_user_func_array') return ['s:max', 'za:sample'];

    if ($name === 'cal_days_in_month') return ['i:0', 'i:2', 'i:2024'];
    if ($name === 'cal_to_jd') return ['i:0', 'i:1', 'i:1', 'i:2024'];

    if ($name === 'date') return ['s:Y-m-d', 'i:1704067200'];
    if ($name === 'date_create' || $name === 'date_create_immutable') {
        return ['s:2024-01-02 03:04:05'];
    }
    if ($name === 'date_create_from_format' || $name === 'date_create_immutable_from_format') {
        return ['s:Y-m-d H:i:s', 's:2024-01-02 03:04:05'];
    }
    if (in_array($name, ['date_add', 'date_sub'], true)) {
        return ['dt:2024-01-02 03:04:05', 'di:1 day'];
    }
    if ($name === 'date_diff') {
        return ['dt:2024-01-02 03:04:05', 'dt:2024-01-03 03:04:05'];
    }
    if ($name === 'date_format') return ['dt:2024-01-02 03:04:05', 's:Y-m-d H:i:s'];
    if ($name === 'date_get_last_errors' || $name === 'date_default_timezone_get') return [];
    if ($name === 'date_default_timezone_set') return ['s:UTC'];
    if ($name === 'date_interval_create_from_date_string') return ['s:1 day'];
    if ($name === 'date_interval_format') return ['di:1 day', 's:%d'];
    if ($name === 'date_isodate_set') return ['dt:2024-01-02 03:04:05', 'i:2024', 'i:2', 'i:1'];
    if ($name === 'date_modify') return ['dt:2024-01-02 03:04:05', 's:1 day'];
    if ($name === 'date_offset_get' || $name === 'date_timestamp_get' || $name === 'date_timezone_get') {
        return ['dt:2024-01-02 03:04:05'];
    }
    if ($name === 'date_parse') return ['s:2024-01-02 03:04:05'];
    if ($name === 'date_parse_from_format') return ['s:Y-m-d H:i:s', 's:2024-01-02 03:04:05'];
    if ($name === 'date_date_set') return ['dt:2024-01-02 03:04:05', 'i:2025', 'i:2', 'i:3'];
    if ($name === 'date_time_set') return ['dt:2024-01-02 03:04:05', 'i:4', 'i:5', 'i:6'];
    if ($name === 'date_timestamp_set') return ['dt:2024-01-02 03:04:05', 'i:1704164645'];
    if ($name === 'date_timezone_set') return ['dt:2024-01-02 03:04:05', 'tz:UTC'];

    if ($name === 'dirname') return ['s:/tmp/example.txt'];
    if ($name === 'clearstatcache') return [];
    if ($name === 'chdir') return ['s:.'];
    if (in_array($name, ['chgrp', 'chmod', 'chown'], true)) {
        return ['s:/__jinx_oracle_missing__', 'i:0'];
    }
    if ($name === 'copy') return ['s:/__jinx_oracle_missing__', 's:/tmp/jinx-oracle-copy-missing'];
    if (in_array($name, ['fclose', 'feof', 'fflush', 'fgetc', 'fgetcsv', 'fgets'], true)) {
        return ['fp:tmp'];
    }
    if ($name === 'flock') return ['fp:tmp', 'i:1'];
    if ($name === 'fopen') return ['s:README.md', 's:rb'];
    if ($name === 'file_put_contents') return ['s:/__jinx_oracle_missing__/out', 's:x'];
    if (str_starts_with($name, 'file')) return ['s:README.md'];

    if ($name === 'class_alias') return ['s:stdClass', 's:JinxInternalAliasProbe'];
    if (in_array($name, ['class_exists', 'class_implements', 'class_parents', 'class_uses'], true)) {
        return ['s:stdClass'];
    }
    if ($name === 'constant' || $name === 'defined') return ['s:PHP_VERSION_ID'];

    return [];
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail100('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}
if (!is_file($targetFile)) {
    fail100('missing procedural-needed-100 target file');
}

$targetText = (string)file_get_contents($targetFile);
if (!preg_match('/targets\s*\{(.*?)\n\}/s', $targetText, $m)) {
    fail100('could not parse targets block');
}

$targets = [];
foreach (preg_split('/\R/', $m[1]) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $targets[] = $line;
}

if (count($targets) !== 100) {
    fail100('expected exactly 100 first-wave targets, got ' . count($targets));
}

/* Execution gate: every first-wave target must reach a real handler. */
foreach ($targets as $name) {
    $out = jinx100($jinx, $name, sample100($name), false, $code);
    if ($code !== 0 || str_starts_with($out, 'null/fault:')) {
        fail100("first-wave target still faults: {$name}\n{$out}");
    }
}

/* Existing array/calendar PHP parity suite proves the first 40 pre-existing handlers. */
$arrayParity = run100(
    escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($root . '/scripts/test-native-zend-array-core-oracle-asm.php'),
    $code
);
if ($code !== 0 || !str_contains($arrayParity, 'PASS: native Oracle ASM Zend-array core matches PHP')) {
    fail100("existing Zend-array/calendar parity suite failed\n{$arrayParity}");
}

/* Exact scalar/output checks for the extended backend. */
$phpDefaultTimezone = date_default_timezone_get();
$phpDefaultZone = new DateTimeZone($phpDefaultTimezone);
$phpFixtureDate = new DateTimeImmutable('2024-01-02 03:04:05', $phpDefaultZone);

$checks = [
    ['call_user_func', ['s:strlen', 's:oracle'], 'int:' . strlen('oracle')],
    ['call_user_func_array', ['s:max', 'za:sample'], 'int:40'],
    ['cal_days_in_month', ['i:0', 'i:2', 'i:2024'], 'int:' . cal_days_in_month(CAL_GREGORIAN, 2, 2024)],
    ['cal_to_jd', ['i:0', 'i:1', 'i:1', 'i:2024'], 'int:' . cal_to_jd(CAL_GREGORIAN, 1, 1, 2024)],
    ['date', ['s:Y-m-d', 'i:1704067200'], 'string:' . date('Y-m-d', 1704067200)],
    ['mktime', ['i:3', 'i:4', 'i:5', 'i:1', 'i:2', 'i:2024'], 'int:' . mktime(3, 4, 5, 1, 2, 2024)],
    ['localtime', ['i:1704067200'], 'zend-array:' . count(localtime(1704067200))],
    ['localtime', ['i:1704067200', 'b:true'], 'zend-array:' . count(localtime(1704067200, true))],
    ['idate', ['s:Y', 'i:1704067200'], 'int:' . idate('Y', 1704067200)],
    ['idate', ['s:W', 'i:1704067200'], 'int:' . idate('W', 1704067200)],
    ['idate', ['s:Z', 'i:1704067200'], 'int:' . idate('Z', 1704067200)],
    ['date_create', ['s:2024-01-02 03:04:05'], 'zend-object:DateTime:2'],
    ['date_create_from_format', ['s:Y-m-d H:i:s', 's:2024-01-02 03:04:05'], 'zend-object:DateTime:2'],
    ['date_create_immutable', ['s:2024-01-02 03:04:05'], 'zend-object:DateTimeImmutable:2'],
    ['date_create_immutable_from_format', ['s:Y-m-d H:i:s', 's:2024-01-02 03:04:05'], 'zend-object:DateTimeImmutable:2'],
    ['date_default_timezone_get', [], 'string:' . $phpDefaultTimezone],
    ['date_default_timezone_set', ['s:UTC'], 'bool:true'],
    ['date_format', ['dt:2024-01-02 03:04:05', 's:Y-m-d H:i:s'], 'string:2024-01-02 03:04:05'],
    ['date_get_last_errors', [], 'bool:false'],
    ['date_interval_create_from_date_string', ['s:1 day'], 'zend-object:DateInterval:8'],
    ['date_interval_format', ['di:1 day', 's:%d'], 'string:1'],
    ['date_offset_get', ['dt:2024-01-02 03:04:05'], 'int:' . $phpFixtureDate->getOffset()],
    ['date_parse', ['s:2024-01-02 03:04:05'], 'zend-array:12'],
    ['date_parse_from_format', ['s:Y-m-d H:i:s', 's:2024-01-02 03:04:05'], 'zend-array:12'],
    ['date_timestamp_get', ['dt:2024-01-02 03:04:05'], 'int:' . $phpFixtureDate->getTimestamp()],
    ['strtotime', ['s:2024-01-02 03:04:05', 'i:1704067200'], (($v = strtotime('2024-01-02 03:04:05', 1704067200)) === false ? 'bool:false' : 'int:' . $v)],
    ['strtotime', ['s:+1 day', 'i:1704067200'], (($v = strtotime('+1 day', 1704067200)) === false ? 'bool:false' : 'int:' . $v)],
    ['strtotime', ['s:@1704067200', 'i:1704067200'], (($v = strtotime('@1704067200', 1704067200)) === false ? 'bool:false' : 'int:' . $v)],
    ['date_timezone_get', ['dt:2024-01-02 03:04:05'], 'zend-object:DateTimeZone:1'],
    ['timezone_open', ['s:UTC'], 'zend-object:DateTimeZone:1'],
    ['timezone_open', ['s:__JinxMissing/Timezone'], 'bool:false'],
    ['timezone_name_get', ['tz:UTC'], 'string:' . timezone_name_get(new DateTimeZone('UTC'))],
    ['timezone_offset_get', ['tz:UTC', 'dt:2024-01-02 03:04:05'], 'int:' . timezone_offset_get(new DateTimeZone('UTC'), new DateTime('2024-01-02 03:04:05', new DateTimeZone('UTC')))],
    ['class_exists', ['s:stdClass'], 'bool:' . (class_exists('stdClass') ? 'true' : 'false')],
    ['class_implements', ['s:stdClass'], 'zend-array:' . count(class_implements('stdClass', false) ?: [])],
    ['class_parents', ['s:stdClass'], 'zend-array:' . count(class_parents('stdClass', false) ?: [])],
    ['class_uses', ['s:stdClass'], 'zend-array:' . count(class_uses('stdClass', false) ?: [])],
    ['method_exists', ['s:ArrayIterator', 's:current'], 'bool:' . (method_exists('ArrayIterator', 'current') ? 'true' : 'false')],
    ['method_exists', ['obj:ArrayIterator', 's:CURRENT'], 'bool:' . (method_exists(new ArrayIterator([]), 'CURRENT') ? 'true' : 'false')],
    ['method_exists', ['s:ArrayIterator', 's:__jinx_missing_method'], 'bool:false'],
    ['property_exists', ['s:Exception', 's:message'], 'bool:' . (property_exists(Exception::class, 'message') ? 'true' : 'false')],
    ['property_exists', ['ex:Exception', 's:message'], 'bool:' . (property_exists(new Exception('jinx'), 'message') ? 'true' : 'false')],
    ['property_exists', ['s:Exception', 's:__jinx_missing_property'], 'bool:false'],
    ['trait_exists', ['s:__JinxMissingTrait'], 'bool:false'],
    ['token_name', ['i:' . T_STRING], 'string:' . token_name(T_STRING)],
    ['token_name', ['i:1'], 'string:' . token_name(1)],
    ['is_a', ['obj:ErrorException', 's:Exception'], 'bool:' . (is_a(new ErrorException('probe'), 'Exception') ? 'true' : 'false')],
    ['is_a', ['obj:ErrorException', 's:ErrorException'], 'bool:' . (is_a(new ErrorException('probe'), 'ErrorException') ? 'true' : 'false')],
    ['is_a', ['s:ErrorException', 's:Exception'], 'bool:' . (is_a('ErrorException', 'Exception') ? 'true' : 'false')],
    ['is_a', ['s:ErrorException', 's:Exception', 'b:true'], 'bool:' . (is_a('ErrorException', 'Exception', true) ? 'true' : 'false')],
    ['is_subclass_of', ['obj:ErrorException', 's:Exception'], 'bool:' . (is_subclass_of(new ErrorException('probe'), 'Exception') ? 'true' : 'false')],
    ['is_subclass_of', ['obj:ErrorException', 's:ErrorException'], 'bool:' . (is_subclass_of(new ErrorException('probe'), 'ErrorException') ? 'true' : 'false')],
    ['is_subclass_of', ['s:ErrorException', 's:Exception'], 'bool:' . (is_subclass_of('ErrorException', 'Exception') ? 'true' : 'false')],
    ['is_callable', ['s:strlen'], 'bool:' . (is_callable('strlen') ? 'true' : 'false')],
    ['is_callable', ['s:__jinx_missing_callable'], 'bool:' . (is_callable('__jinx_missing_callable') ? 'true' : 'false')],
    ['tmpfile', [], 'zend-object:stream:1'],
    ['popen', ['s:true', 's:r'], 'zend-object:stream:1'],
    ['pclose', ['pp:tmp'], 'int:0'],
    ['constant', ['s:PHP_VERSION_ID'], 'int:' . PHP_VERSION_ID],
    ['defined', ['s:PHP_VERSION_ID'], 'bool:' . (defined('PHP_VERSION_ID') ? 'true' : 'false')],
];

$declaredTraits = get_declared_traits();
if ($declaredTraits !== []) {
    $checks[] = [
        'trait_exists',
        ['s:' . $declaredTraits[0]],
        'bool:' . (trait_exists($declaredTraits[0], false) ? 'true' : 'false'),
    ];
}

foreach ($checks as [$name, $args, $expected]) {
    $actual = jinx100($jinx, $name, $args, false, $code);
    if ($code !== 0 || $actual !== $expected) {
        fail100("{$name} parity mismatch\nPHP/expected: {$expected}\nJINX: {$actual}");
    }
}

$splId = jinx100($jinx, 'spl_object_id', ['obj:stdClass'], false, $code);
if ($code !== 0 ||
    !preg_match('/^int:([1-9][0-9]*)$/', $splId)) {
    fail100("spl_object_id return-contract mismatch\nJINX: {$splId}");
}

$splHash = jinx100($jinx, 'spl_object_hash', ['obj:stdClass'], false, $code);
if ($code !== 0 ||
    !preg_match('/^string:[0-9a-f]{32}$/', $splHash)) {
    fail100("spl_object_hash return-contract mismatch\nJINX: {$splHash}");
}

/* Stateful-object calls: each receives a deterministic fixture and must return
 * the same PHP object family while performing the requested operation. */
$objectChecks = [
    ['date_add', ['dt:2024-01-02 03:04:05', 'di:1 day'], 'zend-object:DateTime:2'],
    ['date_sub', ['dt:2024-01-02 03:04:05', 'di:1 day'], 'zend-object:DateTime:2'],
    ['date_diff', ['dt:2024-01-02 03:04:05', 'dt:2024-01-03 03:04:05'], 'zend-object:DateInterval:8'],
    ['date_date_set', ['dt:2024-01-02 03:04:05', 'i:2025', 'i:2', 'i:3'], 'zend-object:DateTime:2'],
    ['date_time_set', ['dt:2024-01-02 03:04:05', 'i:4', 'i:5', 'i:6'], 'zend-object:DateTime:2'],
    ['date_isodate_set', ['dt:2024-01-02 03:04:05', 'i:2024', 'i:2', 'i:1'], 'zend-object:DateTime:2'],
    ['date_modify', ['dt:2024-01-02 03:04:05', 's:1 day'], 'zend-object:DateTime:2'],
    ['date_timestamp_set', ['dt:2024-01-02 03:04:05', 'i:1704164645'], 'zend-object:DateTime:2'],
    ['date_timezone_set', ['dt:2024-01-02 03:04:05', 'tz:UTC'], 'zend-object:DateTime:2'],
];

foreach ($objectChecks as [$name, $args, $expected]) {
    $actual = jinx100($jinx, $name, $args, false, $code);
    if ($code !== 0 || $actual !== $expected) {
        fail100("{$name} object result mismatch\nExpected: {$expected}\nJINX: {$actual}");
    }
}

/* Filesystem parity on one private temp directory. */
$tmp = sys_get_temp_dir() . '/jinx-oracle-100-' . getmypid();
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fail100('could not create filesystem parity temp directory');
}

$source = $tmp . '/source.txt';
$link = $tmp . '/source-link';
$nativeSymlink = $tmp . '/native-symlink';
$nativeHardlink = $tmp . '/native-hardlink';
$renameSource = $tmp . '/rename-source.txt';
$renameDest = $tmp . '/rename-dest.txt';
$removeDir = $tmp . '/remove-dir';
$touched = $tmp . '/touched.txt';
$copy = $tmp . '/copy.txt';
$written = $tmp . '/written.txt';
file_put_contents($source, "alpha\nbeta\n");
file_put_contents($renameSource, "rename\n");
if (!mkdir($removeDir, 0700)) {
    @unlink($source);
    @unlink($renameSource);
    @rmdir($tmp);
    fail100('could not create filesystem parity removable directory');
}
if (!symlink($source, $link)) {
    @rmdir($removeDir);
    @unlink($renameSource);
    @unlink($source);
    @rmdir($tmp);
    fail100('could not create filesystem parity symlink');
}
if (!touch($source, 1700000100, 1700000101)) {
    @unlink($link);
    @rmdir($removeDir);
    @unlink($renameSource);
    @unlink($source);
    @rmdir($tmp);
    fail100('could not pin filesystem parity source timestamps');
}
clearstatcache(true, $source);

$typedSource = 's:' . $source;
$typedLink = 's:' . $link;
$typedNativeSymlink = 's:' . $nativeSymlink;
$typedNativeHardlink = 's:' . $nativeHardlink;
$typedRenameSource = 's:' . $renameSource;
$typedRenameDest = 's:' . $renameDest;
$typedRemoveDir = 's:' . $removeDir;
$typedTouched = 's:' . $touched;
$typedCopy = 's:' . $copy;
$typedWritten = 's:' . $written;

$phpTemp = tempnam($tmp, 'jx');
if ($phpTemp === false) {
    fail100('PHP tempnam fixture failed');
}
$tempnamActual = jinx100($jinx, 'tempnam', ['s:' . $tmp, 's:jx'], false, $code);
if ($code !== 0 || !str_starts_with($tempnamActual, 'string:')) {
    @unlink($phpTemp);
    fail100("tempnam return-contract mismatch\nJINX: {$tempnamActual}");
}
$jinxTemp = substr($tempnamActual, strlen('string:'));
if (!is_file($jinxTemp) ||
    dirname($jinxTemp) !== dirname($phpTemp) ||
    !str_starts_with(basename($jinxTemp), 'jx') ||
    !str_starts_with(basename($phpTemp), 'jx')) {
    @unlink($jinxTemp);
    @unlink($phpTemp);
    fail100("tempnam filesystem parity mismatch\nPHP: {$phpTemp}\nJINX: {$jinxTemp}");
}

$fsChecks = [
    ['file_exists', [$typedSource], 'bool:true'],
    ['is_link', [$typedLink], 'bool:' . (is_link($link) ? 'true' : 'false')],
    ['is_link', [$typedSource], 'bool:' . (is_link($source) ? 'true' : 'false')],
    ['readlink', [$typedLink], 'string:' . readlink($link)],
    ['linkinfo', [$typedLink], 'int:' . linkinfo($link)],
    ['symlink', [$typedSource, $typedNativeSymlink], 'bool:true'],
    ['is_link', [$typedNativeSymlink], 'bool:true'],
    ['readlink', [$typedNativeSymlink], 'string:' . $source],
    ['rename', [$typedRenameSource, $typedRenameDest], 'bool:true'],
    ['file_exists', [$typedRenameDest], 'bool:true'],
    ['rmdir', [$typedRemoveDir], 'bool:true'],
    ['file_exists', [$typedRemoveDir], 'bool:false'],
    ['umask', [], 'int:' . umask()],
    ['is_readable', [$typedSource], 'bool:' . (is_readable($source) ? 'true' : 'false')],
    ['is_writable', [$typedSource], 'bool:' . (is_writable($source) ? 'true' : 'false')],
    ['is_writeable', [$typedSource], 'bool:' . (is_writeable($source) ? 'true' : 'false')],
    ['is_executable', [$typedSource], 'bool:' . (is_executable($source) ? 'true' : 'false')],
    ['fileatime', [$typedSource], 'int:' . fileatime($source)],
    ['filectime', [$typedSource], 'int:' . filectime($source)],
    ['filegroup', [$typedSource], 'int:' . filegroup($source)],
    ['fileinode', [$typedSource], 'int:' . fileinode($source)],
    ['filemtime', [$typedSource], 'int:' . filemtime($source)],
    ['fileowner', [$typedSource], 'int:' . fileowner($source)],
    ['fileperms', [$typedSource], 'int:' . fileperms($source)],
    ['filesize', [$typedSource], 'int:' . filesize($source)],
    ['filetype', [$typedSource], 'string:' . filetype($source)],
    ['link', [$typedSource, $typedNativeHardlink], 'bool:true'],
    ['file_exists', [$typedNativeHardlink], 'bool:true'],
    ['file', [$typedSource], 'zend-array:' . count(file($source))],
    ['copy', [$typedSource, $typedCopy], 'bool:true'],
    ['fopen', [$typedSource, 's:rb'], 'zend-object:stream:1'],
    ['fclose', ['fp:tmp'], 'bool:true'],
    ['feof', ['fp:tmp'], 'bool:false'],
    ['fflush', ['fp:tmp'], 'bool:true'],
    ['fgetc', ['fp:tmp'], 'string:a'],
    ['rewind', ['fp:tmp'], 'bool:true'],
    ['fgetcsv', ['fp:tmp'], 'zend-array:2'],
    ['vfprintf', ['fp:tmp', 's:%d,%d,%d,%d', 'za:sample'], 'int:' . strlen(sprintf('%d,%d,%d,%d', 10, 20, 30, 40))],
    ['flock', ['fp:tmp', 'i:1'], 'bool:true'],
    ['chdir', ['s:.'], 'bool:true'],
    ['chgrp', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['lchgrp', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['chmod', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['chown', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['lchown', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['clearstatcache', [], 'null'],
];

foreach ($fsChecks as [$name, $args, $expected]) {
    $actual = jinx100($jinx, $name, $args, false, $code);
    if ($code !== 0 || $actual !== $expected) {
        @unlink($jinxTemp); @unlink($phpTemp); @unlink($nativeSymlink); @unlink($nativeHardlink); @unlink($link); @unlink($renameSource); @unlink($renameDest); @unlink($touched); @rmdir($removeDir); @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);
        fail100("{$name} filesystem parity mismatch\nExpected: {$expected}\nJINX: {$actual}");
    }
}

$readfileActual = run100(
    escapeshellarg($jinx)
    . ' oracle-call readfile '
    . escapeshellarg($typedSource),
    $code
);
$readfileExpected = (string)file_get_contents($source) . 'int:' . filesize($source);
if ($code !== 0 || $readfileActual !== $readfileExpected) {
    @unlink($jinxTemp); @unlink($phpTemp); @unlink($nativeSymlink); @unlink($nativeHardlink); @unlink($link); @unlink($renameSource); @unlink($renameDest); @unlink($touched); @rmdir($removeDir); @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);
    fail100("readfile output/return parity mismatch\nExpected: {$readfileExpected}\nJINX: {$readfileActual}");
}

$actual = jinx100($jinx, 'touch', [$typedTouched, 'i:1700000000', 'i:1700000001'], false, $code);
clearstatcache(true, $touched);
if ($code !== 0 || $actual !== 'bool:true' ||
    filemtime($touched) !== 1700000000 || fileatime($touched) !== 1700000001) {
    @unlink($jinxTemp); @unlink($phpTemp); @unlink($nativeSymlink); @unlink($nativeHardlink); @unlink($link); @unlink($renameSource); @unlink($renameDest); @unlink($touched); @rmdir($removeDir); @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);
    fail100("touch filesystem parity mismatch\nJINX: {$actual}");
}

$actual = jinx100($jinx, 'file_get_contents', [$typedSource], true, $code);
$expected = 'hex:' . bin2hex((string)file_get_contents($source));
if ($code !== 0 || $actual !== $expected) {
    @unlink($jinxTemp); @unlink($phpTemp); @unlink($nativeSymlink); @unlink($nativeHardlink); @unlink($link); @unlink($renameSource); @unlink($renameDest); @unlink($touched); @rmdir($removeDir); @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);
    fail100("file_get_contents parity mismatch\nExpected: {$expected}\nJINX: {$actual}");
}

$actual = jinx100($jinx, 'file_put_contents', [$typedWritten, 's:xyz'], false, $code);
if ($code !== 0 || $actual !== 'int:3' || file_get_contents($written) !== 'xyz') {
    @unlink($jinxTemp); @unlink($phpTemp); @unlink($nativeSymlink); @unlink($nativeHardlink); @unlink($link); @unlink($renameSource); @unlink($renameDest); @unlink($touched); @rmdir($removeDir); @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);
    fail100("file_put_contents parity mismatch\nJINX: {$actual}");
}

$actual = jinx100($jinx, 'fgets', ['fp:tmp'], true, $code);
if ($code !== 0 || $actual !== 'hex:' . bin2hex("a,b\n")) {
    @unlink($jinxTemp); @unlink($phpTemp); @unlink($nativeSymlink); @unlink($nativeHardlink); @unlink($link); @unlink($renameSource); @unlink($renameDest); @unlink($touched); @rmdir($removeDir); @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);
    fail100("fgets parity mismatch\nJINX: {$actual}");
}

@unlink($jinxTemp); @unlink($phpTemp); @unlink($nativeSymlink); @unlink($nativeHardlink); @unlink($link); @unlink($renameSource); @unlink($renameDest); @unlink($touched); @rmdir($removeDir); @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);

echo 'PASS: first 100 needed procedural Oracle ASM targets execute without placeholders; existing array/calendar parity and extended callable/date/filesystem/introspection checks pass' . PHP_EOL;
