<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failStreamContext(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failStreamContext('repository-root native ./jinx missing or not executable');
}

$required = [
    'stream_context_create',
    'stream_context_get_default',
    'stream_context_get_options',
    'stream_context_get_params',
    'stream_context_set_default',
    'stream_context_set_option',
    'stream_context_set_options',
    'stream_context_set_params',
];
foreach ($required as $function) {
    if (!function_exists($function)) {
        failStreamContext("PHP runtime missing required {$function}() contract");
    }
}

$options = [
    'http' => [
        'method' => 'GET',
        'timeout' => 3.5,
    ],
];

$context = stream_context_create($options);
if (!is_resource($context) ||
    get_resource_type($context) !== 'stream-context') {
    failStreamContext('PHP stream_context_create did not return a stream-context resource');
}

if (stream_context_get_options($context) !== $options) {
    failStreamContext('PHP stream_context_get_options initial payload mismatch');
}

if (!stream_context_set_option(
        $context,
        'http',
        'header',
        'X-Test: 1'
    )) {
    failStreamContext('PHP stream_context_set_option failed');
}

if (!stream_context_set_options(
        $context,
        ['ssl' => ['verify_peer' => false]]
    )) {
    failStreamContext('PHP stream_context_set_options failed');
}

$expectedOptions = [
    'http' => [
        'method' => 'GET',
        'timeout' => 3.5,
        'header' => 'X-Test: 1',
    ],
    'ssl' => [
        'verify_peer' => false,
    ],
];

if (stream_context_get_options($context) !== $expectedOptions) {
    failStreamContext('PHP stream context merged options mismatch');
}

if (!stream_context_set_params($context, [])) {
    failStreamContext('PHP stream_context_set_params empty update failed');
}

$params = stream_context_get_params($context);
if (!is_array($params) ||
    !array_key_exists('options', $params) ||
    $params['options'] !== $expectedOptions) {
    failStreamContext('PHP stream_context_get_params options payload mismatch');
}

$default = stream_context_set_default([
    'http' => ['user_agent' => 'jinx'],
]);
if (!is_resource($default) ||
    get_resource_type($default) !== 'stream-context') {
    failStreamContext('PHP stream_context_set_default did not return a stream-context resource');
}

$defaultAgain = stream_context_get_default();
if (!is_resource($defaultAgain) ||
    get_resource_type($defaultAgain) !== 'stream-context') {
    failStreamContext('PHP stream_context_get_default did not return a stream-context resource');
}
$defaultOptions = stream_context_get_options($defaultAgain);
if (($defaultOptions['http']['user_agent'] ?? null) !== 'jinx') {
    failStreamContext('PHP default stream context did not persist options');
}

$out = [];
$code = 0;
exec(
    escapeshellarg($jinx) . ' stream-context-smoke 2>&1',
    $out,
    $code
);
$text = rtrim(implode(PHP_EOL, $out), "\r\n");

$expected = 'PASS: native stream_context create/get/set/default lifecycle';
if ($code !== 0 || $text !== $expected) {
    failStreamContext(
        "native stream context lifecycle mismatch\n" .
        "Expected: {$expected}\nJINX: {$text}"
    );
}

echo 'PASS: native stream_context family matches PHP lifecycle semantics' . PHP_EOL;
