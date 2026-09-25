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
require_once dirname(__DIR__) . '/runtime/OracleObjectInheritanceExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExpressionBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleBuiltinBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleScalarBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleAppBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDataBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleTextBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDateTimeBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIntrospectionBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRegexStringBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayMutationBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleSecurityNetworkBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRuntimeInfoBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$families = OracleExecutionFamilies::all();

if (count($families) < 141) {
    fail('expected at least 141 executable Oracle families, found ' . count($families));
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
    foreach (['builtins', 'control_flow', 'array_ops', 'function_ops', 'object_ops', 'request_ops', 'loader_ops', 'termination_ops', 'expression_ops', 'casts', 'comparisons', 'boolean_operators', 'magic_constants', 'superglobals', 'string_ops'] as $field) {
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
