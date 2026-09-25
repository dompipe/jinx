<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PASM.php';

use jinx\pasm\PASM;

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 100000;

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

function printRow(array $row, float $baseMs): void
{
    $ratio = $baseMs > 0 ? $row['elapsed_ms'] / $baseMs : 0.0;

    printf(
        "%-28s %10s %14.3f %16.1f %12.2fx\n",
        $row['label'],
        (string) $row['last'],
        $row['elapsed_ms'],
        $row['per_op_ns'],
        $ratio
    );
}

echo "Closure speed benchmark" . PHP_EOL;
echo "Iterations: {$iterations}" . PHP_EOL;
echo PHP_EOL;

$directInline = bench('raw inline expression', $iterations, static function (): int {
    return 2 + 3;
});

$directClosure = static fn(): int => 2 + 3;

$closureCall = bench('direct closure call', $iterations, static function () use ($directClosure): int {
    return $directClosure();
});

$capturedX = 2;
$capturedY = 3;

$capturedClosure = static fn(): int => $capturedX + $capturedY;

$capturedClosureCall = bench('captured closure call', $iterations, static function () use ($capturedClosure): int {
    return $capturedClosure();
});

$oracleClosure = static function (): mixed {
    return PASM::start()
        ->mov('AH', 2)
        ->mov('LOCAL:x', 'AH')
        ->mov('AH', 3)
        ->mov('LOCAL:y', 'AH')
        ->mov('ECX', 'LOCAL:x')
        ->mov('AH', 'LOCAL:y')
        ->add('RDX', 'ECX', 'AH')
        ->ret('RDX')
        ->end();
};

$oracleClosureCall = bench('oracle PASM closure call', $iterations, $oracleClosure);

$specializedOracleClosure = static function (): int {
    // Same final computation after compile-time folding of this tiny example.
    // This represents what the compiler can emit when it proves constants.
    return 5;
};

$specializedOracleCall = bench('specialized folded closure', $iterations, $specializedOracleClosure);

$rows = [
    $directInline,
    $closureCall,
    $capturedClosureCall,
    $oracleClosureCall,
    $specializedOracleCall,
];

foreach ($rows as $row) {
    if ($row['last'] !== 5) {
        fwrite(STDERR, "FAIL: {$row['label']} expected 5, got " . var_export($row['last'], true) . PHP_EOL);
        exit(1);
    }
}

printf(
    "%-28s %10s %14s %16s %12s\n",
    "engine",
    "result",
    "elapsed ms",
    "per op ns",
    "ratio"
);

echo str_repeat("-", 88) . PHP_EOL;

$baseMs = $directInline['elapsed_ms'];

foreach ($rows as $row) {
    printRow($row, $baseMs);
}

echo PHP_EOL;
echo "PASS: closure speed benchmark completed successfully" . PHP_EOL;
