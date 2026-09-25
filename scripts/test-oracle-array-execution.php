<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';

use jinx\oracle\OracleArrayExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-arrays.php';

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

function run_php_fixture(string $fixture): array
{
    $result = [
        'output' => '',
        'return' => null,
        'error_class' => null,
        'error_message' => null,
    ];

    ob_start();
    try {
        $result['return'] = require $fixture;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    } finally {
        $result['output'] = (string) ob_get_clean();
    }

    return $result;
}

function run_oracle_fixture(string $fixture): array
{
    $result = [
        'output' => '',
        'return' => null,
        'error_class' => null,
        'error_message' => null,
        'oracle' => null,
        'program' => null,
    ];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleArrayExecutor::execute($program);
        $result['program'] = $program;
        $result['oracle'] = $oracle;
        $result['output'] = $oracle['output'] ?? null;
        $result['return'] = $oracle['return'] ?? null;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    }

    return $result;
}

$php = run_php_fixture($fixture);
$oracle = run_oracle_fixture($fixture);

same($oracle['error_class'], $php['error_class'], 'Oracle error class matches PHP');

if ($php['error_class'] !== null) {
    if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
        fail('Oracle error message does not include PHP error message: PHP=' . var_export($php['error_message'], true) . ', Oracle=' . var_export($oracle['error_message'], true));
    }

    echo "PASS: Oracle array PHP subset matches PHP error behavior" . PHP_EOL;
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'arrays', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 12) {
    fail('Oracle executed too few array ops');
}

$ops = array_column($oracle['program']['statements'] ?? [], 'op');

foreach (['O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_UNSET', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$appendOracle = OracleArrayExecutor::execute([
    'kind' => 'JINX_ORACLE_PROGRAM',
    'statements' => [
        ['op' => 'O_ASSIGN', 'source' => '$items = [];'],
        ['op' => 'O_DIM_ASSIGN', 'source' => "$items[] = 'first';"],
        ['op' => 'O_DIM_ASSIGN', 'source' => "$items[] = 'second';"],
        ['op' => 'O_RETURN', 'source' => 'return $items[1];'],
    ],
]);

$appendPhp = (static function (): string {
    $items = [];
    $items[] = 'first';
    $items[] = 'second';

    return $items[1];
})();

same($appendOracle['return'] ?? null, $appendPhp, 'Oracle append return matches PHP');
same($appendOracle['family'] ?? null, 'arrays', 'Oracle append family');

echo "PASS: Oracle executes array PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
