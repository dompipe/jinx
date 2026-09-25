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
$initialResidentFingerprint = $bridge->residentStateFingerprint();
$safety = $bridge->residentSafetyContract();

if (($safety['resident_state'] ?? '') !== 'compiled_route_plan_and_template_only' || ($safety['per_request_state'] ?? '') !== 'fresh_isolated_context') {
    fail('resident safety contract is not explicit: ' . json_encode($safety));
}

foreach (['stores_request_body', 'stores_headers', 'stores_cookies', 'stores_session', 'stores_auth_state'] as $field) {
    if (($safety[$field] ?? true) !== false) {
        fail("resident safety contract permits unsafe field {$field}: " . json_encode($safety));
    }
}

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

$firstUser = json_decode($bridge->handleEnvelopeJson(json_encode([
    'method' => 'POST',
    'path' => '/api',
    'headers' => [
        'content-type' => 'application/json',
        'cookie' => 'session=first-user-secret',
        'authorization' => 'Bearer first-user-token',
    ],
    'body' => json_encode(['name' => 'first-user-secret-name']),
]) ?: ''), true);

$secondUser = json_decode($bridge->handleEnvelopeJson(json_encode([
    'method' => 'POST',
    'path' => '/api',
    'headers' => [
        'content-type' => 'application/json',
        'cookie' => 'session=second-user-secret',
        'authorization' => 'Bearer second-user-token',
    ],
    'body' => json_encode(['name' => 'second-user-name']),
]) ?: ''), true);

if (!is_array($firstUser) || ($firstUser['body'] ?? '') !== '{"ok":true,"name":"first-user-secret-name"}') {
    fail('first isolated user response mismatch: ' . json_encode($firstUser));
}

if (!is_array($secondUser) || ($secondUser['body'] ?? '') !== '{"ok":true,"name":"second-user-name"}') {
    fail('second isolated user response mismatch: ' . json_encode($secondUser));
}

if (str_contains((string) ($secondUser['body'] ?? ''), 'first-user-secret')) {
    fail('second response leaked first user request data: ' . json_encode($secondUser));
}

if ($bridge->residentStateFingerprint() !== $initialResidentFingerprint) {
    fail('resident frame changed after isolated user requests');
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

if ($bridge->residentStateFingerprint() !== $initialResidentFingerprint) {
    fail('resident frame changed after health request');
}

echo "PASS: WebBackPageBridge handles request/response envelopes with isolated per-request state" . PHP_EOL;
