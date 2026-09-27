<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function decodeArg(string $arg): mixed
{
    if ($arg === 'null') return null;
    if (strlen($arg) < 2 || $arg[1] !== ':') fail("invalid typed arg {$arg}");

    return match ($arg[0]) {
        'i' => (int) substr($arg, 2),
        'f' => (float) substr($arg, 2),
        'b' => in_array(substr($arg, 2), ['1', 'true'], true),
        's' => substr($arg, 2),
        'a' => array_fill(0, max(0, (int) substr($arg, 2)), null),
        default => fail("unsupported typed arg {$arg}"),
    };
}

function encodeValue(mixed $value): string
{
    if ($value === null) return 'null';
    if (is_bool($value)) return 'bool:' . ($value ? 'true' : 'false');
    if (is_int($value)) return 'int:' . $value;
    if (is_float($value)) return 'float:' . sprintf('%g', $value);
    if (is_string($value)) return 'string:' . $value;
    fail('unsupported return type ' . get_debug_type($value));
}

function phpCall(string $function, array $args): mixed
{
    set_error_handler(static fn (): bool => true);
    try {
        return $function(...$args);
    } finally {
        restore_error_handler();
    }
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

$longNumeric = str_repeat('0', 160) . '42';

$cases = [
    ['is_null', ['null']],
    ['is_bool', ['b:true']],
    ['is_int', ['i:42']],
    ['is_integer', ['i:42']],
    ['is_float', ['f:4.25']],
    ['is_double', ['f:4.25']],
    ['is_string', ['s:jinx']],
    ['is_array', ['a:3']],
    ['is_scalar', ['s:jinx']],

    ['is_numeric', ['s:42.5']],
    ['is_numeric', ['s:  +123.45  ']],
    ['is_numeric', ['s:.5']],
    ['is_numeric', ['s:5.']],
    ['is_numeric', ['s:1e3']],
    ['is_numeric', ['s:-2.5E+4']],
    ['is_numeric', ['s:1e']],
    ['is_numeric', ['s:.']],
    ['is_numeric', ['s:12foo']],
    ['is_numeric', ["s:\u{00A0}9001"]],

    ['boolval', ['s:jinx']],
    ['boolval', ['s:0']],
    ['boolval', ['s:']],
    ['boolval', ['a:0']],
    ['boolval', ['a:3']],
    ['boolval', ['null']],

    ['intval', ['s:42']],
    ['intval', ['s:1e3']],
    ['intval', ['s:42.9e1']],
    ['intval', ['s:0x1A']],
    ['intval', ['s:0x1A', 'i:0']],
    ['intval', ['s:0b101', 'i:0']],
    ['intval', ['s:0b101', 'i:2']],
    ['intval', ['s:042', 'i:0']],
    ['intval', ['s:42', 'i:8']],
    ['intval', ['s:1ya', 'i:36']],
    ['intval', ['s:' . $longNumeric]],
    ['intval', ['a:0']],
    ['intval', ['a:3']],
    ['intval', ['f:4.75']],

    ['floatval', ['s:4.25']],
    ['floatval', ['s:1.25e2']],
    ['floatval', ['s:' . $longNumeric]],
    ['floatval', ['a:0']],
    ['floatval', ['a:3']],
    ['doubleval', ['s:4.25']],
    ['doubleval', ['s:1.25e2']],
    ['doubleval', ['a:3']],

    ['strval', ['i:42']],
    ['strval', ['b:true']],
    ['strval', ['null']],
    ['strval', ['a:3']],
];

foreach ($cases as [$function, $args]) {
    $phpArgs = array_map('decodeArg', $args);
    $expected = encodeValue(phpCall($function, $phpArgs));

    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($function);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $actual = rtrim(implode(PHP_EOL, $output), "\r\n");

    if ($code !== 0) {
        fail("oracle-call {$function} failed: {$actual}");
    }

    if ($actual !== $expected) {
        fail("oracle-call {$function} parity mismatch: PHP={$expected}, JINX={$actual}");
    }
}

echo 'PASS: native Oracle ASM scalar-core builtins match PHP for covered JinxValue semantics' . PHP_EOL;
echo 'PASS: native Oracle ASM scalar-core builtins execute' . PHP_EOL;
