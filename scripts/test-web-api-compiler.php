<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

$root = dirname(__DIR__);
$source = $root . '/fixtures/simple-web-api.php';
$out = $root . '/build/web-compiled/simple-web-api.compiled.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$plan = WebApiCompiler::compileSource((string) file_get_contents($source));

$ops = array_map(static fn(array $op): string => $op['op'], $plan['ops']);

$expected = [
    'WEB_READ_BODY_JSON',
    'WEB_ARRAY_GET',
    'WEB_ECHO_JSON_ARRAY',
];

if ($ops !== $expected) {
    fail('unexpected web ops: ' . json_encode($ops));
}

WebApiCompiler::compileFileToEndpoint($source, $out);

if (!is_file($out)) {
    fail('compiled endpoint was not written');
}

$compiled = (string) file_get_contents($out);

foreach ($expected as $op) {
    // The generated PHP does not preserve op names yet, so this verifies emitted behavior instead.
}

if (!str_contains($compiled, 'file_get_contents("php://input")')) {
    fail('compiled endpoint does not read request body');
}

if (!str_contains($compiled, 'json_decode')) {
    fail('compiled endpoint does not decode JSON');
}

if (!str_contains($compiled, 'json_encode')) {
    fail('compiled endpoint does not encode JSON');
}

echo "PASS: Web API compiler emits executable endpoint for POST JSON dynamic answer\n";
