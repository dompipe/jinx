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
$versionBefore = $index->indexVersion();
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
same($first['index_version'] ?? null, $versionBefore, 'first frame index version');
same($first['arrangement']['components']['main']['text'] ?? null, 'First page body', 'first main text');
same($state['main-window']['page_key'] ?? null, 'app.shell', 'window state page key after first feed');
same($state['main-window']['index_version'] ?? null, $versionBefore, 'window state index version after first feed');

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
same($index->indexVersion(), $versionBefore, 'resident window index version stayed stable across feeds');

$index->patchPageDefault('feed.window', [
    'title' => 'Updated Resident Feed',
    'components' => [
        'detail' => ['text' => 'Resident detail default updated'],
        'status' => ['text' => 'updated-default'],
    ],
]);

if ($index->residentIndexFingerprint() === $fingerprintBefore) {
    fail('resident window index fingerprint did not change after explicit index patch');
}
same($index->indexVersion(), $versionBefore + 1, 'resident window index version increments after explicit index patch');

$third = $index->feedWindow($state, 'main-window', 'feed.window');
same($third['index_version'] ?? null, $versionBefore + 1, 'third frame uses updated index version');
same($third['arrangement']['title'] ?? null, 'Updated Resident Feed', 'third title comes from updated resident index');
same($third['arrangement']['components']['detail']['text'] ?? null, 'Resident detail default updated', 'third detail comes from updated resident index');
same($state['main-window']['index_version'] ?? null, $versionBefore + 1, 'window state tracks updated index version');

$snapshot = $index->browserIndexSnapshot();
same($snapshot['kind'] ?? null, 'JINX_BROWSER_WINDOW_INDEX', 'browser index snapshot kind');
same($snapshot['index_version'] ?? null, $versionBefore + 1, 'browser index snapshot version');
same($snapshot['defaults']['feed.window']['title'] ?? null, 'Updated Resident Feed', 'browser index snapshot updated default');

$registrationScript = $index->toBrowserRegistrationScript();
foreach (['JINXWindowIndex.registerIndex', 'JINX_BROWSER_WINDOW_INDEX', 'Updated Resident Feed', 'window.__JINX_WINDOW_INDEX__'] as $needle) {
    if (!str_contains($registrationScript, $needle)) {
        fail('browser registration script missing expected marker: ' . $needle);
    }
}

$runtimePath = dirname(__DIR__) . '/public/jinx-window-index.js';
$runtime = is_file($runtimePath) ? (string) file_get_contents($runtimePath) : '';
foreach (['window.JINXWindowIndex', 'registerIndex', 'liveUpdate', 'applyFrame', 'mount', 'querySelectorAll', 'jinx-window-frame'] as $needle) {
    if (!str_contains($runtime, $needle)) {
        fail('browser runtime missing expected live DOM/index marker: ' . $needle);
    }
}

$script = WebWindowIndex::toBrowserScript($third);
foreach (['JINXWindowIndex.liveUpdate', 'window.__JINX_WINDOW_INDEX__', 'index_version', 'fingerprint', 'CustomEvent', 'jinx-window-frame', 'replaceText', 'Resident detail default updated'] as $needle) {
    if (!str_contains($script, $needle)) {
        fail('browser script missing expected browser-window programming marker: ' . $needle);
    }
}

$json = json_encode($third, JSON_UNESCAPED_SLASHES) ?: '';
foreach (['cookie', 'session', 'authorization', 'request_body'] as $forbidden) {
    if (str_contains(strtolower($json), $forbidden)) {
        fail('resident window frame contains forbidden request-state marker: ' . $forbidden);
    }
}

echo 'PASS: WebWindowIndex registers mutable browser indexes and live DOM update frames' . PHP_EOL;
