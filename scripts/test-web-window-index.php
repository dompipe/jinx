<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebWindowIndex.php';

use jinx\web\WebWindowIndex;

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

$index = WebWindowIndex::withStandardDefaults();
$fingerprintBefore = $index->residentIndexFingerprint();
$state = [];

$first = $index->feedWindow($state, 'main-window', 'app.shell', [
    'components' => [
        'main' => ['text' => 'First page body'],
        'status' => ['text' => 'first-feed'],
    ],
]);

same($first['kind'] ?? null, 'JINX_WINDOW_INDEX_FRAME', 'first frame kind');
same($first['window_id'] ?? null, 'main-window', 'first frame window id');
same($first['page_key'] ?? null, 'app.shell', 'first frame page key');
same($first['arrangement']['components']['main']['text'] ?? null, 'First page body', 'first main text');
same($state['main-window']['page_key'] ?? null, 'app.shell', 'window state page key after first feed');

$second = $index->feedWindow($state, 'main-window', 'feed.window', [
    'title' => 'Live Feed',
    'components' => [
        'detail' => ['text' => 'Second page detail'],
        'status' => ['text' => 'second-feed'],
    ],
]);

same($second['page_key'] ?? null, 'feed.window', 'second frame page key');
same($second['arrangement']['title'] ?? null, 'Live Feed', 'second title override');
same($second['arrangement']['components']['detail']['text'] ?? null, 'Second page detail', 'second detail text');
same($state['main-window']['page_key'] ?? null, 'feed.window', 'window state page key after second feed');

if (($second['arrangement']['components']['main']['text'] ?? null) === 'First page body') {
    fail('first page component leaked into second page arrangement');
}

same($index->residentIndexFingerprint(), $fingerprintBefore, 'resident window index fingerprint stayed stable across feeds');

$script = WebWindowIndex::toBrowserScript($second);
foreach (['window.__JINX_WINDOW_INDEX__', 'CustomEvent', 'jinx-window-frame', 'replaceText', 'Second page detail'] as $needle) {
    if (!str_contains($script, $needle)) {
        fail('browser script missing expected browser-window programming marker: ' . $needle);
    }
}

$json = json_encode($second, JSON_UNESCAPED_SLASHES) ?: '';
foreach (['cookie', 'session', 'authorization', 'request_body'] as $forbidden) {
    if (str_contains(strtolower($json), $forbidden)) {
        fail('resident window frame contains forbidden request-state marker: ' . $forbidden);
    }
}

echo 'PASS: WebWindowIndex feeds resident page defaults into isolated browser window frames' . PHP_EOL;
