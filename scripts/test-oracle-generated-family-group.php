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
$templates = OracleGeneratedExecutionFamilies::UNIQUE_GENERATED_TEMPLATES;
$lastFamily = sprintf('generated-pure-builtin-%03d', $total);
$rangeMarker = 'generated-pure-builtin-001 through ' . $lastFamily;
$templateIndexes = [];

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
    if (($metadata['semantic_coverage'] ?? null) !== 'generated-case-from-25-pure-builtin-templates') {
        fail("{$family} missing generated semantic coverage marker");
    }
    $templateIndex = $metadata['generated_template_index'] ?? null;
    if (!is_int($templateIndex) || $templateIndex < 0 || $templateIndex >= $templates) {
        fail("{$family} has invalid generated template index: " . var_export($templateIndex, true));
    }
    $templateIndexes[$templateIndex] = true;
}

if (count($templateIndexes) !== $templates) {
    fail("expected {$templates} generated expression templates, found " . count($templateIndexes));
}

foreach (['generated-pure-builtin-001', $lastFamily, $rangeMarker, "{$templates} unique expression templates"] as $marker) {
    if (!str_contains($docs, $marker)) {
        fail("docs missing marker {$marker}");
    }
}

if (!str_contains($testSource, 'OracleGeneratedExecutionFamilies::TOTAL_GENERATED_FAMILIES')) {
    fail('generated parity test no longer enumerates the manifest family range');
}
if (!str_contains($testSource, 'OracleGeneratedExecutionFamilies::UNIQUE_GENERATED_TEMPLATES')) {
    fail('generated parity test no longer reports the unique generated template count');
}

if (!class_exists(OracleGeneratedBuiltinExecutor::class)) {
    fail('generated builtin executor is not loadable');
}

echo "PASS: Oracle generated {$total} family IDs across {$templates} unique expression templates merged into the normal family set" . PHP_EOL;
