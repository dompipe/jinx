<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleGeneratedBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleMergedExecutionFamilies.php';

use jinx\oracle\OracleGeneratedBuiltinExecutor;
use jinx\oracle\OracleGeneratedExecutionFamilies;
use jinx\oracle\OracleMergedExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$families = OracleGeneratedExecutionFamilies::all();
$mergedFamilies = OracleMergedExecutionFamilies::all();
$total = OracleGeneratedExecutionFamilies::TOTAL_GENERATED_FAMILIES;

if (count($families) !== $total) {
    fail("expected exactly {$total} generated executable families, found " . count($families));
}

$testPath = 'scripts/test-oracle-generated-175-builtin-execution.php';
$testSource = (string) file_get_contents($root . '/' . $testPath);
$docs = (string) file_get_contents($root . '/docs/ORACLE_GENERATED_175_BUILTINS.md');

for ($i = 1; $i <= $total; $i++) {
    $family = sprintf('generated-pure-builtin-%03d', $i);
    if (!array_key_exists($family, $families)) {
        fail("missing generated family {$family}");
    }
    if (!array_key_exists($family, $mergedFamilies)) {
        fail("merged family set missing generated family {$family}");
    }

    $metadata = $families[$family];
    if (($metadata['state'] ?? null) !== 'executable') {
        fail("{$family} is not executable");
    }
    if (($metadata['owner'] ?? null) !== OracleGeneratedBuiltinExecutor::class) {
        fail("{$family} has wrong owner");
    }
    if (($metadata['test'] ?? null) !== $testPath) {
        fail("{$family} has wrong test path");
    }
    if (($metadata['builtins'] ?? []) === []) {
        fail("{$family} has no builtin facet list");
    }
}

foreach (['generated-pure-builtin-001', 'generated-pure-builtin-275', 'generated-pure-builtin-001 through generated-pure-builtin-275'] as $marker) {
    if (!str_contains($docs, $marker)) {
        fail("docs missing marker {$marker}");
    }
}

if (!str_contains($testSource, 'OracleGeneratedExecutionFamilies::TOTAL_GENERATED_FAMILIES')) {
    fail('generated parity test no longer enumerates the manifest family range');
}

if (!class_exists(OracleGeneratedBuiltinExecutor::class)) {
    fail('generated builtin executor is not loadable');
}

echo "PASS: Oracle generated {$total} family group exposes {$total} executable PHP/Zend parity families merged into the normal family set" . PHP_EOL;
