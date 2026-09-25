<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebWindowIndex.php';
require_once dirname(__DIR__) . '/runtime/WebNoJsIslandRegistrar.php';

use jinx\web\WebNoJsIslandRegistrar;
use jinx\web\WebWindowIndex;

function demo_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function demo_index_frame(): array
{
    $index = WebWindowIndex::withStandardDefaults();
    $state = [];
    $tick = time();

    return $index->feedWindow($state, 'demo-window', 'feed.window', [
        'title' => 'JINX no-JS island demo',
        'components' => [
            'detail' => [
                'kind' => 'slot',
                'text' => 'Detail island refreshed by its own browser-native document at ' . date('H:i:s', $tick),
            ],
            'status' => [
                'kind' => 'text',
                'text' => 'Status island tick: ' . $tick,
            ],
        ],
    ]);
}

function demo_island_response(string $zone): void
{
    $window = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($_GET['window'] ?? 'demo-window')) ?: 'demo-window';
    $zone = preg_replace('/[^A-Za-z0-9_-]/', '-', $zone) ?: 'detail';
    $refresh = '/island?window=' . rawurlencode($window) . '&zone=' . rawurlencode($zone);

    header('Content-Type: text/html; charset=utf-8');
    echo WebNoJsIslandRegistrar::islandDocument(demo_index_frame(), $zone, $refresh, 2);
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($path === '/island') {
    demo_island_response((string) ($_GET['zone'] ?? 'detail'));
    return;
}

if ($path === '/definition') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "JINX no-JS live islands\n";
    echo "\n";
    echo "Definition:\n";
    echo "- These are standard same-origin iframes.\n";
    echo "- They are used as first-party JINX page islands, not third-party ad frames.\n";
    echo "- The parent page stays loaded. Each island document refreshes itself.\n";
    echo "- No app JavaScript, EventSource, or window runtime is needed.\n";
    echo "- Full arbitrary parent-DOM mutation still needs browser-side execution; this mode avoids that by making the island its own small document.\n";
    return;
}

$frame = demo_index_frame();
$islands = WebNoJsIslandRegistrar::islandSet('demo-window', '/island', ['detail', 'status']);
$definition = trim((string) file_get_contents(__FILE__));

header('Content-Type: text/html; charset=utf-8');
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
    <p>
      <span class="badge">No app JavaScript</span>
      <span class="badge">No EventSource</span>
      <span class="badge">No parent-page refresh</span>
      <span class="badge">Standard iframe primitive</span>
    </p>
  </section>

  <section class="grid">
    <article class="card">
      <h2>Working islands</h2>
      <p class="note">Watch the two boxes update independently. View source and you will see ordinary iframe tags, not ad scripts.</p>
      <?= $islands ?>
    </article>

    <article class="card">
      <h2>Definition</h2>
      <p><strong>What these are:</strong> standard same-origin iframes used as browser-native JINX document islands.</p>
      <p><strong>Why they are not the old ugly ad-frame model:</strong> they are first-party, styled borderless, sandboxed, and controlled by your server. They are page components, not random third-party embeds.</p>
      <p><strong>Limit:</strong> no-JS mode cannot mutate the parent DOM in place. It works by making each island its own small browser document that can refresh itself.</p>
      <p><a href="/definition">Open the plain definition</a></p>
    </article>
  </section>

  <section class="card" style="margin-top:18px">
    <h2>The exact iframe markup emitted</h2>
    <pre><?= demo_html($islands) ?></pre>
  </section>

  <section class="card" style="margin-top:18px">
    <h2>Current frame data</h2>
    <pre><?= demo_html(json_encode($frame, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
  </section>
</main>
</body>
</html>
