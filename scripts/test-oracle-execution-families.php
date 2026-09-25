<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRequestExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIncludeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleArrayExecutor;
use jinx\oracle\OracleConditionalExecutor;
use jinx\oracle\OracleExecutionFamilies;
use jinx\oracle\OracleFunctionExecutor;
use jinx\oracle\OracleIncludeExecutor;
use jinx\oracle\OracleLoopExecutor;
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

$families = OracleExecutionFamilies::all();

foreach (['straight-line', 'conditionals', 'loops', 'arrays', 'functions', 'request-globals', 'include-require'] as $family) {
    if (!isset($families[$family])) {
        fail("missing {$family} execution family");
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
];

foreach ($expected as $family => [$owner, $test]) {
    $metadata = OracleExecutionFamilies::get($family);
    same($metadata['state'] ?? null, 'executable', "{$family} state");
    same($metadata['owner'] ?? null, $owner, "{$family} owner");
    same($metadata['test'] ?? null, $test, "{$family} test");
}

$requiredOps = [
    'straight-line' => ['O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
    'conditionals' => ['O_IF', 'O_ELSE', 'O_BLOCK_CLOSE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
    'loops' => ['O_WHILE', 'O_BREAK', 'O_CONTINUE', 'O_IF', 'O_BLOCK_CLOSE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_ECHO', 'O_RETURN'],
    'arrays' => ['O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_UNSET', 'O_ECHO', 'O_RETURN'],
    'functions' => ['O_FUNCTION_DECL', 'O_ASSIGN', 'O_COMPOUND_ASSIGN', 'O_ECHO', 'O_RETURN', 'O_BLOCK_CLOSE'],
    'request-globals' => ['O_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_ECHO', 'O_RETURN'],
    'include-require' => ['O_INCLUDE', 'O_REQUIRE', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'],
];

foreach ($requiredOps as $family => $ops) {
    $metadata = OracleExecutionFamilies::get($family);
    foreach ($ops as $op) {
        if (!in_array($op, $metadata['ops'] ?? [], true)) {
            fail("{$family} family missing {$op}");
        }
    }
}

foreach (['strlen', 'strtoupper'] as $builtin) {
    foreach (['straight-line', 'conditionals', 'loops', 'functions'] as $family) {
        $metadata = OracleExecutionFamilies::get($family);
        if (!in_array($builtin, $metadata['builtins'] ?? [], true)) {
            fail("{$family} family missing builtin {$builtin}");
        }
    }
}

$arrays = OracleExecutionFamilies::get('arrays');
foreach (['literal_empty_array', 'append', 'nested_dimension_assign', 'nested_dimension_fetch', 'isset', 'empty', 'unset'] as $arrayOp) {
    if (!in_array($arrayOp, $arrays['array_ops'] ?? [], true)) {
        fail("arrays family missing array op {$arrayOp}");
    }
}

$functions = OracleExecutionFamilies::get('functions');
foreach (['named_user_function', 'local_parameter_scope', 'return_value', 'nested_user_call', 'builtin_dispatch'] as $functionOp) {
    if (!in_array($functionOp, $functions['function_ops'] ?? [], true)) {
        fail("functions family missing function op {$functionOp}");
    }
}

$requestGlobals = OracleExecutionFamilies::get('request-globals');
foreach (['$_SERVER', '$_GET', '$_POST', '$_REQUEST'] as $superglobal) {
    if (!in_array($superglobal, $requestGlobals['superglobals'] ?? [], true)) {
        fail("request-globals family missing superglobal {$superglobal}");
    }
}

foreach (['request_context', 'query_params', 'post_params', 'request_params', 'server_params', 'isset', 'empty'] as $requestOp) {
    if (!in_array($requestOp, $requestGlobals['request_ops'] ?? [], true)) {
        fail("request-globals family missing request op {$requestOp}");
    }
}

foreach (['count'] as $builtin) {
    foreach (['arrays', 'request-globals'] as $family) {
        $metadata = OracleExecutionFamilies::get($family);
        if (!in_array($builtin, $metadata['builtins'] ?? [], true)) {
            fail("{$family} family missing builtin {$builtin}");
        }
    }
}

$includeRequire = OracleExecutionFamilies::get('include-require');
foreach (['literal_include', 'literal_require', 'resolved_oracle_edge', 'included_local_scope', 'included_output'] as $loaderOp) {
    if (!in_array($loaderOp, $includeRequire['loader_ops'] ?? [], true)) {
        fail("include-require family missing loader op {$loaderOp}");
    }
}

echo "PASS: Oracle execution families expose the straight-line, conditionals, loops, arrays, functions, request-globals, and include-require executable families" . PHP_EOL;
