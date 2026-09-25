<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleGeneratedBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedExecutionFamilies.php';

use jinx\oracle\OracleGeneratedBuiltinExecutor;
use jinx\oracle\OracleGeneratedExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$families = OracleGeneratedExecutionFamilies::all();

if (count($families) !== 175) {
    fail('expected exactly 175 generated executable families, found ' . count($families));
}

$testPath = 'scripts/test-oracle-generated-175-builtin-execution.php';
$testSource = (string) file_get_contents($root . '/' . $testPath);
$docs = (string) file_get_contents($root . '/docs/ORACLE_GENERATED_175_BUILTINS.md');

for ($i = 1; $i <= 175; $i++) {
    $family = sprintf('generated-pure-builtin-%03d', $i);
    if (!array_key_exists($family, $families)) {
        fail("missing generated family {$family}");
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

foreach (['generated-pure-builtin-001', 'generated-pure-builtin-175', 'generated-pure-builtin-001 through generated-pure-builtin-175'] as $marker) {
    if (!str_contains($docs, $marker)) {
        fail("docs missing marker {$marker}");
    }
}

if (!str_contains($testSource, 'for ($i = 1; $i <= 175; $i++)')) {
    fail('generated 175 parity test no longer enumerates the full family range');
}

if (!class_exists(OracleGeneratedBuiltinExecutor::class)) {
    fail('generated builtin executor is not loadable');
}

echo 'PASS: Oracle generated 175 family group exposes 175 executable PHP/Zend parity families' . PHP_EOL;
