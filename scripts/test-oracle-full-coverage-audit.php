<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleSwitchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExceptionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForeachExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDoWhileExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGlobalScopeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleStaticLocalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRequestExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIncludeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExitExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleStaticMethodExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectInheritanceExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExpressionBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleBuiltinBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleScalarBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleAppBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDataBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArraySetBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFilesystemBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleTextBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDateTimeBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIntrospectionBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRegexStringBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayMutationBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleSecurityNetworkBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRuntimeInfoBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleJinxIslandServerExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleJinxWebExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleMergedExecutionFamilies.php';

use jinx\oracle\OracleGeneratedExecutionFamilies;
use jinx\oracle\OracleMergedExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$families = OracleMergedExecutionFamilies::all();
$nativeSuite = (string) file_get_contents($root . '/scripts/test-jinx-native-suite.php');
$docs = (string) file_get_contents($root . '/docs/ORACLE_EXECUTION_COMMANDS.md');
$docs .= "\n" . (string) file_get_contents($root . '/docs/ORACLE_ARRAY_SET_BUILTINS.md');
$docs .= "\n" . (string) file_get_contents($root . '/docs/ORACLE_FILESYSTEM_BUILTINS.md');
$docs .= "\n" . (string) file_get_contents($root . '/docs/ORACLE_GENERATED_175_BUILTINS.md');
$docs .= "\n" . (string) file_get_contents($root . '/docs/ORACLE_JINX_ISLAND_SERVER.md');
$generatedLastFamily = sprintf('generated-pure-builtin-%03d', OracleGeneratedExecutionFamilies::TOTAL_GENERATED_FAMILIES);
$generatedRangeMarker = 'generated-pure-builtin-001 through ' . $generatedLastFamily;

$testToFamilies = [];

foreach ($families as $family => $metadata) {
    $test = $metadata['test'] ?? null;
    $owner = $metadata['owner'] ?? null;
    $generatedBatch = $metadata['generated_batch'] ?? null;

    if (!is_string($test) || $test === '') {
        fail("{$family} missing comparison test metadata");
    }
    if (!is_file($root . '/' . $test)) {
        fail("{$family} comparison test file does not exist: {$test}");
    }
    if (!str_contains($nativeSuite, $test)) {
        fail("{$family} comparison test is not wired into native suite: {$test}");
    }

    if (is_string($generatedBatch) && $generatedBatch !== '') {
        if (!str_contains($docs, $generatedRangeMarker)) {
            fail("{$family} generated batch range is not documented in supplemental Oracle docs: {$generatedRangeMarker}");
        }
    } elseif (!str_contains($docs, "`{$family}`")) {
        fail("{$family} is not documented in ORACLE_EXECUTION_COMMANDS.md or supplemental Oracle docs");
    }

    if (!str_contains($docs, "`{$test}`")) {
        fail("{$family} test path is not documented in ORACLE_EXECUTION_COMMANDS.md or supplemental Oracle docs: {$test}");
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
        $metadata = $families[$family] ?? [];
        $generatedBatch = $metadata['generated_batch'] ?? null;
        if (is_string($generatedBatch) && $generatedBatch !== '') {
            if (!str_contains($contents, 'OracleGeneratedExecutionFamilies::TOTAL_GENERATED_FAMILIES')) {
                fail("{$test} generated batch test does not enumerate the generated family manifest range");
            }
            continue;
        }

        if (!str_contains($contents, "'{$family}'") && !str_contains($contents, '"' . $family . '"')) {
            fail("{$test} batch test does not explicitly enumerate family {$family}");
        }
    }
}

if (count($families) < 1145) {
    fail('coverage audit expected at least 1145 executable families after generated 782 + JINX web merge, found ' . count($families));
}

$distinctTests = array_keys($testToFamilies);
sort($distinctTests);

echo 'PASS: Oracle full coverage audit validates ' . count($families) . ' executable families across ' . count($distinctTests) . ' PHP parity tests' . PHP_EOL;
