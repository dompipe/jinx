<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;

$root = dirname(__DIR__);

$cases = [
    [
        'name' => 'simple-add',
        'php_file' => $root . '/fixtures/simple-add.php',
        'expected' => 5,
    ],
    [
        'name' => 'simple-sub',
        'php_file' => $root . '/fixtures/simple-sub.php',
        'expected' => 5,
    ],
    [
        'name' => 'simple-mul',
        'php_file' => $root . '/fixtures/simple-mul.php',
        'expected' => 42,
    ],
    [
        'name' => 'negative-add',
        'php_file' => $root . '/fixtures/negative-add.php',
        'expected' => 7,
    ],
    [
        'name' => 'strlen',
        'php_file' => $root . '/fixtures/strlen.php',
        'expected' => 6,
    ],
];

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 10000;

function bench(string $label, int $iterations, callable $fn): array
{
    $start = hrtime(true);
    $last = null;

    for ($i = 0; $i < $iterations; $i++) {
        $last = $fn();
    }

    $elapsedNs = hrtime(true) - $start;
    $elapsedMs = $elapsedNs / 1_000_000;
    $perOpUs = ($elapsedNs / $iterations) / 1_000;

    return [
        'label' => $label,
        'last' => $last,
        'elapsed_ms' => $elapsedMs,
        'per_op_us' => $perOpUs,
    ];
}

function runNativePhp(string $path): mixed
{
    return require $path;
}

function runJinx(string $path): mixed
{
    $jinx = PhpToJinxLowerer::lowerFile($path);
    return JinxToPasmLowerer::run($jinx);
}

echo "JINX vs regular PHP benchmark" . PHP_EOL;
echo "Iterations per case: {$iterations}" . PHP_EOL;
echo PHP_EOL;

printf(
    "%-16s %-14s %12s %14s %14s %12s\n",
    "case",
    "engine",
    "result",
    "elapsed ms",
    "per op us",
    "ratio"
);

echo str_repeat("-", 88) . PHP_EOL;

foreach ($cases as $case) {
    $native = bench(
        $case['name'] . ' native',
        $iterations,
        fn() => runNativePhp($case['php_file'])
    );

    $jinx = bench(
        $case['name'] . ' jinx',
        $iterations,
        fn() => runJinx($case['php_file'])
    );

    if ($native['last'] !== $case['expected']) {
        fwrite(STDERR, "FAIL: native {$case['name']} expected {$case['expected']}, got " . var_export($native['last'], true) . PHP_EOL);
        exit(1);
    }

    if ($jinx['last'] !== $case['expected']) {
        fwrite(STDERR, "FAIL: jinx {$case['name']} expected {$case['expected']}, got " . var_export($jinx['last'], true) . PHP_EOL);
        exit(1);
    }

    $ratio = $native['elapsed_ms'] > 0
        ? $jinx['elapsed_ms'] / $native['elapsed_ms']
        : 0.0;

    printf(
        "%-16s %-14s %12s %14.3f %14.3f %12s\n",
        $case['name'],
        "php",
        (string) $native['last'],
        $native['elapsed_ms'],
        $native['per_op_us'],
        "1.00x"
    );

    printf(
        "%-16s %-14s %12s %14.3f %14.3f %11.2fx\n",
        $case['name'],
        "jinx",
        (string) $jinx['last'],
        $jinx['elapsed_ms'],
        $jinx['per_op_us'],
        $ratio
    );

    echo PHP_EOL;
}

echo "PASS: benchmark completed successfully" . PHP_EOL;
