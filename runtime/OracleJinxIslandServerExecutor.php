<?php

declare(strict_types=1);

namespace jinx\oracle;

require_once __DIR__ . '/WebWindowIndex.php';
require_once __DIR__ . '/WebNoJsIslandRegistrar.php';
require_once __DIR__ . '/WebBackPageBridge.php';

use jinx\web\WebBackPageBridge;
use jinx\web\WebNoJsIslandRegistrar;
use jinx\web\WebWindowIndex;

/**
 * Oracle plan for a JINX-native island web server.
 *
 * This is not a socket accept loop. It is the executable Oracle request plan
 * that the native web worker should own: route dispatch, resident island state,
 * back-page/API execution, iframe island HTML, and EventSource invalidation.
 */
final class OracleJinxIslandServerExecutor
{
    /** @var array{page:string,detail:string,status:string,revision:int,updated_at:string} */
    private array $state;

    private WebWindowIndex $index;
    private WebBackPageBridge $backPage;

    private function __construct(WebBackPageBridge $backPage, ?WebWindowIndex $index = null)
    {
        $this->backPage = $backPage;
        $this->index = $index ?? WebWindowIndex::withStandardDefaults();
        $this->state = self::defaultState();
    }

    public static function withRoute(string $routePath): self
    {
        return new self(WebBackPageBridge::fromRouteFile($routePath));
    }

    /** @return array<string,mixed> */
    public static function oraclePlan(): array
    {
        return [
            'kind' => 'JINX_ISLAND_SERVER_ORACLE_PLAN',
            'ops' => [
                'O_HTTP_ROUTE_TABLE',
                'O_BACK_PAGE_API_BRIDGE',
                'O_RESIDENT_ISLAND_STATE',
                'O_WINDOW_INDEX_FRAME',
                'O_IFRAME_ISLAND_RENDER',
                'O_EVENTSOURCE_INVALIDATION',
                'O_RESPONSE_ENVELOPE',
            ],
            'routes' => [
                'GET /',
                'GET /island',
                'GET /events/island-state',
                'GET /api/island-state',
                'POST /api/island-state',
                'GET /__health',
            ],
            'resident_state' => [
                'window_index',
                'compiled_back_page_bridge',
                'island_state_revision',
            ],
            'per_request_state' => [
                'method',
                'path',
                'query',
                'headers',
                'body',
            ],
        ];
    }

    /** @return array<string,string|bool> */
    public function residentSafetyContract(): array
    {
        return [
            'resident_state' => 'window_index_compiled_back_page_bridge_and_island_revision',
            'per_request_state' => 'fresh_isolated_http_request_context',
            'stores_request_body' => false,
            'stores_headers' => false,
            'stores_cookies' => false,
            'stores_auth_state' => false,
            'supports_eventsource_invalidation' => true,
            'supports_iframe_island_refresh' => true,
        ];
    }

    public function residentStateFingerprint(): string
    {
        return hash('sha256', serialize([
            'state' => $this->state,
            'index' => $this->index->residentIndexFingerprint(),
            'back_page' => $this->backPage->residentStateFingerprint(),
        ]));
    }

    /** @return array{page:string,detail:string,status:string,revision:int,updated_at:string} */
    public function currentState(): array
    {
        return $this->state;
    }

    /** @param array<string,mixed> $request @return array{status:int,headers:array<string,string>,body:string} */
    public function handleRequestEnvelope(array $request): array
    {
        $context = self::isolatedRequestContext($request);
        $method = strtoupper($context['method']);
        $path = $context['path'];

        if ($method === 'GET' && $path === '/__health') {
            return self::jsonEnvelope(200, ['ok' => true, 'worker' => 'jinx-island-server-oracle']);
        }
        if ($method === 'GET' && $path === '/') {
            return self::htmlEnvelope($this->renderParentDocument());
        }
        if ($method === 'GET' && $path === '/island') {
            $zone = (string) ($context['query']['zone'] ?? 'detail');
            return self::htmlEnvelope($this->renderIslandDocument($zone));
        }
        if ($method === 'GET' && $path === '/events/island-state') {
            $after = (int) ($context['query']['after'] ?? 0);
            return self::sseEnvelope($this->renderEventStreamFrame($after));
        }
        if ($path === '/api/island-state') {
            if ($method === 'GET') {
                return self::jsonEnvelope(200, ['ok' => true, 'state' => $this->state]);
            }
            if ($method === 'POST') {
                $patch = json_decode($context['body'], true);
                if (!is_array($patch)) {
                    $patch = [];
                }
                $state = $this->patchState($patch);
                return self::jsonEnvelope(200, [
                    'ok' => true,
                    'state' => $state,
                    'event' => 'jinx-island-refresh',
                ]);
            }
        }

        return self::jsonEnvelope(404, ['ok' => false, 'error' => 'Not found']);
    }

    /** @param array<string,mixed> $patch @return array{page:string,detail:string,status:string,revision:int,updated_at:string} */
    private function patchState(array $patch): array
    {
        foreach (['detail', 'status'] as $key) {
            if (array_key_exists($key, $patch)) {
                $value = trim((string) $patch[$key]);
                $this->state[$key] = $value !== '' ? $value : self::defaultState()[$key];
            }
        }

        if (array_key_exists('page', $patch)) {
            $page = (string) $patch['page'];
            $this->state['page'] = in_array($page, ['feed.window', 'app.shell'], true) ? $page : 'feed.window';
        }

        $this->state['revision']++;
        $this->state['updated_at'] = self::stamp();
        return $this->state;
    }

