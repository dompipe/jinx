<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: double_quoted_variable_interpolation braced_variable_interpolation array_offset_interpolation escaped_dollar

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-string-interpolation.php';

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
same($oracle['output'] ?? null, $phpOutput, 'Oracle interpolated output matches PHP');
same($oracle['return'] ?? null, $phpReturn, 'Oracle interpolated return matches PHP');

$expectedFragments = [
    'Hello JINX, Oracle flies with Oracle #7',
    'Dollar stays: $name',
    'Simple offset: Oracle / 7',
];

foreach ($expectedFragments as $fragment) {
    if (!str_contains($phpOutput, $fragment) || !str_contains((string) ($oracle['output'] ?? ''), $fragment)) {
        fail('missing interpolated output fragment: ' . $fragment);
    }
}

same($phpReturn, 'Return JINX Oracle Oracle 7', 'PHP return sanity');

$ops = array_column($program['statements'] ?? [], 'op');
foreach (['O_ASSIGN', 'O_DIM_ASSIGN', 'O_PRINT', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

echo "PASS: Oracle executes PHP string interpolation and matches PHP output/return" . PHP_EOL;
