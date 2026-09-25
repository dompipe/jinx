<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebProgramCompiler.php';

use jinx\web\WebProgramCompiler;

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function requireOp(array $ops, string $op): void
{
    if (!in_array($op, $ops, true)) {
        fail("missing {$op}; got " . implode(', ', array_values(array_unique($ops))));
    }
}

$realFile = $root . '/fixtures/oracle-post-curl-dynamic.php';

if (!is_file($realFile)) {
    fail('missing fixtures/oracle-post-curl-dynamic.php');
}

$source = file_get_contents($realFile);

if (!is_string($source)) {
    fail('could not read fixture source');
}

$program = WebProgramCompiler::compileAnyPhpFileToWebProgram($realFile);

$ops = array_map(
    static fn(array $stmt): string => (string) $stmt['op'],
    $program['statements']
);

requireOp($ops, 'WEB_DECLARE_IGNORE');
requireOp($ops, 'WEB_FUNCTION_DECL');
requireOp($ops, 'WEB_IF');
requireOp($ops, 'WEB_ASSIGN');
requireOp($ops, 'WEB_RETURN');
requireOp($ops, 'WEB_RETURN_ARRAY');
requireOp($ops, 'WEB_ECHO');
requireOp($ops, 'WEB_READ_BODY');
requireOp($ops, 'WEB_JSON_DECODE');
requireOp($ops, 'WEB_JSON_ENCODE');
requireOp($ops, 'WEB_HEADER');
requireOp($ops, 'WEB_STATUS_CODE');
requireOp($ops, 'WEB_CURL_CALL');

if (is_string($source) && str_contains($source, '$_SERVER')) {
    requireOp($ops, 'WEB_SERVER_SUPERGLOBAL');
}

requireOp($ops, 'WEB_CALL');

echo "PASS: Web compiler identifies declare/echo/functions/calls/json/body/header/if/return-array/curl statements\n";
