<?php

declare(strict_types=1);

// Proof facets exercised by this parity test: http_route_table back_page_api_bridge resident_island_state window_index_frame iframe_island_render response_envelope

require_once dirname(__DIR__) . '/runtime/WebWindowIndex.php';
require_once dirname(__DIR__) . '/runtime/WebNoJsIslandRegistrar.php';
require_once dirname(__DIR__) . '/runtime/WebBackPageBridge.php';
require_once dirname(__DIR__) . '/runtime/OracleJinxIslandServerExecutor.php';

use jinx\oracle\OracleJinxIslandServerExecutor;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        fail("{$label} missing {$needle}:\n{$haystack}");
    }
}

function assert_response(array $response, int $status, string $needle, string $label): void
{
    if (($response['status'] ?? null) !== $status) {
        fail("{$label} status mismatch: " . json_encode($response));
    }
    assert_contains((string) ($response['body'] ?? ''), $needle, $label);
}

$route = dirname(__DIR__) . '/fixtures/simple-web-api-validated.php';
$server = OracleJinxIslandServerExecutor::withRoute($route);
$plan = OracleJinxIslandServerExecutor::oraclePlan();

foreach ([
    'O_HTTP_ROUTE_TABLE',
    'O_BACK_PAGE_API_BRIDGE',
    'O_RESIDENT_ISLAND_STATE',
    'O_WINDOW_INDEX_FRAME',
    'O_IFRAME_ISLAND_RENDER',
    'O_EVENTSOURCE_INVALIDATION',
    'O_RESPONSE_ENVELOPE',
] as $op) {
    if (!in_array($op, $plan['ops'] ?? [], true)) {
        fail("oracle plan missing op {$op}: " . json_encode($plan));
    }
}

foreach (['GET /', 'GET /island', 'GET /events/island-state', 'POST /api/island-state'] as $routeName) {
    if (!in_array($routeName, $plan['routes'] ?? [], true)) {
        fail("oracle plan missing route {$routeName}: " . json_encode($plan));
    }
}

$safety = $server->residentSafetyContract();
foreach (['stores_request_body', 'stores_headers', 'stores_cookies', 'stores_auth_state'] as $field) {
    if (($safety[$field] ?? true) !== false) {
        fail("resident safety contract permits unsafe field {$field}: " . json_encode($safety));
    }
}
if (($safety['supports_eventsource_invalidation'] ?? false) !== true || ($safety['supports_iframe_island_refresh'] ?? false) !== true) {
    fail('resident safety contract does not expose island/event support: ' . json_encode($safety));
}

$initialFingerprint = $server->residentStateFingerprint();

$health = $server->handleRequestEnvelope(['method' => 'GET', 'path' => '/__health', 'headers' => [], 'body' => '']);
assert_response($health, 200, 'jinx-island-server-oracle', 'health');

$parent = $server->handleRequestEnvelope(['method' => 'GET', 'path' => '/', 'headers' => ['authorization' => 'Bearer first-secret'], 'body' => '']);
assert_response($parent, 200, 'JINX Oracle island server', 'parent');
assert_contains($parent['body'], 'EventSource', 'parent runtime');
assert_contains($parent['body'], 'data-jinx-no-js-island', 'parent iframe island');
assert_contains($parent['body'], '/events/island-state', 'parent eventsource route');

$island = $server->handleRequestEnvelope(['method' => 'GET', 'path' => '/island?zone=detail&mode=event', 'headers' => ['cookie' => 'first-user-secret'], 'body' => '']);
assert_response($island, 200, 'data-jinx-no-js-island-document', 'detail island');
assert_contains($island['body'], 'Oracle detail rev 1', 'detail island state');
assert_contains($island['body'], 'Detail from the Oracle back-page API', 'detail back-page value');

$heartbeat = $server->handleRequestEnvelope(['method' => 'GET', 'path' => '/events/island-state?after=1', 'headers' => [], 'body' => '']);
assert_response($heartbeat, 200, 'event: jinx-island-heartbeat', 'heartbeat');
assert_contains($heartbeat['body'], 'JINX_ISLAND_HEARTBEAT', 'heartbeat payload');

if ($server->residentStateFingerprint() !== $initialFingerprint) {
    fail('read-only parent/island/event requests changed resident state');
}

$update = $server->handleRequestEnvelope([
    'method' => 'POST',
    'path' => '/api/island-state',
    'headers' => [
        'content-type' => 'application/json',
        'authorization' => 'Bearer should-not-be-stored',
        'cookie' => 'session=should-not-be-stored',
    ],
    'body' => json_encode(['detail' => 'Changed by Oracle API', 'status' => 'Event pushed by Oracle', 'page' => 'feed.window']),
]);
assert_response($update, 200, 'jinx-island-refresh', 'state update');
assert_contains($update['body'], 'Changed by Oracle API', 'state update detail');

if ($server->residentStateFingerprint() === $initialFingerprint) {
    fail('state-changing API request did not update resident state fingerprint');
}

$event = $server->handleRequestEnvelope(['method' => 'GET', 'path' => '/events/island-state?after=1', 'headers' => [], 'body' => '']);
assert_response($event, 200, 'event: jinx-island-refresh', 'invalidation event');
assert_contains($event['body'], 'JINX_ISLAND_INVALIDATION', 'invalidation payload');
assert_contains($event['body'], '"zones":["detail","status"]', 'invalidation zones');

$changedIsland = $server->handleRequestEnvelope(['method' => 'GET', 'path' => '/island?zone=detail&mode=event', 'headers' => [], 'body' => '']);
assert_response($changedIsland, 200, 'Changed by Oracle API', 'changed detail island');
assert_contains($changedIsland['body'], 'Oracle detail rev 2', 'changed detail revision');

$state = $server->currentState();
foreach (['should-not-be-stored', 'first-user-secret'] as $forbidden) {
    if (str_contains(json_encode($state, JSON_UNESCAPED_SLASHES) ?: '', $forbidden)) {
        fail('request-local secret leaked into resident state: ' . json_encode($state));
    }
}

echo 'PASS: Oracle JINX island server executes route table, back-page API state, iframe island render, and EventSource invalidation' . PHP_EOL;
