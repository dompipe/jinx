<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';

use jinx\oracle\OracleMathBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);

$cases = [
    'floor-builtins' => 'fixtures/oracle-app-builtin-floor.php',
    'ceil-builtins' => 'fixtures/oracle-app-builtin-ceil.php',
    'sqrt-builtins' => 'fixtures/oracle-app-builtin-sqrt.php',
    'pow-builtins' => 'fixtures/oracle-app-builtin-pow.php',
    'fmod-builtins' => 'fixtures/oracle-app-builtin-fmod.php',
    'intdiv-builtins' => 'fixtures/oracle-app-builtin-intdiv.php',
    'deg2rad-builtins' => 'fixtures/oracle-app-builtin-deg2rad.php',
    'rad2deg-builtins' => 'fixtures/oracle-app-builtin-rad2deg.php',
    'sin-builtins' => 'fixtures/oracle-app-builtin-sin.php',
    'cos-builtins' => 'fixtures/oracle-app-builtin-cos.php',
    'tan-builtins' => 'fixtures/oracle-app-builtin-tan.php',
    'asin-builtins' => 'fixtures/oracle-app-builtin-asin.php',
    'acos-builtins' => 'fixtures/oracle-app-builtin-acos.php',
    'atan-builtins' => 'fixtures/oracle-app-builtin-atan.php',
    'log-builtins' => 'fixtures/oracle-app-builtin-log.php',
    'exp-builtins' => 'fixtures/oracle-app-builtin-exp.php',
    'pi-builtins' => 'fixtures/oracle-app-builtin-pi.php',
    'hypot-builtins' => 'fixtures/oracle-app-builtin-hypot.php',
    'is-finite-builtins' => 'fixtures/oracle-app-builtin-is-finite.php',
    'is-infinite-builtins' => 'fixtures/oracle-app-builtin-is-infinite.php',
    'is-nan-builtins' => 'fixtures/oracle-app-builtin-is-nan.php',
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
    ];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleMathBuiltinExecutor::execute($program, $family);
        $result['oracle'] = $oracle;
        $result['output'] = $oracle['output'] ?? null;
        $result['return'] = $oracle['return'] ?? null;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    }

    return $result;
}

foreach ($cases as $family => $relativeFixture) {
    $fixture = $root . '/' . $relativeFixture;
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

echo "PASS: Oracle executes math builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
