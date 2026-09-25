<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleArraySetBuiltinExecutor.php';

use jinx\oracle\OracleArraySetBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-array-set-builtin';

$cases = [
    'array-diff-assoc-builtins' => "json_encode(array_diff_assoc(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 1, 'b' => 9]))",
    'array-diff-key-builtins' => "json_encode(array_diff_key(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 9, 'c' => 8]))",
    'array-intersect-assoc-builtins' => "json_encode(array_intersect_assoc(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 1, 'b' => 9, 'c' => 3]))",
    'array-intersect-key-builtins' => "json_encode(array_intersect_key(['a' => 1, 'b' => 2, 'c' => 3], ['b' => 9, 'c' => 8]))",
    'array-merge-recursive-builtins' => "json_encode(array_merge_recursive(['a' => ['x'], 'b' => 1], ['a' => ['y'], 'b' => 2]))",
    'array-fill-keys-builtins' => "json_encode(array_fill_keys(['left', 'right'], 'filled'))",
    'array-key-first-builtins' => "array_key_first(['alpha' => 1, 'beta' => 2])",
    'array-key-last-builtins' => "array_key_last(['alpha' => 1, 'beta' => 2])",
    'array-keys-strict-builtins' => "json_encode(array_keys(['a' => '2', 'b' => 2, 'c' => '2'], '2', true))",
    'array-reverse-preserve-builtins' => "json_encode(array_reverse(['a' => 1, 'b' => 2, 'c' => 3], true))",
    'array-slice-preserve-builtins' => "json_encode(array_slice(['a' => 1, 'b' => 2, 'c' => 3], 1, 2, true))",
    'array-pad-negative-builtins' => "json_encode(array_pad(['x'], -3, 'pad'))",
    'array-search-strict-builtins' => "array_search('2', [2, '2', 3], true)",
    'in-array-strict-builtins' => "in_array('2', [2, '2', 3], true)",
    'count-recursive-builtins' => "count(['a' => [1, 2], 'b' => [3]], 1)",
    'array-column-index-builtins' => "json_encode(array_column([['id' => 'a', 'name' => 'Ada'], ['id' => 'b', 'name' => 'Bob']], 'name', 'id'))",
    'array-chunk-preserve-builtins' => "json_encode(array_chunk(['a' => 1, 'b' => 2, 'c' => 3], 2, true))",
    'range-step-builtins' => "json_encode(range(2, 10, 2))",
    'array-filter-null-builtins' => "json_encode(array_filter(['a' => 0, 'b' => 1, 'c' => '', 'd' => 'ok']))",
    'array-unique-string-builtins' => "json_encode(array_unique(['10', 10, '2', 2], 2))",
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

function write_fixture(string $fixtureDir, string $family, string $expr): string
{
    if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0777, true) && !is_dir($fixtureDir)) {
        fail('Could not create generated fixture directory: ' . $fixtureDir);
    }

    $path = $fixtureDir . '/' . fixture_name($family);
    $source = "<?php\n\ndeclare(strict_types=1);\n\n" .
        '$value = ' . $expr . ";\n" .
        "echo 'value=' . \$value . \"\\n\";\n" .
        'return $value;' . "\n";

    file_put_contents($path, $source);

    return $path;
}

function run_php_array_set_fixture(string $fixture): array
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

function run_oracle_array_set_fixture(string $fixture, string $family): array
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
        $oracle = OracleArraySetBuiltinExecutor::execute($program, $family);
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

foreach ($cases as $family => $expr) {
    $fixture = write_fixture($fixtureDir, $family, $expr);
    $php = run_php_array_set_fixture($fixture);
    $oracle = run_oracle_array_set_fixture($fixture, $family);

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

echo "PASS: Oracle executes array set/key builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
