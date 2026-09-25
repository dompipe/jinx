<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/zend-arbitrary-code.php');

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

function findOp(array $program, string $op): array
{
    foreach ($program['statements'] ?? [] as $statement) {
        if (($statement['op'] ?? null) === $op) {
            return $statement;
        }
    }

    fail("missing Oracle op {$op}");
}

function requireZendFeature(array $program, string $op, string $construct): void
{
    $statement = findOp($program, $op);
    $features = (array) ($statement['features'] ?? []);

    same($features['php_family'] ?? null, 'zend', "{$op} family");
    same($features['zend_construct'] ?? null, $construct, "{$op} construct");
    same($features['oracle_interpreter_record'] ?? null, true, "{$op} Oracle record");
    same($features['php_fallback_required'] ?? null, true, "{$op} fallback");
}

same($program['kind'] ?? null, 'JINX_ORACLE_PROGRAM', 'program kind');
same($program['executable'], false, 'arbitrary Zend code stays non-executable until mirrored');

if (($program['statement_count'] ?? 0) < 30) {
    fail('arbitrary Zend fixture should produce a broad Oracle statement stream');
}

foreach ([
    'O_NAMESPACE' => 'namespace',
    'O_USE' => 'use',
    'O_INTERFACE_DECL' => 'interface_decl',
    'O_TRAIT_DECL' => 'trait_decl',
    'O_ENUM_DECL' => 'enum_decl',
    'O_CLASS_DECL' => 'class_decl',
    'O_METHOD_DECL' => 'method_decl',
    'O_PROPERTY_DECL' => 'property_decl',
    'O_GLOBAL' => 'global',
    'O_STATIC_LOCAL' => 'static_local',
    'O_NEW' => 'new',
    'O_STATIC_CALL' => 'static_call',
    'O_METHOD_CALL' => 'method_call',
    'O_PROPERTY_FETCH' => 'property_fetch',
    'O_IF' => 'if',
    'O_SWITCH' => 'switch',
    'O_BREAK' => 'break',
    'O_DO' => 'do',
    'O_CONTINUE' => 'continue',
    'O_MATCH' => 'match',
    'O_UNSET' => 'unset',
    'O_THROW' => 'throw',
    'O_RETURN' => 'return',
] as $op => $construct) {
    requireZendFeature($program, $op, $construct);
}

$staticCall = findOp($program, 'O_STATIC_CALL');
$staticFeatures = (array) ($staticCall['features'] ?? []);
same($staticFeatures['class'] ?? null, 'ArbitraryFixture', 'static call class');
same($staticFeatures['method'] ?? null, 'make', 'static call method');

$methodCall = findOp($program, 'O_METHOD_CALL');
$methodFeatures = (array) ($methodCall['features'] ?? []);
same($methodFeatures['method'] ?? null, 'bump', 'method call name');

$propertyFetch = findOp($program, 'O_PROPERTY_FETCH');
$propertyFeatures = (array) ($propertyFetch['features'] ?? []);
same($propertyFeatures['property'] ?? null, 'value', 'property fetch name');

echo "PASS: Oracle records arbitrary Zend-shaped PHP constructs without claiming execution" . PHP_EOL;
