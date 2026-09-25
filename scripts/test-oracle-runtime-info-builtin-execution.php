<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleRuntimeInfoBuiltinExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleRuntimeInfoBuiltinExecutor;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-runtime-info-builtin';

$cases = [
    'php-uname-builtins' => "php_uname('s')",
    'php-sapi-name-builtins' => 'php_sapi_name()',
    'zend-version-builtins' => 'zend_version()',
    'ini-get-builtins' => "ini_get('precision')",
    'ini-get-all-builtins' => 'ini_get_all(null, false)',
    'get-cfg-var-builtins' => "get_cfg_var('cfg_file_path')",
    'php-ini-loaded-file-builtins' => 'php_ini_loaded_file()',
    'php-ini-scanned-files-builtins' => 'php_ini_scanned_files()',
    'get-include-path-builtins' => 'get_include_path()',
    'stream-get-wrappers-builtins' => 'stream_get_wrappers()',
    'stream-get-transports-builtins' => 'stream_get_transports()',
    'stream-get-filters-builtins' => 'stream_get_filters()',
    'sys-get-temp-dir-builtins' => 'sys_get_temp_dir()',
    'get-current-user-builtins' => 'get_current_user()',
    'getmyuid-builtins' => 'getmyuid()',
    'getmygid-builtins' => 'getmygid()',
    'getmypid-builtins' => 'getmypid()',
    'getmyinode-builtins' => 'getmyinode()',
    'getlastmod-builtins' => 'getlastmod()',
    'umask-builtins' => 'umask()',
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
        '$encoded = json_encode($value);' . "\n" .
        "echo 'value=' . \$encoded . \"\\n\";\n" .
        'return $encoded;' . "\n";

    file_put_contents($path, $source);

    return $path;
}

function run_php_runtime_info_fixture(string $fixture): array
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

function run_oracle_runtime_info_fixture(string $fixture, string $family): array
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
        $oracle = OracleRuntimeInfoBuiltinExecutor::execute($program, $family);
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
    $php = run_php_runtime_info_fixture($fixture);
    $oracle = run_oracle_runtime_info_fixture($fixture, $family);

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

echo "PASS: Oracle executes runtime-info builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
