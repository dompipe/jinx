<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleSecurityNetworkBuiltinExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleSecurityNetworkBuiltinExecutor;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-security-network-builtin';

$cases = [
    'filter-var-email-builtins' => "filter_var('jinx@example.com', FILTER_VALIDATE_EMAIL)",
    'filter-var-int-builtins' => "filter_var('42', FILTER_VALIDATE_INT)",
    'filter-id-builtins' => "filter_id('validate_email')",
    'filter-list-builtins' => 'filter_list()',
    'hash-algos-builtins' => 'hash_algos()',
    'hash-equals-builtins' => "hash_equals('oracle', 'oracle')",
    'hash-hkdf-builtins' => "hash_hkdf('sha256', 'input-key', 16, 'jinx-info', 'jinx-salt')",
    'hash-pbkdf2-builtins' => "hash_pbkdf2('sha256', 'password', 'salt', 10, 16)",
    'password-get-info-builtins' => "password_get_info('not-a-password-hash')",
    'password-needs-rehash-builtins' => "password_needs_rehash('not-a-password-hash', PASSWORD_BCRYPT)",
    'password-verify-builtins' => "password_verify('password', 'not-a-password-hash')",
    'inet-pton-builtins' => "bin2hex(inet_pton('127.0.0.1'))",
    'inet-ntop-builtins' => "inet_ntop(hex2bin('7f000001'))",
    'ip2long-builtins' => "ip2long('127.0.0.1')",
    'long2ip-builtins' => 'long2ip(2130706433)',
    'extension-loaded-builtins' => "extension_loaded('json')",
    'get-loaded-extensions-builtins' => 'get_loaded_extensions()',
    'get-extension-funcs-builtins' => "in_array('json_encode', get_extension_funcs('json'), true)",
    'phpversion-builtins' => 'phpversion()',
    'version-compare-builtins' => "version_compare('8.2.0', '8.1.0')",
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

function run_php_security_network_fixture(string $fixture): array
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

function run_oracle_security_network_fixture(string $fixture, string $family): array
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
        $oracle = OracleSecurityNetworkBuiltinExecutor::execute($program, $family);
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
    $php = run_php_security_network_fixture($fixture);
    $oracle = run_oracle_security_network_fixture($fixture, $family);

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

echo "PASS: Oracle executes security/network builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
