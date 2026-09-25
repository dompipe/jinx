<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleRequestExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleRequestExecutor;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-request-globals.php';
$request = [
    '_SERVER' => [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/index.php?name=jinx',
        'SCRIPT_FILENAME' => $fixture,
        'DOCUMENT_ROOT' => dirname($fixture),
    ],
    '_GET' => [
        'name' => 'jinx',
        'page' => '1',
    ],
    '_POST' => [
        'token' => 'abc123',
    ],
];
$request['_REQUEST'] = array_merge($request['_GET'], $request['_POST']);

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

function run_php_request_fixture(string $fixture, array $request): array
{
    $__jinx_old_server = $_SERVER;
    $__jinx_old_get = $_GET;
    $__jinx_old_post = $_POST;
    $__jinx_old_request = $_REQUEST;

    $__jinx_capture_return = null;
    $__jinx_capture_error_class = null;
    $__jinx_capture_error_message = null;

    $_SERVER = $request['_SERVER'];
    $_GET = $request['_GET'];
    $_POST = $request['_POST'];
    $_REQUEST = $request['_REQUEST'];

    ob_start();
    try {
        $__jinx_capture_return = require $fixture;
    } catch (Throwable $e) {
        $__jinx_capture_error_class = $e::class;
        $__jinx_capture_error_message = $e->getMessage();
    } finally {
        $__jinx_capture_output = (string) ob_get_clean();
        $_SERVER = $__jinx_old_server;
        $_GET = $__jinx_old_get;
        $_POST = $__jinx_old_post;
        $_REQUEST = $__jinx_old_request;
    }

    return [
        'output' => $__jinx_capture_output,
        'return' => $__jinx_capture_return,
        'error_class' => $__jinx_capture_error_class,
        'error_message' => $__jinx_capture_error_message,
    ];
}

function run_oracle_request_fixture(string $fixture, array $request): array
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
        $oracle = OracleRequestExecutor::execute($program, $request);
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

$php = run_php_request_fixture($fixture, $request);
$oracle = run_oracle_request_fixture($fixture, $request);

same($oracle['error_class'], $php['error_class'], 'Oracle error class matches PHP');

if ($php['error_class'] !== null) {
    if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
        fail('Oracle error message does not include PHP error message: PHP=' . var_export($php['error_message'], true) . ', Oracle=' . var_export($oracle['error_message'], true));
    }

    echo "PASS: Oracle request globals PHP subset matches PHP error behavior" . PHP_EOL;
    exit(0);
}

same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['return'], $php['return'], 'Oracle return matches PHP');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'request-globals', 'Oracle execution family');

if (($oracle['oracle']['executed_ops'] ?? 0) < 6) {
    fail('Oracle executed too few request ops');
}

$ops = array_column($oracle['program']['statements'] ?? [], 'op');

foreach (['O_ASSIGN', 'O_COALESCE', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$source = implode("\n", array_map(
    static fn (array $statement): string => (string) ($statement['source'] ?? ''),
    array_filter($oracle['program']['statements'] ?? [], 'is_array')
));

foreach (['$_SERVER[', '$_GET[', '$_POST[', '$_REQUEST['] as $needle) {
    if (!str_contains($source, $needle)) {
        fail("fixture did not use expected request source {$needle}");
    }
}

echo "PASS: Oracle executes request globals PHP subset and matches PHP output/return/error behavior" . PHP_EOL;
