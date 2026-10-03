<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: use_alias interface_contract trait_method backed_enum_case static_factory method_dispatch enum_value_fetch

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleZendDeclarationExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleZendDeclarationExecutor;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-zend-declarations.php';

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

function capture_php_zend_declaration_fixture(string $fixture): array
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

    return [
        'output' => $output,
        'return' => $return,
        'error_class' => $errorClass,
        'error_message' => $errorMessage,
    ];
}

function capture_oracle_zend_declaration_fixture(string $fixture): array
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
        $oracle = OracleZendDeclarationExecutor::execute($program);
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

$php = capture_php_zend_declaration_fixture($fixture);
$oracle = capture_oracle_zend_declaration_fixture($fixture);

same($oracle['error_class'], $php['error_class'], 'Oracle error class matches PHP');

if ($php['error_class'] !== null) {
    if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
        fail('Oracle error message does not include PHP error message');
    }

    echo "PASS: Oracle Zend declaration PHP subset matches PHP error behavior" . PHP_EOL;
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'zend-declarations', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 7) {
    fail('Oracle executed too few Zend declaration ops');
}

$ops = array_column($oracle['program']['statements'] ?? [], 'op');
foreach (['O_NAMESPACE', 'O_USE', 'O_INTERFACE_DECL', 'O_TRAIT_DECL', 'O_ENUM_DECL', 'O_ENUM_CASE', 'O_CLASS_DECL', 'O_METHOD_DECL', 'O_PROPERTY_DECL', 'O_STATIC_CALL', 'O_METHOD_CALL', 'O_PROPERTY_FETCH', 'O_IF', 'O_THROW', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$enumCases = array_values(array_filter(
    $oracle['program']['statements'] ?? [],
    static fn (array $statement): bool => ($statement['op'] ?? null) === 'O_ENUM_CASE'
));
same(count($enumCases), 1, 'fixture records one backed enum case');
same($enumCases[0]['features']->name ?? null, 'One', 'backed enum case name');
same($enumCases[0]['features']->backed_value_source ?? null, "'one'", 'backed enum case value source');

$source = (string) file_get_contents($fixture);
foreach (['namespace Dompipe\\Jinx\\Fixtures\\ExecutableZend', 'use RuntimeException as ImportedRuntimeException', 'interface RenderableDeclaration', 'trait CountsDeclaration', 'enum DeclarationMode', 'DeclarationFixture::make', '$object->bump', '$mode->value'] as $needle) {
    if (!str_contains($source, $needle)) {
        fail("fixture did not contain expected Zend declaration source {$needle}");
    }
}

echo "PASS: Oracle executes Zend declaration/interface/trait/enum PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