    private function renderParentDocument(): string
    {
        $revision = (string) $this->state['revision'];
        $detail = WebNoJsIslandRegistrar::islandFrame('demo-window', 'detail', '/island?window=demo-window&zone=detail&mode=event', 'JINX Oracle detail island');
        $status = WebNoJsIslandRegistrar::islandFrame('demo-window', 'status', '/island?window=demo-window&zone=status&mode=event', 'JINX Oracle status island');

        return '<!doctype html><html><head><meta charset="utf-8"><title>JINX Oracle island server</title></head>'
            . '<body data-jinx-state-revision="' . self::html($revision) . '">'
            . '<h1>JINX Oracle island server</h1>'
            . '<p>EventSource invalidation backs iframe island refresh.</p>'
            . '<section data-jinx-event-islands="1">' . $detail . $status . '</section>'
            . '<script>' . self::eventSourceRuntime() . '</script>'
            . '</body></html>';
    }

    private function renderIslandDocument(string $zone): string
    {
        $safeZone = preg_replace('/[^A-Za-z0-9_-]/', '-', $zone) ?: 'detail';
        return WebNoJsIslandRegistrar::islandDocument($this->frame(), $safeZone, null, 0);
    }

    private function renderEventStreamFrame(int $after): string
    {
        $revision = $this->state['revision'];
        if ($revision <= $after) {
            return self::sse('jinx-island-heartbeat', [
                'kind' => 'JINX_ISLAND_HEARTBEAT',
                'revision' => $revision,
            ]);
        }

        return self::sse('jinx-island-refresh', [
            'kind' => 'JINX_ISLAND_INVALIDATION',
            'revision' => $revision,
            'window' => 'demo-window',
            'zones' => ['detail', 'status'],
            'state' => $this->state,
        ]);
    }

    /** @return array<string,mixed> */
    private function frame(): array
    {
        $windowState = [];
        $detail = $this->backPageValue($this->state['detail']);
        $status = $this->backPageValue($this->state['status']);
        $revision = $this->state['revision'];

        return $this->index->feedWindow($windowState, 'demo-window', $this->state['page'], [
            'title' => 'JINX Oracle island server / ' . $this->state['page'],
            'components' => [
                'detail' => [
                    'kind' => 'slot',
                    'text' => 'Oracle detail rev ' . $revision . ': ' . $detail,
                ],
                'status' => [
                    'kind' => 'text',
                    'text' => 'Oracle status rev ' . $revision . ': ' . $status,
                ],
                'main' => [
                    'kind' => 'slot',
                    'text' => 'JINX webserver oracle owns this frame.',
                ],
            ],
        ]);
    }

    private function backPageValue(string $value): string
    {
        $response = $this->backPage->handleRequestEnvelope([
            'method' => 'POST',
            'path' => '/api/island-value',
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['name' => $value], JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
        $body = json_decode((string) ($response['body'] ?? ''), true);
        return is_array($body) && ($body['ok'] ?? false) === true ? (string) ($body['name'] ?? '') : 'Back-page API failed';
    }

    /** @param array<string,mixed> $request @return array{method:string,path:string,query:array<string,string>,headers:array<string,string>,body:string} */
    private static function isolatedRequestContext(array $request): array
    {
        $target = (string) ($request['path'] ?? '/');
        $parts = parse_url($target);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $headers = [];
        foreach ((array) ($request['headers'] ?? []) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $headers[strtolower((string) $key)] = (string) $value;
            }
        }

        return [
            'method' => (string) ($request['method'] ?? 'GET'),
            'path' => (string) ($parts['path'] ?? '/'),
            'query' => array_map('strval', $query),
            'headers' => $headers,
            'body' => (string) ($request['body'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $payload @return array{status:int,headers:array<string,string>,body:string} */
    private static function jsonEnvelope(int $status, array $payload): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'];
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private static function htmlEnvelope(string $body): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'text/html; charset=utf-8'], 'body' => $body];
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private static function sseEnvelope(string $body): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'text/event-stream; charset=utf-8', 'Cache-Control' => 'no-cache'], 'body' => $body];
    }

    /** @param array<string,mixed> $payload */
    private static function sse(string $event, array $payload): string
    {
        return 'event: ' . $event . "\n" . 'data: ' . (json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}') . "\n\n";
    }

    private static function eventSourceRuntime(): string
    {
        return "(function(){var r=Number(document.body.getAttribute('data-jinx-state-revision')||'0');var s=new EventSource('/events/island-state?after='+r);s.addEventListener('jinx-island-refresh',function(e){var m=JSON.parse(e.data||'{}');(m.zones||[]).forEach(function(z){document.querySelectorAll('iframe[data-jinx-zone=\"'+z+'\"]').forEach(function(f){var u=new URL(f.getAttribute('src'),location.href);u.searchParams.set('_rev',m.revision);f.setAttribute('src',u.pathname+u.search);});});});})();";
    }

    /** @return array{page:string,detail:string,status:string,revision:int,updated_at:string} */
    private static function defaultState(): array
    {
        return [
            'page' => 'feed.window',
            'detail' => 'Detail from the Oracle back-page API',
            'status' => 'Status from the Oracle back-page API',
            'revision' => 1,
            'updated_at' => self::stamp(),
        ];
    }

    private static function stamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
