<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleIntrospectionBuiltinExecutor.php';

use jinx\oracle\OracleIntrospectionBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-introspection-builtin';

$cases = [
    'is-float-builtins' => 'is_float(3.5)',
    'is-double-builtins' => 'is_double(3.5)',
    'is-real-builtins' => 'is_float(3.5)',
    'is-long-builtins' => 'is_long(7)',
    'is-integer-builtins' => 'is_integer(7)',
    'is-iterable-builtins' => 'is_iterable([1, 2, 3])',
    'is-resource-builtins' => "is_resource('not-resource')",
    'is-callable-builtins' => "is_callable('strlen')",
    'function-exists-builtins' => "function_exists('strlen')",
    'class-exists-builtins' => "class_exists('DateTime')",
    'interface-exists-builtins' => "interface_exists('Throwable')",
    'trait-exists-builtins' => "trait_exists('NeverThereJinxTrait')",
    'enum-exists-builtins' => "enum_exists('NeverThereJinxEnum')",
    'get-debug-type-builtins' => 'get_debug_type([1, 2, 3])',
    'constant-builtins' => "constant('PHP_VERSION')",
    'method-exists-builtins' => "method_exists('DateTime', 'format')",
    'property-exists-builtins' => "property_exists('DateTime', 'date')",
    'is-subclass-of-builtins' => "is_subclass_of('DateTimeImmutable', 'DateTimeInterface')",
    'is-a-builtins' => "is_a('DateTimeImmutable', 'DateTimeInterface', true)",
    'defined-builtins' => "defined('PHP_VERSION')",
];

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

function fixture_name(string $family): string
{
    return preg_replace('/[^a-z0-9]+/', '-', strtolower($family)) . '.php';
}

function write_fixture(string $fixtureDir, string $family, string $expression): string
{
    if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0777, true) && !is_dir($fixtureDir)) {
        fail('Could not create generated fixture directory: ' . $fixtureDir);
    }

    $path = $fixtureDir . '/' . fixture_name($family);
    $source = "<?php\n\ndeclare(strict_types=1);\n\n" .
        '$value = ' . $expression . ";\n" .
        "echo 'value=' . \$value . \"\\n\";\n" .
        'return $value;' . "\n";

    file_put_contents($path, $source);

    return $path;
}

function run_php_introspection_fixture(string $fixture): array
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

function run_oracle_introspection_fixture(string $fixture, string $family): array
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
        $oracle = OracleIntrospectionBuiltinExecutor::execute($program, $family);
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

foreach ($cases as $family => $expression) {
    $fixture = write_fixture($fixtureDir, $family, $expression);
    $php = run_php_introspection_fixture($fixture);
    $oracle = run_oracle_introspection_fixture($fixture, $family);

    same($oracle['error_class'], $php['error_class'], "{$family} Oracle error class matches PHP");

    if ($php['error_class'] !== null) {
        if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
            fail("{$family} Oracle error message does not include PHP error message");
        }
        continue;
    }

    same($oracle['output'], $php['output'], "{$family} Oracle output matches PHP");
    same($oracle['return'], $php['return'], "{$family} Oracle return matches PHP");
    same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', "{$family} Oracle execution kind");
    same($oracle['oracle']['family'] ?? null, $family, "{$family} Oracle execution family");

    if (($oracle['oracle']['executed_ops'] ?? 0) < 1) {
        fail("{$family} Oracle executed too few ops");
    }
}

echo "PASS: Oracle executes introspection builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
