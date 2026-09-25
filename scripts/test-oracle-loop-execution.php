<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';

use jinx\oracle\OracleLoopExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-loops.php';

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

/**
 * @return array{output:string,return:mixed,error:?string}
 */
function capture_php_fixture(string $fixture): array
{
    $return = null;
    $error = null;
    ob_start();

    try {
        $return = require $fixture;
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    $output = (string) ob_get_clean();

    return [
        'output' => $output,
        'return' => $return,
        'error' => $error,
    ];
}

/**
 * @param array<string,mixed> $program
 * @return array{output:string,return:mixed,error:?string,family:?string,executed_ops:int}
 */
function capture_oracle_program(array $program): array
{
    try {
        $oracle = OracleLoopExecutor::execute($program);

        return [
            'output' => (string) ($oracle['output'] ?? ''),
            'return' => $oracle['return'] ?? null,
            'error' => null,
            'family' => $oracle['family'] ?? null,
            'executed_ops' => (int) ($oracle['executed_ops'] ?? 0),
        ];
    } catch (Throwable $e) {
        return [
            'output' => '',
            'return' => null,
            'error' => get_class($e) . ': ' . $e->getMessage(),
            'family' => null,
            'executed_ops' => 0,
        ];
    }
}

$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
$php = capture_php_fixture($fixture);
$oracle = capture_oracle_program($program);

same($oracle['error'], $php['error'], 'Oracle error behavior matches PHP');
same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['family'], 'loops', 'Oracle family');

if ($oracle['executed_ops'] < 12) {
    fail('Oracle executed too few loop-family ops');
}

$ops = array_column($program['statements'] ?? [], 'op');

foreach (['O_WHILE', 'O_IF', 'O_CONTINUE', 'O_BREAK', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

echo "PASS: Oracle executes loop PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
