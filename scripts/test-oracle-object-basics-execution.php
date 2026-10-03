<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: class_declaration constructor_call method_call this_property_write this_property_fetch method_return

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectExecutor.php';

use jinx\oracle\OracleObjectExecutor;
use jinx\oracle\OracleProgramCompiler;

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

function run_php_object_fixture(string $fixture): array
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

function run_oracle_object_fixture(string $fixture): array
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
        $oracle = OracleObjectExecutor::execute($program);
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

$fixture = dirname(__DIR__) . '/fixtures/oracle-executable-object-basics.php';
$php = run_php_object_fixture($fixture);
$oracle = run_oracle_object_fixture($fixture);

same($oracle['error_class'], $php['error_class'], 'Oracle error class matches PHP');

if ($php['error_class'] !== null) {
    if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
        fail('Oracle error message does not include PHP error message');
    }
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'object-basics', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 5) {
    fail('Oracle executed too few object ops');
}

$ops = array_column($oracle['program']['statements'] ?? [], 'op');
foreach (['O_CLASS_DECL', 'O_METHOD_DECL', 'O_NEW', 'O_METHOD_CALL', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$source = (string) file_get_contents($fixture);
foreach (['$this->name', '$this->total', 'new ScoreCard', '->add(', '->label('] as $needle) {
    if (!str_contains($source, $needle)) {
        fail("fixture did not contain expected object source {$needle}");
    }
}

echo "PASS: Oracle executes object basics PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
