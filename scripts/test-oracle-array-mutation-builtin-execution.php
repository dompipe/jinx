<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayMutationBuiltinExecutor.php';

use jinx\oracle\OracleArrayMutationBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-array-mutation-builtin';

$cases = [
    'sort-builtins' => ["\$value = ['b', 'a', 'c'];", '$result = sort($value);'],
    'rsort-builtins' => ["\$value = ['b', 'a', 'c'];", '$result = rsort($value);'],
    'asort-builtins' => ["\$value = ['b' => 2, 'a' => 1, 'c' => 3];", '$result = asort($value);'],
    'arsort-builtins' => ["\$value = ['b' => 2, 'a' => 1, 'c' => 3];", '$result = arsort($value);'],
    'ksort-builtins' => ["\$value = ['b' => 2, 'a' => 1, 'c' => 3];", '$result = ksort($value);'],
    'krsort-builtins' => ["\$value = ['b' => 2, 'a' => 1, 'c' => 3];", '$result = krsort($value);'],
    'natsort-builtins' => ["\$value = ['img12', 'img10', 'img2', 'img1'];", '$result = natsort($value);'],
    'natcasesort-builtins' => ["\$value = ['Img12', 'img10', 'img2', 'Img1'];", '$result = natcasesort($value);'],
    'array-push-builtins' => ["\$value = ['a'];", "\$result = array_push(\$value, 'b', 'c');"],
    'array-pop-builtins' => ["\$value = ['a', 'b', 'c'];", '$result = array_pop($value);'],
    'array-shift-builtins' => ["\$value = ['a', 'b', 'c'];", '$result = array_shift($value);'],
    'array-unshift-builtins' => ["\$value = ['b', 'c'];", "\$result = array_unshift(\$value, 'a');"],
    'array-splice-builtins' => ["\$value = ['a', 'b', 'c', 'd'];", "\$result = array_splice(\$value, 1, 2, ['x', 'y']);"],
    'array-multisort-builtins' => ["\$value = [3, 1, 2];", "\$labels = ['c', 'a', 'b'];", '$result = array_multisort($value, $labels);'],
    'reset-builtins' => ["\$value = ['a' => 'first', 'b' => 'second'];", '$result = reset($value);'],
    'end-builtins' => ["\$value = ['a' => 'first', 'b' => 'second'];", '$result = end($value);'],
    'next-builtins' => ["\$value = ['a' => 'first', 'b' => 'second'];", '$result = next($value);'],
    'prev-builtins' => ["\$value = ['a' => 'first', 'b' => 'second'];", '$result = prev($value);'],
    'current-builtins' => ["\$value = ['a' => 'first', 'b' => 'second'];", '$result = current($value);'],
    'key-builtins' => ["\$value = ['a' => 'first', 'b' => 'second'];", '$result = key($value);'],
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

/** @param list<string> $statements */
function write_fixture(string $fixtureDir, string $family, array $statements): string
{
    if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0777, true) && !is_dir($fixtureDir)) {
        fail('Could not create generated fixture directory: ' . $fixtureDir);
    }

    $path = $fixtureDir . '/' . fixture_name($family);
    $source = "<?php\n\ndeclare(strict_types=1);\n\n" .
        '$labels = null;' . "\n" .
        implode("\n", $statements) . "\n" .
        '$encoded = json_encode([$result, $value, $labels]);' . "\n" .
        "echo 'value=' . \$encoded . \"\\n\";\n" .
        'return $encoded;' . "\n";

    file_put_contents($path, $source);

    return $path;
}

function run_php_array_mutation_fixture(string $fixture): array
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

function run_oracle_array_mutation_fixture(string $fixture, string $family): array
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
        $oracle = OracleArrayMutationBuiltinExecutor::execute($program, $family);
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

foreach ($cases as $family => $statements) {
    $fixture = write_fixture($fixtureDir, $family, $statements);
    $php = run_php_array_mutation_fixture($fixture);
    $oracle = run_oracle_array_mutation_fixture($fixture, $family);

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

echo "PASS: Oracle executes array mutation builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
