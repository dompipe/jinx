<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-straightline.php';

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

$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
$oracle = OracleProgramCompiler::executeSupportedOracleProgram($program);

ob_start();
$phpReturn = require $fixture;
$phpOutput = (string) ob_get_clean();

same($oracle['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['output'] ?? null, $phpOutput, 'Oracle output matches PHP');
same($oracle['return'] ?? null, $phpReturn, 'Oracle return matches PHP');
same($oracle['return'] ?? null, 24, 'Oracle local/dimension coalesce assignment result');

if (($oracle['executed_ops'] ?? 0) < 13) {
    fail('Oracle executed too few straight-line ops');
}

$ops = array_column($program['statements'] ?? [], 'op');

foreach (['O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_COALESCE_ASSIGN', 'O_COALESCE', 'O_ECHO', 'O_PRINT', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

echo "PASS: Oracle executes straight-line PHP subset and matches PHP output/return" . PHP_EOL;
