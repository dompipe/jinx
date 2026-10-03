<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: literal_include literal_require resolved_oracle_edge included_local_scope included_output

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleIncludeExecutor.php';

use jinx\oracle\OracleIncludeExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-include-require.php';

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
    $__jinx_capture_return = null;
    $__jinx_capture_error_class = null;
    $__jinx_capture_error_message = null;

    ob_start();
    try {
        $__jinx_capture_return = require $fixture;
    } catch (Throwable $e) {
        $__jinx_capture_error_class = $e::class;
        $__jinx_capture_error_message = $e->getMessage();
    } finally {
        $__jinx_capture_output = (string) ob_get_clean();
    }

    return [
        'output' => $__jinx_capture_output,
        'return' => $__jinx_capture_return,
        'error_class' => $__jinx_capture_error_class,
        'error_message' => $__jinx_capture_error_message,
    ];
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
        $oracle = OracleIncludeExecutor::execute($program);
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

    echo "PASS: Oracle include/require PHP subset matches PHP error behavior" . PHP_EOL;
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'include-require', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 6) {
    fail('Oracle executed too few include/require ops');
}

$statements = $oracle['program']['statements'] ?? [];
$ops = array_column($statements, 'op');

foreach (['O_INCLUDE', 'O_REQUIRE', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$includeEdge = false;
$requireEdge = false;

foreach ($statements as $statement) {
    if (!is_array($statement)) {
        continue;
    }

    $features = (array) ($statement['features'] ?? []);

    if (($statement['op'] ?? null) === 'O_INCLUDE' && isset($statement['included_oracle_program']) && ($features['literal_target_resolved'] ?? false)) {
        $includeEdge = true;
    }

    if (($statement['op'] ?? null) === 'O_REQUIRE' && isset($statement['included_oracle_program']) && ($features['literal_target_resolved'] ?? false)) {
        $requireEdge = true;
    }
}

if (!$includeEdge) {
    fail('fixture did not produce resolved include Oracle edge');
}

if (!$requireEdge) {
    fail('fixture did not produce resolved require Oracle edge');
}

echo "PASS: Oracle executes include/require PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
