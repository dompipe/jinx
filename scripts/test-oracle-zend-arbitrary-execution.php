<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: array_literal array_offset_fetch function_decl function_call global_declaration static_local use_alias imported_exception

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleZendArbitraryExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleZendArbitraryExecutor;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-zend-arbitrary.php';

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

function capture_php_zend_arbitrary_fixture(string $fixture): array
{
    $return = null;
    $errorClass = null;
    $errorMessage = null;

    ob_start();
    try {
        $return = require $fixture;
    } catch (Throwable $e) {
        $errorClass = $e::class;
        $errorMessage = $e->getMessage();
    } finally {
        $output = (string) ob_get_clean();
    }

    return ['output' => $output, 'return' => $return, 'error_class' => $errorClass, 'error_message' => $errorMessage];
}

function capture_oracle_zend_arbitrary_fixture(string $fixture): array
{
    $result = ['output' => '', 'return' => null, 'error_class' => null, 'error_message' => null, 'oracle' => null, 'program' => null];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleZendArbitraryExecutor::execute($program);
        $result['program'] = $program;
        $result['oracle'] = $oracle;
        $result['output'] = $oracle['output'] ?? null;
        $result['return'] = $oracle['return'] ?? null;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    }

    return $result;
}

$php = capture_php_zend_arbitrary_fixture($fixture);
$oracle = capture_oracle_zend_arbitrary_fixture($fixture);

same($oracle['error_class'], $php['error_class'], 'Oracle error class matches PHP');
if ($php['error_class'] !== null) {
    if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
        fail('Oracle error message does not include PHP error message');
    }
    echo "PASS: Oracle arbitrary Zend PHP subset matches PHP error behavior" . PHP_EOL;
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'zend-arbitrary', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 18) {
    fail('Oracle executed too few arbitrary Zend ops');
}

$ops = array_column($oracle['program']['statements'] ?? [], 'op');
foreach (['O_NAMESPACE', 'O_USE', 'O_FUNCTION_DECL', 'O_GLOBAL', 'O_STATIC_LOCAL', 'O_ASSIGN', 'O_IF', 'O_SWITCH', 'O_BREAK', 'O_DO', 'O_CONTINUE', 'O_MATCH', 'O_UNSET', 'O_THROW', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$source = (string) file_get_contents($fixture);
foreach (['global $globalCounter', 'static $calls = 0', 'isset($payload', 'switch ($payload', 'do {', 'continue', 'match ($suffix)', 'unset($payload', 'throw new ImportedRuntimeException', 'zend_big_entry'] as $needle) {
    if (!str_contains($source, $needle)) {
        fail("fixture did not contain expected arbitrary Zend source {$needle}");
    }
}

echo "PASS: Oracle executes arbitrary Zend function/control PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
