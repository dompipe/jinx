<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebWindowIndex.php';
require_once dirname(__DIR__) . '/runtime/WebNoJsIslandRegistrar.php';
require_once dirname(__DIR__) . '/runtime/WebBackPageBridge.php';

use jinx\web\WebBackPageBridge;
use jinx\web\WebNoJsIslandRegistrar;
use jinx\web\WebWindowIndex;

function demo_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function demo_now(): array
{
    $micro = microtime(true);
    $millis = (int) round(($micro - floor($micro)) * 1000);
    return [
        'unix' => (int) $micro,
        'millis' => $millis,
        'stamp' => date('H:i:s', (int) $micro) . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT),
    ];
}

function demo_state_path(): string
{
    return sys_get_temp_dir() . '/jinx-no-js-island-demo-state.json';
}

function demo_default_state(): array
{
    return [
        'page' => 'feed.window',
        'detail' => 'Detail from the back-page API',
        'status' => 'Status from the back-page API',
        'revision' => 1,
        'updated_at' => demo_now()['stamp'],
    ];
}

function demo_load_state(): array
{
    $path = demo_state_path();
    if (!is_file($path)) {
        return demo_default_state();
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? array_replace(demo_default_state(), $decoded) : demo_default_state();
}

function demo_save_state(array $patch): array
{
    $state = demo_load_state();
    foreach (['page', 'detail', 'status'] as $key) {
        if (array_key_exists($key, $patch)) {
            $state[$key] = trim((string) $patch[$key]) !== '' ? trim((string) $patch[$key]) : (string) demo_default_state()[$key];
        }
    }
    if (!in_array((string) $state['page'], ['feed.window', 'app.shell'], true)) {
        $state['page'] = 'feed.window';
    }
    $state['revision'] = (int) ($state['revision'] ?? 0) + 1;
    $state['updated_at'] = demo_now()['stamp'];
    file_put_contents(demo_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
    return $state;
}

function demo_back_page_api_value(string $name): string
{
    static $bridge = null;
    if (!$bridge instanceof WebBackPageBridge) {
        $bridge = WebBackPageBridge::fromRouteFile(dirname(__DIR__) . '/fixtures/simple-web-api-validated.php');
    }

    $response = $bridge->handleRequestEnvelope([
        'method' => 'POST',
        'path' => '/api/island-value',
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode(['name' => $name], JSON_UNESCAPED_SLASHES) ?: '{}',
    ]);

    $body = json_decode((string) ($response['body'] ?? ''), true);
    if ((int) ($response['status'] ?? 500) !== 200 || !is_array($body) || ($body['ok'] ?? false) !== true) {
        return 'Back-page API failed';
    }

    return (string) ($body['name'] ?? '');
}

function demo_index_frame(): array
{
    $index = WebWindowIndex::withStandardDefaults();
    $windowState = [];
    $now = demo_now();
    $stored = demo_load_state();
    $page = (string) ($stored['page'] ?? 'feed.window');
    if (!in_array($page, ['feed.window', 'app.shell'], true)) {
        $page = 'feed.window';
    }

    $detail = demo_back_page_api_value((string) ($stored['detail'] ?? 'Detail from the back-page API'));
    $status = demo_back_page_api_value((string) ($stored['status'] ?? 'Status from the back-page API'));
    $revision = (int) ($stored['revision'] ?? 1);

    return $index->feedWindow($windowState, 'demo-window', $page, [
        'title' => 'JINX no-JS island demo / ' . $page,
        'components' => [
            'detail' => [
                'kind' => 'slot',
                'text' => 'Event refreshed detail rev ' . $revision . ': ' . $detail . ' / island render ' . $now['stamp'],
            ],
            'status' => [
                'kind' => 'text',
                'text' => 'Event refreshed status rev ' . $revision . ': ' . $status . ' / island render ' . $now['stamp'],
            ],
            'main' => [
                'kind' => 'slot',
                'text' => 'Back-page API property is backing this parent frame too.',
            ],
        ],
    ]);
}

function demo_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function demo_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    demo_no_cache_headers();
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function demo_handle_api_state(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'POST') {
        $payload = $_POST;
        if ($payload === []) {
            $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
        }
        $state = demo_save_state(is_array($payload) ? $payload : []);
        if (isset($_POST['from_form'])) {
            header('Location: /');
            return;
        }
        demo_json_response(['ok' => true, 'state' => $state, 'event' => 'jinx-island-refresh']);
        return;
    }

    demo_json_response(['ok' => true, 'state' => demo_load_state()]);
}

function demo_sse_send(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . (json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}') . "\n\n";
    @ob_flush();
    flush();
}

function demo_event_stream(): void
{
    $after = max(0, (int) ($_GET['after'] ?? 0));
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $started = microtime(true);
    while ((microtime(true) - $started) < 30.0) {
        $state = demo_load_state();
        $revision = (int) ($state['revision'] ?? 0);
        if ($revision > $after) {
            demo_sse_send('jinx-island-refresh', [
                'kind' => 'JINX_ISLAND_INVALIDATION',
                'revision' => $revision,
                'window' => 'demo-window',
                'zones' => ['detail', 'status'],
                'state' => $state,
            ]);
            return;
        }
        echo ": waiting for island revision greater than {$after}\n\n";
        @ob_flush();
        flush();
        usleep(250000);
    }

    demo_sse_send('jinx-island-heartbeat', [
        'kind' => 'JINX_ISLAND_HEARTBEAT',
        'revision' => (int) (demo_load_state()['revision'] ?? 0),
    ]);
}

function demo_island_response(string $zone): void
{
    $window = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($_GET['window'] ?? 'demo-window')) ?: 'demo-window';
    $zone = preg_replace('/[^A-Za-z0-9_-]/', '-', $zone) ?: 'detail';
    $eventMode = (string) ($_GET['mode'] ?? 'event') === 'event';
    $refresh = '/island?window=' . rawurlencode($window)
        . '&zone=' . rawurlencode($zone)
        . '&mode=' . ($eventMode ? 'event' : 'timed')
        . '&_=' . rawurlencode((string) microtime(true));

    header('Content-Type: text/html; charset=utf-8');
    demo_no_cache_headers();
    if (!$eventMode) {
        header('Refresh: 2; url=' . $refresh);
    }

    echo WebNoJsIslandRegistrar::islandDocument(demo_index_frame(), $zone, $eventMode ? null : $refresh, $eventMode ? 0 : 2);
}

