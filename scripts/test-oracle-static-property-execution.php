<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: class_declaration static_property_fetch static_property_write static_property_compound_write shared_class_state

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleStaticPropertyExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleStaticPropertyExecutor;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-static-properties.php';

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

function run_php_static_property_fixture(string $fixture): array
{
    $__jinx_capture_return = null;
    $__jinx_capture_error_class = null;
    $__jinx_capture_error_message = null;

    ob_start();
    try {
        $__jinx_capture_return = require $fixture;
    } catch (Throwable $e) {
        $__jinx_capture_error_class = $e::class;
        $__jinx_capture_error_message = $e->getMessage();
    } finally {
        $__jinx_capture_output = (string) ob_get_clean();
    }

    return [
        'output' => $__jinx_capture_output,
        'return' => $__jinx_capture_return,
        'error_class' => $__jinx_capture_error_class,
        'error_message' => $__jinx_capture_error_message,
    ];
}

function run_oracle_static_property_fixture(string $fixture): array
{
    $result = [
        'output' => '',
        'return' => null,
        'error_class' => null,
        'error_message' => null,
        'oracle' => null,
        'program' => null,
    ];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleStaticPropertyExecutor::execute($program);
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

$php = run_php_static_property_fixture($fixture);
$oracle = run_oracle_static_property_fixture($fixture);

same($oracle['error_class'], $php['error_class'], 'Oracle error class matches PHP');

if ($php['error_class'] !== null) {
    if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
        fail('Oracle error message does not include PHP error message');
    }

    echo "PASS: Oracle static-property PHP subset matches PHP error behavior" . PHP_EOL;
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['output'], '2:7:10', 'Oracle compound static-property write output');
same($oracle['return'], 10, 'Oracle compound static-property write return');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'static-properties', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 8) {
    fail('Oracle executed too few static-property ops');
}

$ops = array_column($oracle['program']['statements'] ?? [], 'op');
foreach (['O_CLASS_DECL', 'O_PROPERTY_DECL', 'O_STATIC_PROPERTY_ASSIGN', 'O_STATIC_PROPERTY_FETCH', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$writeRecords = array_values(array_filter(
    $oracle['program']['statements'] ?? [],
    static fn (array $statement): bool => str_starts_with((string) ($statement['source'] ?? ''), 'StaticCounter::$counter =')
));
same(count($writeRecords), 1, 'fixture has one static-property write record');
same($writeRecords[0]['op'] ?? null, 'O_STATIC_PROPERTY_ASSIGN', 'static-property write is not mislabeled as fetch');
same($writeRecords[0]['features']->class ?? null, 'StaticCounter', 'static-property write class feature');
same($writeRecords[0]['features']->property ?? null, 'counter', 'static-property write property feature');

$compoundWriteRecords = array_values(array_filter(
    $oracle['program']['statements'] ?? [],
    static fn (array $statement): bool => str_starts_with((string) ($statement['source'] ?? ''), 'StaticCounter::$counter +=')
));
same(count($compoundWriteRecords), 1, 'fixture has one compound static-property write record');
same($compoundWriteRecords[0]['op'] ?? null, 'O_STATIC_PROPERTY_ASSIGN', 'compound static-property write uses write opcode');
same($compoundWriteRecords[0]['features']->compound_assignment ?? null, true, 'compound static-property write feature');

$source = (string) file_get_contents($fixture);
foreach (['public static $counter', 'StaticCounter::$counter', 'StaticCounter::$counter + 5', 'StaticCounter::$counter += 3'] as $needle) {
    if (!str_contains($source, $needle)) {
        fail("fixture did not contain expected static-property source {$needle}");
    }
}

echo "PASS: Oracle executes static-property PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
