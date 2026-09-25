<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);

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

$simple = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/simple-add.php');

same($simple['kind'], 'JINX_ORACLE_PROGRAM', 'simple kind');
same($simple['executable'], true, 'simple executable');
same($simple['coalesced_ops'], [[
    'op' => 'ORET_CONST',
    'value' => 5,
]], 'simple coalesced ops');

$required = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/oracle-require-entry.php');
same($required['kind'], 'JINX_ORACLE_PROGRAM', 'require entry kind');
same($required['statement_count'], 1, 'require entry statement count');

$requireStmt = $required['statements'][0] ?? null;
if (!is_array($requireStmt)) {
    fail('require entry did not produce a statement');
}

same($requireStmt['op'] ?? null, 'O_REQUIRE', 'require statement op');

$requireFeatures = (array) ($requireStmt['features'] ?? []);
same($requireFeatures['loader'] ?? null, 'require', 'require loader');
same($requireFeatures['target'] ?? null, 'oracle-required-library.php', 'require target');
same($requireFeatures['enters_oracle_program'] ?? null, true, 'require enters Oracle program');
same($requireFeatures['included_executable'] ?? null, true, 'required file is executable Oracle');

$included = $requireStmt['included_oracle_program'] ?? null;
if (!is_array($included)) {
    fail('require statement did not carry included Oracle program');
}

same($included['kind'] ?? null, 'JINX_ORACLE_PROGRAM', 'included Oracle kind');
same($included['executable'] ?? null, true, 'included Oracle executable');
same($included['coalesced_ops'] ?? null, [[
    'op' => 'ORET_CONST',
    'value' => 5,
]], 'included Oracle coalesced ops');

$includedFile = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/oracle-include-entry.php');
$includeStmt = $includedFile['statements'][0] ?? null;
if (!is_array($includeStmt)) {
    fail('include entry did not produce a statement');
}

same($includeStmt['op'] ?? null, 'O_INCLUDE', 'include statement op');
$includeFeatures = (array) ($includeStmt['features'] ?? []);
same($includeFeatures['loader'] ?? null, 'include', 'include loader');
same($includeFeatures['enters_oracle_program'] ?? null, true, 'include enters Oracle program');

$includeOnceFile = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/oracle-include-once-entry.php');
$includeOnceStmt = $includeOnceFile['statements'][0] ?? null;
if (!is_array($includeOnceStmt)) {
    fail('include_once entry did not produce a statement');
}

same($includeOnceStmt['op'] ?? null, 'O_INCLUDE', 'include_once statement op');
$includeOnceFeatures = (array) ($includeOnceStmt['features'] ?? []);
same($includeOnceFeatures['loader'] ?? null, 'include_once', 'include_once loader');
same($includeOnceFeatures['once'] ?? null, true, 'include_once once flag');
same($includeOnceFeatures['enters_oracle_program'] ?? null, true, 'include_once enters Oracle program');

$dynamicLoader = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/oracle-dynamic-loader-entry.php');
$dynamicStmt = $dynamicLoader['statements'][1] ?? null;
if (!is_array($dynamicStmt)) {
    fail('dynamic include entry did not produce a loader statement');
}

same($dynamicStmt['op'] ?? null, 'O_INCLUDE', 'dynamic include statement op');
$dynamicFeatures = (array) ($dynamicStmt['features'] ?? []);
same($dynamicFeatures['dynamic_target'] ?? null, true, 'dynamic include target flag');
same($dynamicFeatures['php_fallback_required'] ?? null, true, 'dynamic include fallback flag');
same(array_key_exists('included_oracle_program', $dynamicStmt), false, 'dynamic include does not enter Oracle program');

$cycle = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/oracle-cycle-a.php');
$cycleA = $cycle['statements'][0] ?? null;
if (!is_array($cycleA)) {
    fail('cycle entry did not produce a statement');
}

$cycleB = $cycleA['included_oracle_program']['statements'][0] ?? null;
if (!is_array($cycleB)) {
    fail('cycle include did not carry nested statement');
}

$cycleFeatures = (array) ($cycleB['features'] ?? []);
same($cycleFeatures['oracle_include_cycle'] ?? null, true, 'include cycle flag');
same($cycleFeatures['php_fallback_required'] ?? null, true, 'include cycle fallback flag');
same(array_key_exists('included_oracle_program', $cycleB), false, 'cycle does not recurse forever');

$realFile = $root . '/fixtures/oracle-post-curl-dynamic.php';

if (!is_file($realFile)) {
    $realFile = $root . '/fixtures/real-post-curl-dynamic.php';
}

if (is_file($realFile)) {
    $real = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($realFile);

    same($real['kind'], 'JINX_ORACLE_PROGRAM', 'real kind');

    if (($real['statement_count'] ?? 0) < 10) {
        fail('real PHP file should produce many Oracle statements');
    }

    $seenCurl = false;
    $seenJson = false;
    $seenPost = false;
    $seenFunction = false;

    foreach ($real['statements'] as $stmt) {
        $features = (array) ($stmt['features'] ?? []);

        if (($features['curl'] ?? false) === true) {
            $seenCurl = true;
        }

        if (($features['json'] ?? false) === true) {
            $seenJson = true;
        }

        if (($features['post_input'] ?? false) === true) {
            $seenPost = true;
        }

        if (($stmt['op'] ?? null) === 'O_FUNCTION_DECL') {
            $seenFunction = true;
        }
    }

    same($seenFunction, true, 'real PHP has Oracle function declarations');
    same($seenCurl, true, 'real PHP has Oracle curl feature');
    same($seenJson, true, 'real PHP has Oracle json feature');
    same($seenPost, true, 'real PHP has Oracle post-input feature');
}

echo "PASS: OracleProgramCompiler interprets PHP into canonical Oracle statements and executable ops when supported" . PHP_EOL;
