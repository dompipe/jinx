<?php

declare(strict_types=1);

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 1000000;

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
        "%-38s %10s %14.3f %16.1f %12.2fx\n",
        $row['label'],
        (string) $row['last'],
        $row['elapsed_ms'],
        $row['per_op_ns'],
        $ratio
    );
}

$raw = bench('raw PHP expression', $iterations, static function (): int {
    return 2 + 3;
});

$literalPasmShape = bench('literal PASM register traffic', $iterations, static function (): int {
    $AH = 2;
    $LOCAL_x = $AH;

    $AH = 3;
    $LOCAL_y = $AH;

    $ECX = $LOCAL_x;
    $AH = $LOCAL_y;

    $RDX = $ECX + $AH;

    return $RDX;
});

$coalescedLocals = bench('coalesced locals', $iterations, static function (): int {
    $x = 2;
    $y = 3;

    return $x + $y;
});

$coalescedExpression = bench('coalesced expression', $iterations, static function (): int {
    return 2 + 3;
});

$folded = bench('constant folded', $iterations, static function (): int {
    return 5;
});

$rows = [
    $raw,
    $literalPasmShape,
    $coalescedLocals,
    $coalescedExpression,
    $folded,
];

foreach ($rows as $row) {
    if ($row['last'] !== 5) {
        fwrite(STDERR, "FAIL: {$row['label']} expected 5, got " . var_export($row['last'], true) . PHP_EOL);
        exit(1);
    }
}

echo "Optimized PASM forms benchmark" . PHP_EOL;
echo "Iterations: {$iterations}" . PHP_EOL;
echo PHP_EOL;

printf(
    "%-38s %10s %14s %16s %12s\n",
    "engine",
    "result",
    "elapsed ms",
    "per op ns",
    "ratio"
);

echo str_repeat("-", 102) . PHP_EOL;

$baseMs = $raw['elapsed_ms'];

foreach ($rows as $row) {
    printRow($row, $baseMs);
}

echo PHP_EOL;
echo "PASS: optimized PASM forms benchmark completed successfully" . PHP_EOL;
