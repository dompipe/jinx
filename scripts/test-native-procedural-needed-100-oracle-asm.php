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
    ['date_timezone_get', ['dt:2024-01-02 03:04:05'], 'zend-object:DateTimeZone:1'],
    ['class_exists', ['s:stdClass'], 'bool:' . (class_exists('stdClass') ? 'true' : 'false')],
    ['class_implements', ['s:stdClass'], 'zend-array:' . count(class_implements('stdClass', false) ?: [])],
    ['class_parents', ['s:stdClass'], 'zend-array:' . count(class_parents('stdClass', false) ?: [])],
    ['class_uses', ['s:stdClass'], 'zend-array:' . count(class_uses('stdClass', false) ?: [])],
    ['constant', ['s:PHP_VERSION_ID'], 'int:' . PHP_VERSION_ID],
    ['defined', ['s:PHP_VERSION_ID'], 'bool:' . (defined('PHP_VERSION_ID') ? 'true' : 'false')],
];

foreach ($checks as [$name, $args, $expected]) {
    $actual = jinx100($jinx, $name, $args, false, $code);
    if ($code !== 0 || $actual !== $expected) {
        fail100("{$name} parity mismatch\nPHP/expected: {$expected}\nJINX: {$actual}");
    }
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
$copy = $tmp . '/copy.txt';
$written = $tmp . '/written.txt';
file_put_contents($source, "alpha\nbeta\n");

$typedSource = 's:' . $source;
$typedCopy = 's:' . $copy;
$typedWritten = 's:' . $written;

$fsChecks = [
    ['file_exists', [$typedSource], 'bool:true'],
    ['fileatime', [$typedSource], 'int:' . fileatime($source)],
    ['filectime', [$typedSource], 'int:' . filectime($source)],
    ['filegroup', [$typedSource], 'int:' . filegroup($source)],
    ['fileinode', [$typedSource], 'int:' . fileinode($source)],
    ['filemtime', [$typedSource], 'int:' . filemtime($source)],
    ['fileowner', [$typedSource], 'int:' . fileowner($source)],
    ['fileperms', [$typedSource], 'int:' . fileperms($source)],
    ['filesize', [$typedSource], 'int:' . filesize($source)],
    ['filetype', [$typedSource], 'string:' . filetype($source)],
    ['file', [$typedSource], 'zend-array:' . count(file($source))],
    ['copy', [$typedSource, $typedCopy], 'bool:true'],
    ['fopen', [$typedSource, 's:rb'], 'zend-object:stream:1'],
    ['fclose', ['fp:tmp'], 'bool:true'],
    ['feof', ['fp:tmp'], 'bool:false'],
    ['fflush', ['fp:tmp'], 'bool:true'],
    ['fgetc', ['fp:tmp'], 'string:a'],
    ['fgetcsv', ['fp:tmp'], 'zend-array:2'],
    ['flock', ['fp:tmp', 'i:1'], 'bool:true'],
    ['chdir', ['s:.'], 'bool:true'],
    ['chgrp', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['chmod', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['chown', ['s:/__jinx_oracle_missing__', 'i:0'], 'bool:false'],
    ['clearstatcache', [], 'null'],
];

foreach ($fsChecks as [$name, $args, $expected]) {
    $actual = jinx100($jinx, $name, $args, false, $code);
    if ($code !== 0 || $actual !== $expected) {
        @unlink($source); @unlink($copy); @unlink($written); @rmdir($tmp);
        fail100("{$name} filesystem parity mismatch\nExpected: {$expected}\nJINX: {$actual}");
    }
}

$actual = jinx100($jinx, 'file_get_contents', [$typedSource], true, $code);
$expected = 'hex:' . bin2hex((string)file_get_contents($source));
if ($code !== 0 || $actual !== $expected) {
    fail100("file_get_contents parity mismatch\nExpected: {$expected}\nJINX: {$actual}");
}

$actual = jinx100($jinx, 'file_put_contents', [$typedWritten, 's:xyz'], false, $code);
if ($code !== 0 || $actual !== 'int:3' || file_get_contents($written) !== 'xyz') {
    fail100("file_put_contents parity mismatch\nJINX: {$actual}");
}

$actual = jinx100($jinx, 'fgets', ['fp:tmp'], true, $code);
if ($code !== 0 || $actual !== 'hex:' . bin2hex("a,b\n")) {
    fail100("fgets parity mismatch\nJINX: {$actual}");
}

@unlink($source);
@unlink($copy);
@unlink($written);
@rmdir($tmp);

echo 'PASS: first 100 needed procedural Oracle ASM targets execute without placeholders; existing array/calendar parity and extended callable/date/filesystem/introspection checks pass' . PHP_EOL;
