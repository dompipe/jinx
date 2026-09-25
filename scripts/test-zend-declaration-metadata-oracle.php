<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/zend-declaration-metadata.php');

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

function findOp(array $program, string $op, ?string $name = null): array
{
    foreach ($program['statements'] ?? [] as $statement) {
        if (($statement['op'] ?? null) !== $op) {
            continue;
        }

        $features = (array) ($statement['features'] ?? []);
        if ($name === null || ($features['name'] ?? null) === $name) {
            return $statement;
        }
    }

    fail("missing Oracle op {$op}" . ($name !== null ? " named {$name}" : ''));
}

function requireZendFeature(array $program, string $op, string $construct, ?string $name = null): array
{
    $statement = findOp($program, $op, $name);
    $features = (array) ($statement['features'] ?? []);

    same($features['php_family'] ?? null, 'zend', "{$op} family");
    same($features['zend_construct'] ?? null, $construct, "{$op} construct");
    same($features['oracle_interpreter_record'] ?? null, true, "{$op} Oracle record");
    same($features['php_fallback_required'] ?? null, true, "{$op} fallback");

    return $features;
}

same($program['kind'] ?? null, 'JINX_ORACLE_PROGRAM', 'program kind');
same($program['executable'], false, 'declaration metadata stays non-executable until mirrored');

foreach ([
    'O_DECLARE' => 'declare',
    'O_NAMESPACE' => 'namespace',
    'O_USE' => 'use',
    'O_CONST_DECL' => 'const_decl',
    'O_CLASS_DECL' => 'class_decl',
    'O_CLASS_CONST' => 'class_const',
    'O_PROPERTY_DECL' => 'property_decl',
    'O_METHOD_DECL' => 'method_decl',
    'O_FUNCTION_DECL' => 'function_decl',
    'O_TRY' => 'try',
    'O_CATCH' => 'catch',
    'O_FINALLY' => 'finally',
    'O_THROW' => 'throw',
] as $op => $construct) {
    requireZendFeature($program, $op, $construct);
}

$attributeClass = requireZendFeature($program, 'O_CLASS_DECL', 'class_decl', 'OracleMeta');
same($attributeClass['attributes'] ?? null, ['Attribute'], 'attribute class records PHP attribute');
same($attributeClass['modifier_final'] ?? null, true, 'final class modifier');

$subjectClass = requireZendFeature($program, 'O_CLASS_DECL', 'class_decl', 'MetadataSubject');
same($subjectClass['attributes'] ?? null, ['OracleMeta'], 'subject class records custom attribute');
same($subjectClass['modifier_readonly'] ?? null, true, 'readonly class modifier');

$baseClass = requireZendFeature($program, 'O_CLASS_DECL', 'class_decl', 'MetadataBase');
same($baseClass['modifier_abstract'] ?? null, true, 'abstract class modifier');

$constructor = requireZendFeature($program, 'O_METHOD_DECL', 'method_decl', '__construct');
same($constructor['magic_method'] ?? null, '__construct', 'constructor magic method');
same($constructor['parameter_count'] ?? null, 1, 'constructor parameter count');
same($constructor['parameters'] ?? null, ['name'], 'constructor parameter names');

$destructor = requireZendFeature($program, 'O_METHOD_DECL', 'method_decl', '__destruct');
same($destructor['magic_method'] ?? null, '__destruct', 'destructor magic method');

$toString = requireZendFeature($program, 'O_METHOD_DECL', 'method_decl', '__toString');
same($toString['magic_method'] ?? null, '__tostring', 'toString magic method');
same($toString['return_type'] ?? null, 'string', 'toString return type');

$render = requireZendFeature($program, 'O_METHOD_DECL', 'method_decl', 'render');
same($render['modifier_abstract'] ?? null, true, 'abstract method modifier');
same($render['modifier_protected'] ?? null, true, 'protected method modifier');
same($render['parameter_count'] ?? null, 2, 'render parameter count');
same($render['parameters'] ?? null, ['name', 'flags'], 'render parameter names');
same($render['return_type'] ?? null, 'string', 'render return type');

$entry = requireZendFeature($program, 'O_FUNCTION_DECL', 'function_decl', 'metadata_entry');
same($entry['parameter_count'] ?? null, 2, 'entry parameter count');
same($entry['parameters'] ?? null, ['subject', 'fallback'], 'entry parameter names');
same($entry['return_type'] ?? null, 'string', 'entry return type');

$catch = requireZendFeature($program, 'O_CATCH', 'catch');
same($catch['catch_type'] ?? null, 'RuntimeException|Throwable', 'catch union type');
same($catch['catch_variable'] ?? null, 'error', 'catch variable');

echo "PASS: Oracle records Zend declaration metadata, attributes, magic methods, and catch/finally" . PHP_EOL;
