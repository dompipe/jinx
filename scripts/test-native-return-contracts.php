<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failReturn(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runReturn(array $parts, ?int &$code = null): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
    $out = [];
    $status = 0;
    exec($cmd, $out, $status);
    $code = $status;
    return trim(implode(PHP_EOL, $out));
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failReturn('native ./jinx is missing; run ./scripts/build-native-jinx.sh first');
}

/* PHP 8+ argument errors must not be flattened into successful false/null values. */
$mustFault = [
    ['array_chunk', 'za:sample', 'i:0'],
    ['explode', 's:', 's:abc'],
    ['str_split', 's:abc', 'i:0'],
    ['count_chars', 's:abc', 'i:9'],
    ['range', 'i:1', 'i:10', 'i:0'],
    ['range', 'i:1', 'i:5', 'i:9'],
    ['array_combine', 'za:sample', 'za:strings'],
    ['array_rand', 'za:sample', 'i:0'],
    ['array_fill', 'i:0', 'i:-1', 'i:9'],
    ['count', 'za:sample', 'i:9'],
    ['count', 'i:7'],
    ['sizeof', 's:not-an-array'],
    ['constant', 's:__JINX_UNDEFINED_CONSTANT__'],
    ['fgets', 'fp:tmp', 'i:1'],
];

foreach ($mustFault as $case) {
    $name = array_shift($case);
    $out = runReturn(array_merge([$jinx, 'oracle-call', $name], $case), $code);
    if ($code === 0 || !str_contains($out, 'null/fault: ' . $name)) {
        failReturn("{$name} invalid-argument path was accepted as a successful return: {$out}");
    }
}

/* Legitimate null return values remain successful calls. */
$validNull = [
    ['parse_url', 's:http://example.com/path', 'i:6'],
    ['array_find', 'za:sample', 's:is_null'],
    ['array_find_key', 'za:sample', 's:is_null'],
];

foreach ($validNull as $case) {
    $name = array_shift($case);
    $out = runReturn(array_merge([$jinx, 'oracle-call', $name], $case), $code);
    if ($code !== 0 || $out !== 'null') {
        failReturn("{$name} legitimate null return was misclassified: {$out}");
    }
}

/* Real Zend-array carriers must use PHP array conversion rules, not object/scalar fallthrough. */
$arrayConversions = [
    ['boolval', 'za:empty', 'bool:false'],
    ['boolval', 'za:sample', 'bool:true'],
    ['intval', 'za:empty', 'int:0'],
    ['intval', 'za:sample', 'int:1'],
    ['floatval', 'za:empty', 'float:0'],
    ['floatval', 'za:sample', 'float:1'],
    ['strval', 'za:sample', 'string:Array'],
    ['intval', 'dt:2024-01-02 03:04:05', 'int:1'],
    ['floatval', 'dt:2024-01-02 03:04:05', 'float:1'],
];

foreach ($arrayConversions as [$name, $arg, $expected]) {
    $out = runReturn([$jinx, 'oracle-call', $name, $arg], $code);
    if ($code !== 0 || $out !== $expected) {
        failReturn("{$name}({$arg}) expected {$expected}, got {$out}");
    }
}

$out = runReturn([$jinx, 'oracle-call', 'strval', 'dt:2024-01-02 03:04:05'], $code);
if ($code === 0 || !str_contains($out, 'null/fault: strval')) {
    failReturn("strval(DateTime) fabricated a scalar value instead of requiring __toString: {$out}");
}

/* Object predicates must use class metadata, not hard-coded false. */
$objectPredicates = [
    ['is_countable', 'obj:ArrayIterator', 'bool:true'],
    ['is_iterable', 'obj:ArrayIterator', 'bool:true'],
    ['is_countable', 'obj:stdClass', 'bool:false'],
    ['is_iterable', 'obj:stdClass', 'bool:false'],
];

foreach ($objectPredicates as [$name, $arg, $expected]) {
    $out = runReturn([$jinx, 'oracle-call', $name, $arg], $code);
    if ($code !== 0 || $out !== $expected) {
        failReturn("{$name}({$arg}) expected {$expected}, got {$out}");
    }
}

/* Existing handlers that were previously mis-audited must execute with valid call shapes. */
$recoveredHandlers = [
    ['array_key_exists', ['s:keep', 'za:sample'], 'bool:true'],
    ['array_pad', ['za:sample', 'i:6', 'i:0'], 'zend-array:6'],
    ['array_search', ['i:20', 'za:sample'], 'int:1'],
    ['array_splice', ['za:sample', 'i:1', 'i:2'], 'zend-array:2'],
    ['base_convert', ['s:ff', 'i:16', 'i:10'], 'string:255'],
    ['dirname', ['s:/tmp/example.txt'], 'string:/tmp'],
    ['pathinfo', ['s:/tmp/example.txt'], 'zend-array:4'],
    ['stat', ['s:README.md'], 'zend-array:26'],
    ['unlink', ['s:/__jinx_oracle_missing__/return-contract'], 'bool:false'],
];

foreach ($recoveredHandlers as [$name, $args, $expected]) {
    $out = runReturn(array_merge([$jinx, 'oracle-call', $name], $args), $code);
    if ($code !== 0 || $out !== $expected) {
        failReturn("{$name} valid call shape expected {$expected}, got {$out}");
    }
}

/* Native stream carriers must behave like PHP resources, not ordinary objects. */
$resourceChecks = [
    ['is_resource', 'bool:true'],
    ['is_object', 'bool:false'],
    ['gettype', 'string:resource'],
    ['get_debug_type', 'string:resource (stream)'],
];

foreach ($resourceChecks as [$name, $expected]) {
    $out = runReturn([$jinx, 'oracle-call', $name, 'fp:tmp'], $code);
    if ($code !== 0 || $out !== $expected) {
        failReturn("{$name}(stream) expected {$expected}, got {$out}");
    }
}

/* class_alias is intentionally not claimed until the user-class registry is wired. */
$out = runReturn([$jinx, 'oracle-call', 'class_alias', 's:stdClass', 's:JinxAliasProbe'], $code);
if ($code === 0 || !str_contains($out, 'null/fault: class_alias')) {
    failReturn("class_alias is still being counted as implemented via a fabricated false result: {$out}");
}

echo "PASS: native Oracle return contracts reject invalid argument paths, preserve legitimate nulls, and classify stream resources correctly" . PHP_EOL;
