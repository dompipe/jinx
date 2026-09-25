<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';

use jinx\oracle\OracleConditionalExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-conditionals.php';

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

    return [
        'output' => $output,
        'return' => $return,
        'error' => $error,
    ];
}

function captureOracleProgram(array $program): array
{
    try {
        $oracle = OracleConditionalExecutor::execute($program);

        return [
            'result' => $oracle,
            'output' => $oracle['output'] ?? null,
            'return' => $oracle['return'] ?? null,
            'error' => null,
        ];
    } catch (Throwable $e) {
        return [
            'result' => null,
            'output' => null,
            'return' => null,
            'error' => get_class($e) . ': ' . $e->getMessage(),
        ];
    }
}

$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
$php = capturePhpFixture($fixture);
$oracle = captureOracleProgram($program);

same($oracle['error'], $php['error'], 'Oracle error behavior matches PHP');
same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['result']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['result']['family'] ?? null, 'conditionals', 'Oracle execution family');

if (($oracle['result']['executed_ops'] ?? 0) < 8) {
    fail('Oracle executed too few conditional-family ops');
}

$ops = array_column($program['statements'] ?? [], 'op');

foreach (['O_ASSIGN', 'O_IF', 'O_ELSE', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_PRINT', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

echo "PASS: Oracle executes conditional PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
