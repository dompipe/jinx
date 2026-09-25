<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebWindowIndex.php';
require_once dirname(__DIR__) . '/runtime/WebNoJsIslandRegistrar.php';

use jinx\web\WebNoJsIslandRegistrar;
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

$script = WebWindowIndex::toBrowserScript($third);
foreach (['JINXWindowIndex.liveUpdate', 'window.__JINX_WINDOW_INDEX__', 'index_version', 'fingerprint', 'CustomEvent', 'jinx-window-frame', 'replaceText', 'Resident detail default updated'] as $needle) {
    if (!str_contains($script, $needle)) {
        fail('browser script missing expected browser-window programming marker: ' . $needle);
    }
}

$streamBoot = $index->toBrowserStreamBootScript('/jinx/window-stream', 'main-stream');
foreach (['EventSource', 'connectStream', '/jinx/window-stream', 'main-stream', 'jinx-window-stream-connected', 'jinx-window-frame'] as $needle) {
    if (!str_contains($streamBoot, $needle)) {
        fail('browser stream boot script missing expected marker: ' . $needle);
    }
}

$sse = WebWindowIndex::toServerSentEventFrame($third);
foreach (['event: jinx-window-frame', 'data: {', 'JINX_WINDOW_INDEX_FRAME', 'Resident detail default updated'] as $needle) {
    if (!str_contains($sse, $needle)) {
        fail('server-sent event frame missing expected marker: ' . $needle);
    }
}
if (!str_ends_with($sse, "\n\n")) {
    fail('server-sent event frame does not terminate with a blank line');
}

$registrarHtml = $index->toNoJsRegistrarHtml();
foreach (['data-jinx-no-js-registrar', 'data-jinx-index-json', 'data-jinx-page-default', 'Updated Resident Feed'] as $needle) {
    if (!str_contains($registrarHtml, $needle)) {
        fail('no-JS registrar HTML missing expected marker: ' . $needle);
    }
}
foreach (['<script', 'window.', 'JINXWindowIndex'] as $forbidden) {
    if (str_contains($registrarHtml, $forbidden)) {
        fail('no-JS registrar HTML contains forbidden script/runtime marker: ' . $forbidden);
    }
}

$frameHtml = WebWindowIndex::toNoJsFrameHtml($third);
foreach (['data-jinx-no-js-frame', 'data-jinx-window="main-window"', 'data-jinx-zone="detail"', 'Resident detail default updated', 'data-jinx-frame-json'] as $needle) {
    if (!str_contains($frameHtml, $needle)) {
        fail('no-JS frame HTML missing expected marker: ' . $needle);
    }
}
foreach (['<script', 'window.', 'JINXWindowIndex'] as $forbidden) {
    if (str_contains($frameHtml, $forbidden)) {
        fail('no-JS frame HTML contains forbidden script/runtime marker: ' . $forbidden);
    }
}

$documentHtml = $index->toNoJsDocumentHtml($third, '/feed.window', 1);
foreach (['<!doctype html>', 'data-jinx-no-js-document', 'http-equiv="refresh"', 'data-jinx-no-js-registrar', 'data-jinx-no-js-frame'] as $needle) {
    if (!str_contains($documentHtml, $needle)) {
        fail('no-JS document HTML missing expected marker: ' . $needle);
    }
}
foreach (['<script', 'window.', 'JINXWindowIndex'] as $forbidden) {
    if (str_contains($documentHtml, $forbidden)) {
        fail('no-JS document HTML contains forbidden script/runtime marker: ' . $forbidden);
    }
}

$islandFrame = WebNoJsIslandRegistrar::islandFrame('main-window', 'detail', '/jinx/island?window=main-window&zone=detail');
foreach (['<iframe', 'data-jinx-no-js-island', 'data-jinx-window="main-window"', 'data-jinx-zone="detail"', 'src="/jinx/island?window=main-window&amp;zone=detail"'] as $needle) {
    if (!str_contains($islandFrame, $needle)) {
        fail('no-JS island iframe missing expected marker: ' . $needle);
    }
}

$islandSet = WebNoJsIslandRegistrar::islandSet('main-window', '/jinx/island', ['detail', 'status']);
foreach (['data-jinx-no-js-island-set', 'zone=detail', 'zone=status', '<iframe'] as $needle) {
    if (!str_contains($islandSet, $needle)) {
        fail('no-JS island set missing expected marker: ' . $needle);
    }
}

$islandDocument = WebNoJsIslandRegistrar::islandDocument($third, 'detail', '/jinx/island?window=main-window&zone=detail', 1);
foreach (['<!doctype html>', 'data-jinx-no-js-island-document', 'http-equiv="refresh"', 'Resident detail default updated', 'data-jinx-island-frame-json'] as $needle) {
    if (!str_contains($islandDocument, $needle)) {
        fail('no-JS island document missing expected marker: ' . $needle);
    }
}
foreach (['<script', 'window.', 'JINXWindowIndex', 'EventSource'] as $forbidden) {
    if (str_contains($islandFrame . $islandSet . $islandDocument, $forbidden)) {
        fail('no-JS island output contains forbidden script/runtime marker: ' . $forbidden);
    }
}

$json = json_encode($third, JSON_UNESCAPED_SLASHES) ?: '';
foreach (['cookie', 'session', 'authorization', 'request_body'] as $forbidden) {
    if (str_contains(strtolower($json), $forbidden)) {
        fail('resident window frame contains forbidden request-state marker: ' . $forbidden);
    }
}

echo 'PASS: WebWindowIndex emits JINX stream runtime, no-JS registrar DOM frames, and no-JS live islands' . PHP_EOL;
