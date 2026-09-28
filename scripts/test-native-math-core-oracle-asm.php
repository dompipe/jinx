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
    ['abs', ['i:-42']],
    ['abs', ['i:-9223372036854775808']],
    ['abs', ['f:-1.25']],
    ['fmod', ['f:5.5', 'f:2']],
    ['fdiv', ['f:7', 'f:2']],
    ['fdiv', ['f:-7', 'f:2']],
    ['log1p', ['f:0.0000001']],
    ['log1p', ['f:1']],
    ['round', ['f:0.285', 'i:2', 'i:1']],
    ['round', ['f:1.5', 'i:0', 'i:1']],
    ['round', ['f:1.5', 'i:0', 'i:2']],
    ['round', ['f:2.5', 'i:0', 'i:3']],
    ['round', ['f:3.5', 'i:0', 'i:3']],
    ['round', ['f:2.5', 'i:0', 'i:4']],
    ['round', ['f:3.5', 'i:0', 'i:4']],
    ['round', ['f:1.1', 'i:0', 'i:5']],
    ['round', ['f:-1.1', 'i:0', 'i:5']],
    ['round', ['f:1.1', 'i:0', 'i:6']],
    ['round', ['f:-1.1', 'i:0', 'i:6']],
    ['round', ['f:1.9', 'i:0', 'i:7']],
    ['round', ['f:-1.9', 'i:0', 'i:7']],
    ['round', ['f:1.1', 'i:0', 'i:8']],
    ['round', ['f:-1.1', 'i:0', 'i:8']],
    ['round', ['f:1245', 'i:-1', 'i:1']],
    ['round', ['i:42', 'i:2', 'i:1']],
    ['intdiv', ['i:7', 'i:2']],
    ['deg2rad', ['f:180']],
    ['rad2deg', ['f:3.141592653589793']],
    ['pi', []],
    ['hypot', ['f:3', 'f:4']],
    ['is_finite', ['f:42']],
    ['is_infinite', ['f:42']],
    ['is_nan', ['f:42']],
    ['acos', ['f:0.5']],
    ['acosh', ['f:2']],
    ['asin', ['f:0.5']],
    ['asinh', ['f:1']],
    ['atan', ['f:1']],
    ['atan2', ['f:1', 'f:2']],
    ['atanh', ['f:0.5']],
    ['ceil', ['f:1.25']],
    ['floor', ['f:1.75']],
    ['sqrt', ['f:2']],
    ['sin', ['f:1']],
    ['sinh', ['f:1']],
    ['tan', ['f:1']],
    ['tanh', ['f:1']],
    ['cos', ['f:1']],
    ['cosh', ['f:1']],
    ['exp', ['f:1']],
    ['expm1', ['f:0.0000001']],
    ['log', ['f:2']],
    ['log10', ['f:1000']],
    ['pow', ['i:2', 'i:8']],
    ['pow', ['i:-2', 'i:3']],
    ['pow', ['i:-1', 'i:20']],
    ['pow', ['i:10', 'i:-1']],
    ['pow', ['i:2', 'i:63']],
    ['pow', ['f:2.5', 'i:3']],
    ['fpow', ['f:2', 'f:8']],
    ['max', ['i:2', 'f:3.5', 'i:3']],
    ['min', ['f:2.5', 'i:-4', 'f:-3.5']],
    ['max', ['i:4', 'f:4', 'i:3']],
    ['min', ['f:4', 'i:4', 'f:5']],
    ['round', ['f:5.045', 'i:2']],
    ['round', ['f:5.055', 'i:2']],
    ['round', ['i:345', 'i:-2']],
    ['round', ['i:678', 'i:-3']],
    ['round', ['f:9.5', 'i:0', 'i:1']],
    ['round', ['f:9.5', 'i:0', 'i:2']],
    ['round', ['f:8.5', 'i:0', 'i:3']],
    ['round', ['f:8.5', 'i:0', 'i:4']],
    ['round', ['f:-1.55', 'i:1', 'i:1']],
    ['round', ['f:-1.55', 'i:1', 'i:2']],
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
echo 'PASS: native Oracle ASM math-core builtins execute' . PHP_EOL;
