<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRequestExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIncludeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExitExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleNextTenExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleArrayExecutor;
use jinx\oracle\OracleConditionalExecutor;
use jinx\oracle\OracleExecutionFamilies;
use jinx\oracle\OracleExitExecutor;
use jinx\oracle\OracleFunctionExecutor;
use jinx\oracle\OracleIncludeExecutor;
use jinx\oracle\OracleLoopExecutor;
use jinx\oracle\OracleNextTenExecutor;
use jinx\oracle\OracleRequestExecutor;
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

$expected = [
    'straight-line' => [OracleStraightLineExecutor::class, 'scripts/test-oracle-straightline-execution.php'],
    'conditionals' => [OracleConditionalExecutor::class, 'scripts/test-oracle-conditional-execution.php'],
    'loops' => [OracleLoopExecutor::class, 'scripts/test-oracle-loop-execution.php'],
    'arrays' => [OracleArrayExecutor::class, 'scripts/test-oracle-array-execution.php'],
    'functions' => [OracleFunctionExecutor::class, 'scripts/test-oracle-function-execution.php'],
    'request-globals' => [OracleRequestExecutor::class, 'scripts/test-oracle-request-globals-execution.php'],
    'include-require' => [OracleIncludeExecutor::class, 'scripts/test-oracle-include-require-execution.php'],
    'exit-die' => [OracleExitExecutor::class, 'scripts/test-oracle-exit-die-execution.php'],
    'ternary-expressions' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'type-casts' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'string-builtins' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'math-builtins' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'comparison-expressions' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'boolean-expressions' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'magic-constants' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'array-literals' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'foreach-loops' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
    'for-loops' => [OracleNextTenExecutor::class, 'scripts/test-oracle-next-ten-execution.php'],
];

$families = OracleExecutionFamilies::all();

foreach ($expected as $family => [$owner, $test]) {
    if (!isset($families[$family])) {
        fail("missing {$family} execution family");
    }

    $metadata = OracleExecutionFamilies::get($family);
    same($metadata['state'] ?? null, 'executable', "{$family} state");
    same($metadata['owner'] ?? null, $owner, "{$family} owner");
    same($metadata['test'] ?? null, $test, "{$family} test");

    if (($metadata['ops'] ?? []) === []) {
        fail("{$family} family has no executable ops");
    }
}

$checks = [
    ['arrays', 'array_ops', ['literal_empty_array', 'append', 'unset']],
    ['functions', 'function_ops', ['named_user_function', 'return_value', 'builtin_dispatch']],
    ['request-globals', 'superglobals', ['$_SERVER', '$_GET', '$_POST', '$_REQUEST']],
    ['include-require', 'loader_ops', ['literal_include', 'literal_require', 'resolved_oracle_edge']],
    ['exit-die', 'termination_ops', ['exit_string_output', 'die_alias', 'termination_flag']],
    ['ternary-expressions', 'expression_ops', ['ternary_true_branch', 'ternary_false_branch']],
    ['type-casts', 'casts', ['int', 'string', 'bool']],
    ['string-builtins', 'builtins', ['strlen', 'strtolower', 'trim', 'substr']],
    ['math-builtins', 'builtins', ['abs', 'max', 'min', 'round']],
    ['comparison-expressions', 'comparisons', ['!==', '<=>', '>']],
    ['boolean-expressions', 'boolean_operators', ['&&', '||', '!']],
    ['magic-constants', 'magic_constants', ['__FILE__', '__DIR__', 'PHP_VERSION']],
    ['array-literals', 'array_ops', ['list_literal', 'assoc_literal']],
    ['foreach-loops', 'control_flow', ['foreach_key_value', 'foreach_value_scope']],
    ['for-loops', 'control_flow', ['for_init', 'for_condition', 'for_iteration']],
];

foreach ($checks as [$family, $field, $values]) {
    $metadata = OracleExecutionFamilies::get($family);
    foreach ($values as $value) {
        if (!in_array($value, $metadata[$field] ?? [], true)) {
            fail("{$family} family missing {$field} value {$value}");
        }
    }
}

echo "PASS: Oracle execution families expose the base families and next ten executable families" . PHP_EOL;
