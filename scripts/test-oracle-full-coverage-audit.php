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
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$families = OracleExecutionFamilies::all();
$nativeSuite = (string) file_get_contents($root . '/scripts/test-jinx-native-suite.php');
$docs = (string) file_get_contents($root . '/docs/ORACLE_EXECUTION_COMMANDS.md');

$testToFamilies = [];

foreach ($families as $family => $metadata) {
    $test = $metadata['test'] ?? null;
    $owner = $metadata['owner'] ?? null;

    if (!is_string($test) || $test === '') {
        fail("{$family} missing comparison test metadata");
    }
    if (!is_file($root . '/' . $test)) {
        fail("{$family} comparison test file does not exist: {$test}");
    }
    if (!str_contains($nativeSuite, $test)) {
        fail("{$family} comparison test is not wired into native suite: {$test}");
    }
    if (!str_contains($docs, "`{$family}`")) {
        fail("{$family} is not documented in ORACLE_EXECUTION_COMMANDS.md");
    }
    if (!str_contains($docs, "`{$test}`")) {
        fail("{$family} test path is not documented in ORACLE_EXECUTION_COMMANDS.md: {$test}");
    }
    if (!is_string($owner) || !class_exists($owner)) {
        fail("{$family} owner class is not loadable: " . var_export($owner, true));
    }

    $testToFamilies[$test][] = $family;
}

foreach ($testToFamilies as $test => $coveredFamilies) {
    if (count($coveredFamilies) === 1) {
        continue;
    }

    $contents = (string) file_get_contents($root . '/' . $test);
    foreach ($coveredFamilies as $family) {
        if (!str_contains($contents, "'{$family}'") && !str_contains($contents, '"' . $family . '"')) {
            fail("{$test} batch test does not explicitly enumerate family {$family}");
        }
    }
}

if (count($families) < 141) {
    fail('coverage audit expected at least 141 executable families');
}

$distinctTests = array_keys($testToFamilies);
sort($distinctTests);

echo 'PASS: Oracle full coverage audit validates ' . count($families) . ' executable families across ' . count($distinctTests) . ' PHP parity tests' . PHP_EOL;
