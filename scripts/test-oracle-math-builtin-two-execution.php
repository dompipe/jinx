<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';

use jinx\oracle\OracleMathBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-math-builtin-two';

$cases = [
    'acosh-builtins' => 'acosh(2.0)',
    'asinh-builtins' => 'asinh(1.5)',
    'atanh-builtins' => 'atanh(0.25)',
    'atan2-builtins' => 'atan2(3.0, 4.0)',
    'log10-builtins' => 'log10(1000.0)',
    'log1p-builtins' => 'log1p(0.75)',
    'expm1-builtins' => 'expm1(1.25)',
    'sinh-builtins' => 'sinh(1.25)',
    'cosh-builtins' => 'cosh(1.25)',
    'tanh-builtins' => 'tanh(0.75)',
    'fdiv-builtins' => 'fdiv(7.5, 2.5)',
    'abs-builtins' => 'abs(-17.5)',
    'max-builtins' => 'max(3, 9, 4)',
    'min-builtins' => 'min(3, 9, 4)',
    'round-half-up-builtins' => 'round(2.5, 0, PHP_ROUND_HALF_UP)',
    'round-half-down-builtins' => 'round(2.5, 0, PHP_ROUND_HALF_DOWN)',
    'round-half-even-builtins' => 'round(2.5, 0, PHP_ROUND_HALF_EVEN)',
    'round-half-odd-builtins' => 'round(2.5, 0, PHP_ROUND_HALF_ODD)',
    'getrandmax-builtins' => 'getrandmax()',
    'mt-getrandmax-builtins' => 'mt_getrandmax()',
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

function run_php_math_builtin_fixture(string $fixture): array
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

function run_oracle_math_builtin_fixture(string $fixture, string $family): array
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
        $oracle = OracleMathBuiltinExecutor::execute($program, $family);
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
    $php = run_php_math_builtin_fixture($fixture);
    $oracle = run_oracle_math_builtin_fixture($fixture, $family);

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

echo "PASS: Oracle executes math builtin batch two PHP families and matches PHP output/return/error behavior" . PHP_EOL;
