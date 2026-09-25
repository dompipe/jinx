<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebBackPageBridge.php';

use jinx\web\WebBackPageBridge;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$route = dirname(__DIR__) . '/fixtures/simple-web-api-validated.php';
$bridge = WebBackPageBridge::fromRoute($route, 'fast-template');

$success = json_decode($bridge->handleEnvelopeJson(json_encode([
    'method' => 'POST',
    'path' => '/api',
    'headers' => ['content-type' => 'application/json'],
    'body' => json_encode(['name' => 'jinx']),
]) ?: ''), true);

if (!is_array($success) || ($success['status'] ?? null) !== 200 || ($success['body'] ?? '') !== '{"ok":true,"name":"jinx"}') {
    fail('success back-page envelope mismatch: ' . json_encode($success));
}

$missing = json_decode($bridge->handleEnvelopeJson(json_encode([
    'method' => 'POST',
    'path' => '/api',
    'headers' => ['content-type' => 'application/json'],
    'body' => json_encode(['missing' => 'jinx']),
]) ?: ''), true);

if (!is_array($missing) || ($missing['status'] ?? null) !== 400 || ($missing['body'] ?? '') !== '{"ok":false,"error":"Missing name"}') {
    fail('missing-name back-page envelope mismatch: ' . json_encode($missing));
}

$health = json_decode($bridge->handleEnvelopeJson(json_encode([
    'method' => 'GET',
    'path' => '/__health',
    'headers' => [],
    'body' => '',
]) ?: ''), true);

if (!is_array($health) || ($health['status'] ?? null) !== 200 || !str_contains((string) ($health['body'] ?? ''), 'jinx-back-page')) {
    fail('health back-page envelope mismatch: ' . json_encode($health));
}

echo "PASS: WebBackPageBridge handles request/response envelopes" . PHP_EOL;
