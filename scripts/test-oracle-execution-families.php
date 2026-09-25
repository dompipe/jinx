<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleExecutionFamilies;
use jinx\oracle\OracleStraightLineExecutor;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$families = OracleExecutionFamilies::all();

if (!isset($families['straight-line'])) {
    fail('missing straight-line execution family');
}

$straightLine = OracleExecutionFamilies::get('straight-line');

same($straightLine['state'] ?? null, 'executable', 'straight-line state');
same($straightLine['owner'] ?? null, OracleStraightLineExecutor::class, 'straight-line owner');
same($straightLine['test'] ?? null, 'scripts/test-oracle-straightline-execution.php', 'straight-line test');

foreach (['O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_ECHO', 'O_PRINT', 'O_RETURN'] as $op) {
    if (!in_array($op, $straightLine['ops'] ?? [], true)) {
        fail("straight-line family missing {$op}");
    }
}

foreach (['strlen', 'strtoupper'] as $builtin) {
    if (!in_array($builtin, $straightLine['builtins'] ?? [], true)) {
        fail("straight-line family missing builtin {$builtin}");
    }
}

echo "PASS: Oracle execution families expose the straight-line executable family" . PHP_EOL;
