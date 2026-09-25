<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleDateTimeBuiltinExecutor.php';

use jinx\oracle\OracleDateTimeBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-date-time-builtin';

date_default_timezone_set('UTC');

$cases = [
    'date-builtins' => "date('Y-m-d H:i:s', 1700000000)",
    'gmdate-builtins' => "gmdate('Y-m-d H:i:s', 1700000000)",
    'strtotime-builtins' => "strtotime('2020-01-02 03:04:05 UTC', 0)",
    'mktime-builtins' => "mktime(3, 4, 5, 1, 2, 2020)",
    'gmmktime-builtins' => "gmmktime(3, 4, 5, 1, 2, 2020)",
    'checkdate-builtins' => "checkdate(2, 29, 2020)",
    'idate-builtins' => "idate('Y', 1700000000)",
    'getdate-builtins' => "getdate(1700000000)",
    'localtime-builtins' => "localtime(1700000000, true)",
    'date-parse-builtins' => "date_parse('2020-01-02 03:04:05')",
    'date-parse-from-format-builtins' => "date_parse_from_format('Y-m-d H:i:s', '2020-01-02 03:04:05')",
    'timezone-name-from-abbr-builtins' => "timezone_name_from_abbr('UTC', 0, 0)",
    'timezone-version-get-builtins' => "timezone_version_get()",
    'timezone-open-builtins' => "timezone_name_get(timezone_open('UTC'))",
    'timezone-name-get-builtins' => "timezone_name_get(timezone_open('America/Detroit'))",
    'date-create-builtins' => "date_format(date_create('2020-01-02 03:04:05 UTC'), 'Y-m-d H:i:s T')",
    'date-format-builtins' => "date_format(date_create('2020-01-02 03:04:05 UTC'), 'c')",
    'date-timestamp-get-builtins' => "date_timestamp_get(date_create('2020-01-02 03:04:05 UTC'))",
    'date-timezone-get-builtins' => "timezone_name_get(date_timezone_get(date_create('2020-01-02 03:04:05 UTC')))",
    'timezone-offset-get-builtins' => "timezone_offset_get(timezone_open('UTC'), date_create('2020-01-02 03:04:05 UTC'))",
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
        "echo 'value=' . json_encode(\$value) . \"\\n\";\n" .
        'return $value;' . "\n";

    file_put_contents($path, $source);

    return $path;
}

function run_php_date_time_fixture(string $fixture): array
{
    date_default_timezone_set('UTC');
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

function run_oracle_date_time_fixture(string $fixture, string $family): array
{
    date_default_timezone_set('UTC');
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
        $oracle = OracleDateTimeBuiltinExecutor::execute($program, $family);
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
    $php = run_php_date_time_fixture($fixture);
    $oracle = run_oracle_date_time_fixture($fixture, $family);

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

echo "PASS: Oracle executes date/time builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
