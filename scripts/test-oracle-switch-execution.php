<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleSwitchExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleSwitchExecutor;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-switch.php';

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

function capturePhpFixture(string $fixture): array
{
    $output = '';
    $return = null;
    $error = null;

    ob_start();
    try {
        $return = require $fixture;
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    } finally {
        $output = (string) ob_get_clean();
    }

    return ['output' => $output, 'return' => $return, 'error' => $error];
}

$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
$oracle = OracleSwitchExecutor::execute($program);
$php = capturePhpFixture($fixture);

same($oracle['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['family'] ?? null, 'switch', 'Oracle execution family');
same($oracle['output'] ?? null, $php['output'], 'Oracle output matches PHP');
same($oracle['return'] ?? null, $php['return'], 'Oracle return matches PHP');

if (($oracle['executed_ops'] ?? 0) < 7) {
    fail('Oracle executed too few switch-family ops');
}

$ops = array_column($program['statements'] ?? [], 'op');
foreach (['O_SWITCH', 'O_CASE', 'O_DEFAULT', 'O_BREAK', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

echo "PASS: Oracle executes switch/case/default PHP subset and matches PHP output/return" . PHP_EOL;
