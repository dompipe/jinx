<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';

use jinx\oracle\OracleFunctionExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-functions.php';

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
        $oracle = OracleFunctionExecutor::execute($program);
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

    echo "PASS: Oracle function PHP subset matches PHP error behavior" . PHP_EOL;
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['output'], 'JINX:12:4:6', 'Oracle named/default/variadic output');
same($oracle['return'], 'JINX:12:4:6', 'Oracle named/default/variadic return');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'functions', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 1) {
    fail('Oracle did not execute any function ops');
}

$ops = array_column($oracle['program']['statements'] ?? [], 'op');

foreach (['O_FUNCTION_DECL', 'O_ASSIGN', 'O_RETURN', 'O_ECHO'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$functionDeclCount = 0;
foreach ($ops as $op) {
    if ($op === 'O_FUNCTION_DECL') {
        $functionDeclCount++;
    }
}

if ($functionDeclCount < 4) {
    fail('fixture did not produce the expected four function declarations');
}

foreach (['add_score(', 'label_score(', 'sum_tail($head, ...$tail)', 'sum_tail(1, 2, 3)', 'build_score($name, $base = 7, $bonus = 5)', "build_score(name: 'jinx')", 'strlen(', 'strtoupper('] as $needle) {
    $found = false;

    foreach ($oracle['program']['statements'] ?? [] as $statement) {
        if (str_contains((string) ($statement['source'] ?? ''), $needle)) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        fail("fixture did not include expected function evidence {$needle}");
    }
}

echo "PASS: Oracle executes function PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
