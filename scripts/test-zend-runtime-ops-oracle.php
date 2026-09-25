<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($root . '/fixtures/zend-runtime-ops.php');

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
same($program['executable'], false, 'runtime Zend ops stay non-executable until mirrored');

if (($program['statement_count'] ?? 0) < 25) {
    fail('runtime ops fixture should produce a broad Oracle statement stream');
}

foreach ([
    'O_DECLARE' => 'declare',
    'O_CLASS_DECL' => 'class_decl',
    'O_PROPERTY_DECL' => 'property_decl',
    'O_FUNCTION_DECL' => 'function_decl',
    'O_DIM_ASSIGN' => 'dim_assign',
    'O_COALESCE' => 'coalesce',
    'O_DIM_FETCH' => 'dim_fetch',
    'O_COMPOUND_ASSIGN' => 'compound_assign',
    'O_INC' => 'increment',
    'O_DEC' => 'decrement',
    'O_TERNARY' => 'ternary',
    'O_CLOSURE' => 'closure',
    'O_ARROW_FUNCTION' => 'arrow_function',
    'O_ANON_CLASS' => 'anonymous_class',
    'O_CLONE' => 'clone',
    'O_INSTANCEOF' => 'instanceof',
    'O_STATIC_PROPERTY_FETCH' => 'static_property_fetch',
    'O_ECHO' => 'echo',
    'O_PRINT' => 'print',
    'O_CALL' => 'call',
    'O_GOTO' => 'goto',
    'O_LABEL' => 'label',
    'O_YIELD' => 'yield',
    'O_EXIT' => 'exit',
] as $op => $construct) {
    requireZendFeature($program, $op, $construct);
}

$goto = findOp($program, 'O_GOTO');
$gotoFeatures = (array) ($goto['features'] ?? []);
same($gotoFeatures['label'] ?? null, 'finished', 'goto label');

$label = findOp($program, 'O_LABEL');
$labelFeatures = (array) ($label['features'] ?? []);
same($labelFeatures['label'] ?? null, 'finished', 'label name');

$instanceof = findOp($program, 'O_INSTANCEOF');
$instanceofFeatures = (array) ($instanceof['features'] ?? []);
same($instanceofFeatures['class'] ?? null, 'RuntimeOps', 'instanceof class');

echo "PASS: Oracle records Zend runtime body ops for arbitrary PHP without claiming execution" . PHP_EOL;
