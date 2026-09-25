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
    return [
        'unix' => (int) $micro,
        'millis' => (int) round(($micro - floor($micro)) * 1000),
        'stamp' => date('H:i:s', (int) $micro) . '.' . str_pad((string) ((int) round(($micro - floor($micro)) * 1000)), 3, '0', STR_PAD_LEFT),
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
    $state = [];
    $now = demo_now();
    $stored = demo_load_state();
    $page = (string) ($stored['page'] ?? 'feed.window');
    if (!in_array($page, ['feed.window', 'app.shell'], true)) {
        $page = 'feed.window';
    }

    $detail = demo_back_page_api_value((string) ($stored['detail'] ?? 'Detail from the back-page API'));
    $status = demo_back_page_api_value((string) ($stored['status'] ?? 'Status from the back-page API'));
    $revision = (int) ($stored['revision'] ?? 1);

    $overrides = [
        'title' => 'JINX no-JS island demo / ' . $page,
        'components' => [
            'detail' => [
                'kind' => 'slot',
                'text' => 'API-backed detail rev ' . $revision . ': ' . $detail . ' / island refresh ' . $now['stamp'],
            ],
            'status' => [
                'kind' => 'text',
                'text' => 'API-backed status rev ' . $revision . ': ' . $status . ' / island refresh ' . $now['stamp'],
            ],
            'main' => [
                'kind' => 'slot',
                'text' => 'Back-page API property is backing this parent frame too.',
            ],
        ],
    ];

    return $index->feedWindow($state, 'demo-window', $page, $overrides);
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
        demo_json_response(['ok' => true, 'state' => $state]);
        return;
    }

    demo_json_response(['ok' => true, 'state' => demo_load_state()]);
}

function demo_island_response(string $zone): void
{
    $window = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($_GET['window'] ?? 'demo-window')) ?: 'demo-window';
    $zone = preg_replace('/[^A-Za-z0-9_-]/', '-', $zone) ?: 'detail';
    $next = microtime(true) + 2.0;
    $refresh = '/island?window=' . rawurlencode($window)
        . '&zone=' . rawurlencode($zone)
        . '&_=' . rawurlencode((string) $next);

    header('Content-Type: text/html; charset=utf-8');
    demo_no_cache_headers();
    header('Refresh: 2; url=' . $refresh);

    echo WebNoJsIslandRegistrar::islandDocument(demo_index_frame(), $zone, $refresh, 2);
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

if ($path === '/definition') {
    header('Content-Type: text/plain; charset=utf-8');
    demo_no_cache_headers();
    echo "JINX no-JS live islands\n";
    echo "\n";
    echo "Definition:\n";
    echo "- These are standard same-origin iframes.\n";
    echo "- They are used as first-party JINX page islands, not third-party ad frames.\n";
    echo "- The parent page stays loaded. Each island document refreshes itself.\n";
    echo "- The island content is backed by a back-page/API state property.\n";
    echo "- Change /api/island-state and the island changes on its next refresh.\n";
    echo "- No app JavaScript, EventSource, or window runtime is needed.\n";
    echo "- The island endpoint sends no-cache headers and a browser-native Refresh header.\n";
    echo "- Full arbitrary parent-DOM mutation still needs browser-side execution; this mode avoids that by making the island its own small document.\n";
    return;
}

$state = demo_load_state();
$frame = demo_index_frame();
$islands = WebNoJsIslandRegistrar::islandSet('demo-window', '/island', ['detail', 'status']);

header('Content-Type: text/html; charset=utf-8');
demo_no_cache_headers();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JINX no-JS island demo</title>
<style>
:root { color-scheme: light dark; font-family: system-ui, -apple-system, Segoe UI, sans-serif; }
body { margin: 0; padding: 24px; line-height: 1.45; }
main { max-width: 1120px; margin: 0 auto; }
.hero { border: 1px solid #9995; border-radius: 18px; padding: 20px; margin-bottom: 18px; }
.grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 18px; align-items: start; }
.card { border: 1px solid #9995; border-radius: 18px; padding: 16px; background: color-mix(in srgb, Canvas 94%, CanvasText 6%); }
[data-jinx-no-js-island-set] { display: grid; gap: 12px; }
[data-jinx-no-js-island] {
  display: block;
  width: 100%;
  min-height: 128px;
  border: 0;
  border-radius: 14px;
  background: Canvas;
  box-shadow: inset 0 0 0 1px #9995;
}
label { display: block; font-weight: 650; margin: 10px 0 4px; }
input, select, button { font: inherit; width: 100%; box-sizing: border-box; border-radius: 10px; border: 1px solid #9998; padding: 9px 10px; background: Canvas; color: CanvasText; }
button { cursor: pointer; margin-top: 12px; font-weight: 700; }
code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
pre { overflow: auto; max-height: 420px; padding: 12px; border-radius: 12px; background: #0001; }
.badge { display: inline-block; border: 1px solid #9995; border-radius: 999px; padding: 3px 9px; margin: 0 6px 6px 0; font-size: 0.9rem; }
.note { opacity: .8; }
@media (max-width: 800px) { .grid { grid-template-columns: 1fr; } body { padding: 14px; } }
</style>
</head>
<body>
<main>
  <section class="hero">
    <h1>JINX no-JS live islands</h1>
    <p>This page uses standard iframes, but they are first-party JINX islands: borderless, same-origin, sandboxed, and styled like page components. The parent page does not refresh. Each island refreshes only its own tiny document every two seconds.</p>
    <p>The island text is backed by a back-page/API state property. Change it with the form or POST to <code>/api/island-state</code>; the islands pick it up on the next refresh.</p>
    <p>
      <span class="badge">No app JavaScript</span>
      <span class="badge">No EventSource</span>
      <span class="badge">No parent-page refresh</span>
      <span class="badge">Standard iframe primitive</span>
      <span class="badge">Back-page/API backed</span>
    </p>
  </section>

  <section class="grid">
    <article class="card">
      <h2>Working islands</h2>
      <p class="note">Watch the two boxes update independently. Submit the form, then watch them pick up the new API-backed property on their next refresh.</p>
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
      <p class="note">Form submission reloads the parent once because there is no JS. Direct API clients can POST JSON to the same route. The islands then refresh themselves from that state.</p>
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

  <section class="card" style="margin-top:18px">
    <h2>Current parent frame data</h2>
    <p class="note">This parent data only changes when the whole page is reloaded. The island boxes above change independently.</p>
    <pre><?= demo_html(json_encode($frame, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
  </section>
</main>
</body>
</html>
