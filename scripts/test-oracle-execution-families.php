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
require_once dirname(__DIR__) . '/runtime/OracleObjectExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExpressionBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleBuiltinBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$families = OracleExecutionFamilies::all();

if (count($families) < 39) {
    fail('expected at least 39 executable Oracle families, found ' . count($families));
}

$requiredFamilies = [
    'straight-line',
    'conditionals',
    'loops',
    'arrays',
    'functions',
    'request-globals',
    'include-require',
    'exit-die',
    'object-basics',
    'ternary-expressions',
    'type-casts',
    'string-builtins',
    'math-builtins',
    'comparison-expressions',
    'boolean-expressions',
    'magic-constants',
    'array-literals',
    'foreach-loops',
    'for-loops',
    'str-replace-builtins',
    'strpos-builtins',
    'explode-builtins',
    'in-array-builtins',
    'array-key-exists-builtins',
    'array-merge-builtins',
    'array-reverse-builtins',
    'array-unique-builtins',
    'json-encode-builtins',
    'hash-builtins',
    'ltrim-builtins',
    'rtrim-builtins',
    'ucfirst-builtins',
    'lcfirst-builtins',
    'strrev-builtins',
    'str-repeat-builtins',
    'str-pad-builtins',
    'array-keys-builtins',
    'array-values-builtins',
    'array-slice-builtins',
];

foreach ($requiredFamilies as $family) {
    if (!isset($families[$family])) {
        fail("missing {$family} execution family");
    }
}

foreach ($families as $family => $metadata) {
    if (($metadata['state'] ?? null) !== 'executable') {
        fail("{$family} is not marked executable");
    }

    $owner = $metadata['owner'] ?? null;
    if (!is_string($owner) || $owner === '' || !class_exists($owner)) {
        fail("{$family} owner class is missing or unloaded: " . var_export($owner, true));
    }

    $test = $metadata['test'] ?? null;
    if (!is_string($test) || $test === '' || !is_file($root . '/' . $test)) {
        fail("{$family} comparison test is missing: " . var_export($test, true));
    }

    $ops = $metadata['ops'] ?? null;
    if (!is_array($ops) || $ops === []) {
        fail("{$family} family has no executable ops");
    }

    $hasCoverageDimension = false;
    foreach (['builtins', 'control_flow', 'array_ops', 'function_ops', 'object_ops', 'request_ops', 'loader_ops', 'termination_ops', 'expression_ops', 'casts', 'comparisons', 'boolean_operators', 'magic_constants', 'superglobals'] as $field) {
        if (($metadata[$field] ?? []) !== []) {
            $hasCoverageDimension = true;
            break;
        }
    }

    if (!$hasCoverageDimension) {
        fail("{$family} has no coverage dimension metadata");
    }
}

echo 'PASS: Oracle execution families expose ' . count($families) . ' executable PHP/Zend parity families' . PHP_EOL;
