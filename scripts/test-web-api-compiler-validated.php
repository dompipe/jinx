<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

$root = dirname(__DIR__);
$source = $root . '/fixtures/simple-web-api-validated.php';
$out = $root . '/build/web-compiled/simple-web-api-validated.compiled.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$plan = WebApiCompiler::compileSource((string) file_get_contents($source));

$ops = array_map(static fn(array $op): string => $op['op'], $plan['ops']);

$expected = [
    'WEB_READ_BODY_JSON',
    'WEB_IF_MISSING_ARRAY_KEY',
    'WEB_ARRAY_GET',
    'WEB_ECHO_JSON_ARRAY',
];

if ($ops !== $expected) {
    fail('unexpected web ops: ' . json_encode($ops));
}

$ifOp = $plan['ops'][1];

$thenOps = array_map(static fn(array $op): string => $op['op'], $ifOp['then']);

if ($thenOps !== ['WEB_STATUS_CODE', 'WEB_ECHO_JSON_ARRAY', 'WEB_RETURN']) {
    fail('unexpected if then ops: ' . json_encode($thenOps));
}

WebApiCompiler::compileFileToEndpoint($source, $out);

if (!is_file($out)) {
    fail('compiled endpoint was not written');
}

$compiled = (string) file_get_contents($out);

foreach ([
    'isset($data[',
    'http_response_code(400)',
    'Missing name',
    'json_encode',
    'Content-Type: application/json',
] as $needle) {
    if (!str_contains($compiled, $needle)) {
        fail("compiled endpoint missing {$needle}");
    }
}

echo "PASS: Web API compiler emits validated endpoint with if/status/error JSON/return\n";
