<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function decodeMathArg(string $arg): mixed
{
    if (strlen($arg) < 2 || $arg[1] !== ':') {
        fail("invalid typed math argument {$arg}");
    }

    return match ($arg[0]) {
        'i' => (int) substr($arg, 2),
        'f' => (float) substr($arg, 2),
        default => fail("unsupported typed math argument {$arg}"),
    };
}

function encodeMathValue(mixed $value): string
{
    if (is_bool($value)) {
        return 'bool:' . ($value ? 'true' : 'false');
    }
    if (is_int($value)) {
        return 'int:' . $value;
    }
    if (is_float($value)) {
        return 'float:' . sprintf('%g', $value);
    }

    fail('unsupported PHP math return type ' . get_debug_type($value));
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

$cases = [
    ['fmod', ['f:5.5', 'f:2']],
    ['intdiv', ['i:7', 'i:2']],
    ['deg2rad', ['f:180']],
    ['rad2deg', ['f:3.141592653589793']],
    ['pi', []],
    ['hypot', ['f:3', 'f:4']],
    ['is_finite', ['f:42']],
    ['is_infinite', ['f:42']],
    ['is_nan', ['f:42']],
    ['cos', ['f:1']],
    ['cosh', ['f:1']],
    ['pow', ['i:2', 'i:8']],
    ['pow', ['i:-1', 'i:20']],
    ['pow', ['i:10', 'i:-1']],
    ['pow', ['i:2', 'i:63']],
    ['pow', ['f:2.5', 'i:3']],
    ['fpow', ['f:2', 'f:8']],
];

foreach ($cases as [$function, $args]) {
    $phpArgs = array_map('decodeMathArg', $args);
    $expected = encodeMathValue($function(...$phpArgs));

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

echo 'PASS: native Oracle ASM math-core and cosine handlers match PHP for covered scalar cases' . PHP_EOL;