function demo_event_islands(): string
{
    $html = '<section data-jinx-no-js-island-set="1" data-jinx-event-islands="1" data-jinx-window="demo-window">' . "\n";
    foreach (['detail', 'status'] as $zone) {
        $src = '/island?window=demo-window&zone=' . rawurlencode($zone) . '&mode=event&_=' . rawurlencode((string) microtime(true));
        $html .= WebNoJsIslandRegistrar::islandFrame('demo-window', $zone, $src, 'JINX event island ' . $zone) . "\n";
    }
    $html .= '</section>';
    return $html;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($path === '/island') {
    demo_island_response((string) ($_GET['zone'] ?? 'detail'));
    return;
}
if ($path === '/api/island-state') {
    demo_handle_api_state();
    return;
}
if ($path === '/events/island-state') {
    demo_event_stream();
    return;
}
if ($path === '/definition') {
    header('Content-Type: text/plain; charset=utf-8');
    demo_no_cache_headers();
    echo "JINX event-driven live islands\n\n";
    echo "Definition:\n";
    echo "- Iframe islands are standard same-origin first-party documents.\n";
    echo "- The island content is backed by a back-page/API state property.\n";
    echo "- An EventSource invalidation stream tells the parent when islands should reload.\n";
    echo "- Matching iframe island URLs are reloaded only when the revision changes.\n";
    echo "- The parent page does not poll on a timer and does not reload.\n";
    return;
}

$state = demo_load_state();
$frame = demo_index_frame();
$islands = demo_event_islands();
$revision = (int) ($state['revision'] ?? 0);

header('Content-Type: text/html; charset=utf-8');
demo_no_cache_headers();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JINX event-driven island demo</title>
<style>
:root { color-scheme: light dark; font-family: system-ui, -apple-system, Segoe UI, sans-serif; }
body { margin: 0; padding: 24px; line-height: 1.45; }
main { max-width: 1120px; margin: 0 auto; }
.hero { border: 1px solid #9995; border-radius: 18px; padding: 20px; margin-bottom: 18px; }
.grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 18px; align-items: start; }
.card { border: 1px solid #9995; border-radius: 18px; padding: 16px; background: color-mix(in srgb, Canvas 94%, CanvasText 6%); }
[data-jinx-no-js-island-set] { display: grid; gap: 12px; }
[data-jinx-no-js-island] { display: block; width: 100%; min-height: 128px; border: 0; border-radius: 14px; background: Canvas; box-shadow: inset 0 0 0 1px #9995; }
label { display: block; font-weight: 650; margin: 10px 0 4px; }
input, select, button { font: inherit; width: 100%; box-sizing: border-box; border-radius: 10px; border: 1px solid #9998; padding: 9px 10px; background: Canvas; color: CanvasText; }
button { cursor: pointer; margin-top: 12px; font-weight: 700; }
code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
pre { overflow: auto; max-height: 420px; padding: 12px; border-radius: 12px; background: #0001; }
.badge { display: inline-block; border: 1px solid #9995; border-radius: 999px; padding: 3px 9px; margin: 0 6px 6px 0; font-size: 0.9rem; }
.note { opacity: .8; }
#event-log { min-height: 1.4em; }
@media (max-width: 800px) { .grid { grid-template-columns: 1fr; } body { padding: 14px; } }
</style>
</head>
<body data-jinx-state-revision="<?= demo_html((string) $revision) ?>">
<main>
  <section class="hero">
    <h1>JINX event-driven live islands</h1>
    <p>The iframe islands below do not refresh by timer. The parent page listens to a JINX EventSource invalidation stream. When the back-page/API state revision changes, only the matching island iframe URLs reload.</p>
    <p>
      <span class="badge">EventSource invalidation</span>
      <span class="badge">No island timer</span>
      <span class="badge">No parent-page refresh</span>
      <span class="badge">Standard iframe primitive</span>
      <span class="badge">Back-page/API backed</span>
    </p>
    <p id="event-log" class="note">Waiting for JINX island invalidation events after revision <?= demo_html((string) $revision) ?>.</p>
  </section>

  <section class="grid">
    <article class="card">
      <h2>Event-driven islands</h2>
      <p class="note">POST JSON to the API from another terminal and watch these island boxes reload only after the event stream receives the changed revision.</p>
      <?= $islands ?>
    </article>

    <article class="card">
      <h2>Back-page/API property</h2>
      <form method="post" action="/api/island-state">
        <input type="hidden" name="from_form" value="1">
        <label for="page">Island page</label>
        <select id="page" name="page">
          <option value="feed.window"<?= (($state['page'] ?? '') === 'feed.window') ? ' selected' : '' ?>>feed.window</option>
          <option value="app.shell"<?= (($state['page'] ?? '') === 'app.shell') ? ' selected' : '' ?>>app.shell</option>
        </select>
        <label for="detail">Detail property</label>
        <input id="detail" name="detail" value="<?= demo_html((string) ($state['detail'] ?? '')) ?>">
        <label for="status">Status property</label>
        <input id="status" name="status" value="<?= demo_html((string) ($state['status'] ?? '')) ?>">
        <button type="submit">Save API property</button>
      </form>
      <p class="note">The form is still no-JS and reloads the parent once. For the event-driven demo, use curl from another terminal so the parent page remains open.</p>
      <pre>curl -X POST http://127.0.0.1:8099/api/island-state \
  -H 'Content-Type: application/json' \
  -d '{"detail":"Changed by API event","status":"Event pushed status"}'</pre>
      <p><a href="/api/island-state">Open current API state JSON</a></p>
      <p><a href="/definition">Open the plain definition</a></p>
    </article>
  </section>

  <section class="card" style="margin-top:18px">
    <h2>The exact iframe markup emitted</h2>
    <pre><?= demo_html($islands) ?></pre>
  </section>

  <section class="card" style="margin-top:18px">
    <h2>Current back-page/API state</h2>
    <pre><?= demo_html(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
  </section>
</main>
<script>
(function(){
  var log = document.getElementById('event-log');
  var revision = Number(document.body.getAttribute('data-jinx-state-revision') || '0');
  var source = null;
  function note(text) { if (log) log.textContent = text; }
  function reloadIslands(message) {
    var zones = Array.isArray(message.zones) ? message.zones : [];
    zones.forEach(function(zone) {
      document.querySelectorAll('iframe[data-jinx-no-js-island="1"][data-jinx-zone="' + String(zone).replace(/"/g, '') + '"]').forEach(function(frame) {
        var url = new URL(frame.getAttribute('src') || '/island', window.location.href);
        url.searchParams.set('mode', 'event');
        url.searchParams.set('_rev', String(message.revision || revision));
        url.searchParams.set('_event', String(Date.now()));
        frame.setAttribute('src', url.pathname + url.search);
      });
    });
  }
  function connect() {
    if (!window.EventSource) { note('EventSource is not available in this browser.'); return; }
    if (source) source.close();
    var url = '/events/island-state?after=' + encodeURIComponent(String(revision));
    source = new EventSource(url);
    note('Listening for JINX island invalidations after revision ' + revision + '.');
    source.addEventListener('jinx-island-refresh', function(event) {
      var message = {};
      try { message = JSON.parse(event.data || '{}'); } catch (e) { message = {}; }
      revision = Number(message.revision || revision);
      document.body.setAttribute('data-jinx-state-revision', String(revision));
      reloadIslands(message);
      note('JINX island invalidation received for revision ' + revision + '.');
      connect();
    });
    source.addEventListener('jinx-island-heartbeat', function(event) {
      note('Still listening for JINX island changes after revision ' + revision + '.');
      connect();
    });
    source.onerror = function() {
      note('JINX island event stream reconnecting...');
      try { source.close(); } catch (e) {}
      setTimeout(connect, 1200);
    };
  }
  connect();
})();
</script>
</body>
</html>
