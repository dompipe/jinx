<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';
require_once dirname(__DIR__) . '/runtime/CoalescedOracleCompiler.php';
require_once dirname(__DIR__) . '/runtime/PASM.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;
use jinx\oracle\CoalescedOracleCompiler;

$root = dirname(__DIR__);
$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 100000;

$cases = [
    ['simple-add', $root . '/fixtures/simple-add.php', 5],
    ['simple-sub', $root . '/fixtures/simple-sub.php', 5],
    ['simple-mul', $root . '/fixtures/simple-mul.php', 42],
    ['negative-add', $root . '/fixtures/negative-add.php', 7],
    ['strlen', $root . '/fixtures/strlen.php', 6],
];

function bench(string $label, int $iterations, callable $fn): array
{
    $start = hrtime(true);
    $last = null;

    for ($i = 0; $i < $iterations; $i++) {
        $last = $fn();
    }

    $elapsedNs = hrtime(true) - $start;

    return [
        'label' => $label,
        'last' => $last,
        'elapsed_ms' => $elapsedNs / 1_000_000,
        'per_op_ns' => $elapsedNs / $iterations,
    ];
}

function printRow(string $case, array $row, float $baseMs): void
{
    $ratio = $baseMs > 0 ? $row['elapsed_ms'] / $baseMs : 0.0;

    printf(
        "%-14s %-24s %10s %14.3f %16.1f %12.2fx\n",
        $case,
        $row['label'],
        (string) $row['last'],
        $row['elapsed_ms'],
        $row['per_op_ns'],
        $ratio
    );
}

echo "Coalesced Oracle vs PHP benchmark" . PHP_EOL;
echo "Iterations per case: {$iterations}" . PHP_EOL;
echo PHP_EOL;

printf(
    "%-14s %-24s %10s %14s %16s %12s\n",
    "case",
    "engine",
    "result",
    "elapsed ms",
    "per op ns",
    "ratio"
);

echo str_repeat("-", 98) . PHP_EOL;

foreach ($cases as [$name, $file, $expected]) {
    $jinx = PhpToJinxLowerer::lowerFile($file);

    $literalPasm = static fn(): mixed => JinxToPasmLowerer::run($jinx);

    $coalescedOps = CoalescedOracleCompiler::compileJinx($jinx);
    $coalesced = static fn(): mixed => CoalescedOracleCompiler::execute($coalescedOps);

    $coalescedClosure = CoalescedOracleCompiler::compileJinxToClosure($jinx);

    $native = bench('php-require', $iterations, static fn(): mixed => require $file);
    $literal = bench('literal-pasm', $iterations, $literalPasm);
    $coal = bench('coalesced-oracle', $iterations, $coalesced);
    $coalClosure = bench('coalesced-closure', $iterations, $coalescedClosure);

    foreach ([$native, $literal, $coal, $coalClosure] as $row) {
        if ($row['last'] !== $expected) {
            fwrite(
                STDERR,
                "FAIL: {$name} {$row['label']} expected {$expected}, got " .
                var_export($row['last'], true) .
                PHP_EOL
            );
            exit(1);
        }
    }

    $baseMs = $native['elapsed_ms'];

    printRow($name, $native, $baseMs);
    printRow($name, $literal, $baseMs);
    printRow($name, $coal, $baseMs);
    printRow($name, $coalClosure, $baseMs);

    echo PHP_EOL;
}

echo "PASS: coalesced Oracle benchmark completed successfully" . PHP_EOL;
