<?php

declare(strict_types=1);

namespace jinx\web;

/**
 * Resident browser-window page arrangement index.
 *
 * This is a server-side database of page defaults and arrangements that can be
 * fed repeatedly into a browser window. The resident index stores only page
 * shape/defaults. User request/session/auth/cookie/body data must stay outside
 * this object and be supplied as isolated per-request context elsewhere.
 */
final class WebWindowIndex
{
    /** @var array<string,array<string,mixed>> */
    private array $pageDefaults = [];

    private int $indexVersion = 1;

    /** @param array<string,array<string,mixed>> $pageDefaults */
    public function __construct(array $pageDefaults = [])
    {
        foreach ($pageDefaults as $pageKey => $defaults) {
            $this->upsertPageDefault((string) $pageKey, $defaults, false);
        }
    }

    public static function withStandardDefaults(): self
    {
        return new self([
            'app.shell' => [
                'title' => 'JINX App Shell',
                'layout' => 'shell',
                'zones' => ['topbar', 'main', 'sidebar', 'status'],
                'components' => [
                    'topbar' => ['kind' => 'bar', 'text' => 'JINX'],
                    'main' => ['kind' => 'slot', 'text' => 'Ready'],
                    'sidebar' => ['kind' => 'slot', 'text' => 'Index'],
                    'status' => ['kind' => 'text', 'text' => 'resident'],
                ],
            ],
            'feed.window' => [
                'title' => 'JINX Feed Window',
                'layout' => 'feed',
                'zones' => ['feed', 'detail', 'status'],
                'components' => [
                    'feed' => ['kind' => 'list', 'items' => []],
                    'detail' => ['kind' => 'slot', 'text' => 'Select an item'],
                    'status' => ['kind' => 'text', 'text' => 'live'],
                ],
            ],
        ]);
    }

    /** @param array<string,mixed> $defaults */
    public function registerPageDefault(string $pageKey, array $defaults): void
    {
        $this->upsertPageDefault($pageKey, $defaults, true);
    }

    /** @param array<string,mixed> $defaults */
    public function updatePageDefault(string $pageKey, array $defaults): void
    {
        if (!isset($this->pageDefaults[trim($pageKey)])) {
            throw new \RuntimeException("Cannot update unknown JINX window page key: {$pageKey}");
        }

        $this->upsertPageDefault($pageKey, $defaults, true);
    }

    /** @param array<string,mixed> $patch */
    public function patchPageDefault(string $pageKey, array $patch): void
    {
        $pageKey = trim($pageKey);
        if (!isset($this->pageDefaults[$pageKey])) {
            throw new \RuntimeException("Cannot patch unknown JINX window page key: {$pageKey}");
        }

        $this->upsertPageDefault($pageKey, self::mergeArrangement($this->pageDefaults[$pageKey], $patch), true);
    }

    /** @param array<string,mixed> $defaults */
    private function upsertPageDefault(string $pageKey, array $defaults, bool $bumpVersion): void
    {
        $pageKey = trim($pageKey);
        if ($pageKey === '') {
            throw new \InvalidArgumentException('Window page key cannot be empty.');
        }

        $defaults['page_key'] = $pageKey;
        $defaults['resident'] = true;
        $defaults['index_mutable'] = true;
        $this->pageDefaults[$pageKey] = self::normalizePageDefaults($defaults);

        if ($bumpVersion) {
            $this->indexVersion++;
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function pageDefaults(): array
    {
        return $this->pageDefaults;
    }

    public function indexVersion(): int
    {
        return $this->indexVersion;
    }

    /** @return array<string,mixed> */
    public function arrangeWindow(string $windowId, string $pageKey, array $overrides = []): array
    {
        if (!isset($this->pageDefaults[$pageKey])) {
            throw new \RuntimeException("Unknown JINX window page key: {$pageKey}");
        }

        $windowId = trim($windowId) !== '' ? trim($windowId) : 'window:default';
        $base = $this->pageDefaults[$pageKey];
        $arrangement = self::mergeArrangement($base, $overrides);

        return [
            'kind' => 'JINX_WINDOW_INDEX_FRAME',
            'version' => 1,
            'index_version' => $this->indexVersion,
            'window_id' => $windowId,
            'page_key' => $pageKey,
            'resident_index_fingerprint' => $this->residentIndexFingerprint(),
            'arrangement' => $arrangement,
            'patches' => self::patchesForArrangement($windowId, $arrangement),
            'safety' => [
                'resident_state' => 'page defaults and arrangements only',
                'request_state' => 'must be isolated outside the resident window index',
                'index_update_rule' => 'only explicit register/update/patch calls mutate the resident index',
                'browser_runtime_rule' => 'JINX emits the browser applier inline; no separate JavaScript file is required',
            ],
        ];
    }

    /** @param array<string,mixed> $windowState @return array<string,mixed> */
    public function feedWindow(array &$windowState, string $windowId, string $pageKey, array $overrides = []): array
    {
        $frame = $this->arrangeWindow($windowId, $pageKey, $overrides);
        $windowState[$windowId] = [
            'page_key' => $pageKey,
            'index_version' => $frame['index_version'],
            'resident_index_fingerprint' => $frame['resident_index_fingerprint'],
            'arrangement' => $frame['arrangement'],
            'last_patch_count' => count($frame['patches']),
        ];
        return $frame;
    }

    public function residentIndexFingerprint(): string
    {
        return hash('sha256', json_encode([
            'version' => $this->indexVersion,
            'page_defaults' => $this->pageDefaults,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '');
    }

    /** @return array<string,mixed> */
    public function browserIndexSnapshot(): array
    {
        return [
            'kind' => 'JINX_BROWSER_WINDOW_INDEX',
            'index_version' => $this->indexVersion,
            'fingerprint' => $this->residentIndexFingerprint(),
            'defaults' => $this->pageDefaults,
            'safety' => [
                'resident_state' => 'page defaults and arrangements only',
                'per_user_scale' => 'browser holds the user-visible index; server only sends index snapshots and frames',
                'live_update_rule' => 'apply frames through the inline JINX-emitted window index runtime',
                'external_js_required' => false,
            ],
        ];
    }

    /**
     * Emits the browser-side window-index runtime from PHP/JINX itself.
     *
     * This avoids requiring a hand-authored page script or an external
     * /jinx-window-index.js include. The browser still executes emitted code
     * because DOM mutation requires browser code, but the application author
     * writes JINX/PHP only.
     */
    public static function inlineBrowserRuntimeScript(): string
    {
        return <<<'JS'
(function(window, document){
  if (window.JINXWindowIndex && window.JINXWindowIndex.__jinx_emitted_runtime === true) return;

  function root() {
    window.__JINX_WINDOW_INDEX__ = window.__JINX_WINDOW_INDEX__ || {
      frames: {},
      defaults: {},
      windows: {},
      history: [],
      index_version: 0,
      fingerprint: ''
    };
    return window.__JINX_WINDOW_INDEX__;
  }

  function remember(eventName, payload) {
    var state = root();
    state.history.push({
      event: eventName,
      index_version: payload && payload.index_version ? payload.index_version : (state.index_version || 0),
      at: Date.now()
    });
    if (state.history.length > 200) state.history.shift();
  }

  function dispatch(name, detail) {
    if (typeof window.CustomEvent === 'function') {
      window.dispatchEvent(new CustomEvent(name, {detail: detail}));
    }
  }

  function query(selector) {
    try {
      return selector ? document.querySelector(selector) : null;
    } catch (e) {
      return null;
    }
  }

  function applyPatch(patch) {
    if (!patch || typeof patch !== 'object') return false;
    var el = query(String(patch.selector || ''));
    if (!el) return false;

    var value = patch.value == null ? '' : String(patch.value);
    switch (String(patch.op || 'replaceText')) {
      case 'replaceText':
        el.textContent = value;
        return true;
      case 'replaceHTML':
        el.innerHTML = value;
        return true;
      case 'appendHTML':
        el.insertAdjacentHTML('beforeend', value);
        return true;
      case 'setAttribute':
        if (patch.name) el.setAttribute(String(patch.name), value);
        return true;
      case 'removeAttribute':
        if (patch.name) el.removeAttribute(String(patch.name));
        return true;
      case 'toggleClass':
        if (patch.name) el.classList.toggle(String(patch.name), !!patch.enabled);
        return true;
      default:
        return false;
    }
  }

  function registerIndex(index) {
    var state = root();
    index = index || {};
    state.index_version = index.index_version || state.index_version || 0;
    state.fingerprint = index.fingerprint || state.fingerprint || '';
    state.defaults = Object.assign(state.defaults || {}, index.defaults || {});
    remember('registerIndex', index);
    dispatch('jinx-window-index-registered', index);
    return state;
  }

  function applyFrame(frame) {
    var state = root();
    frame = frame || {};
    var windowId = frame.window_id || 'window:default';
    var pageKey = frame.page_key || 'page:default';

    state.index_version = frame.index_version || state.index_version || 0;
    state.fingerprint = frame.resident_index_fingerprint || state.fingerprint || '';
    state.frames[windowId] = frame;
    state.defaults[pageKey] = frame.arrangement || state.defaults[pageKey] || {};
    state.windows[windowId] = {
      page_key: pageKey,
      index_version: state.index_version,
      fingerprint: state.fingerprint,
      arrangement: frame.arrangement || {},
      last_patch_count: Array.isArray(frame.patches) ? frame.patches.length : 0
    };

    var applied = 0;
    for (var i = 0; i < (frame.patches || []).length; i++) {
      if (applyPatch(frame.patches[i])) applied++;
    }

    frame.applied_patches = applied;
    remember('applyFrame', frame);
    dispatch('jinx-window-frame', frame);
    return frame;
  }

  function liveUpdate(frame) {
    var applied = applyFrame(frame);
    dispatch('jinx-window-live-update', applied);
    return applied;
  }

  function mount(windowId, pageKey, arrangement) {
    return liveUpdate({
      kind: 'JINX_WINDOW_INDEX_FRAME',
      version: 1,
      index_version: root().index_version || 0,
      window_id: windowId || 'window:default',
      page_key: pageKey || 'page:default',
      arrangement: arrangement || {},
      patches: []
    });
  }

  window.JINXWindowIndex = {
    __jinx_emitted_runtime: true,
    registerIndex: registerIndex,
    applyFrame: applyFrame,
    liveUpdate: liveUpdate,
    mount: mount,
    applyPatch: applyPatch,
    state: root
  };
})(window, document);
JS;
    }

    public function toBrowserRegistrationScript(bool $includeRuntime = true): string
    {
        $json = json_encode($this->browserIndexSnapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Could not encode JINX browser window index snapshot.');
        }

        $script = "(function(window){\n"
            . "  const index = {$json};\n"
            . "  window.JINXWindowIndex.registerIndex(index);\n"
            . "})(window);";

        return ($includeRuntime ? self::inlineBrowserRuntimeScript() . "\n" : '') . $script;
    }

    public function toBrowserBootScript(): string
    {
        return $this->toBrowserRegistrationScript(true);
    }

    /** @param array<string,mixed> $frame */
    public static function toBrowserScript(array $frame, bool $includeRuntime = true): string
    {
        $json = json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Could not encode JINX window frame for browser.');
        }

        $script = "(function(window){\n"
            . "  const frame = {$json};\n"
            . "  window.JINXWindowIndex.liveUpdate(frame);\n"
            . "})(window);";

        return ($includeRuntime ? self::inlineBrowserRuntimeScript() . "\n" : '') . $script;
    }

    /** @param array<string,mixed> $defaults @return array<string,mixed> */
    private static function normalizePageDefaults(array $defaults): array
    {
        $defaults['title'] = (string) ($defaults['title'] ?? $defaults['page_key'] ?? 'JINX Window');
        $defaults['layout'] = (string) ($defaults['layout'] ?? 'default');
        $defaults['zones'] = array_values(array_map('strval', is_array($defaults['zones'] ?? null) ? $defaults['zones'] : []));
        $defaults['components'] = is_array($defaults['components'] ?? null) ? $defaults['components'] : [];
        return $defaults;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function mergeArrangement(array $base, array $overrides): array
    {
        $merged = $base;
        foreach ($overrides as $key => $value) {
            if ($key === 'components' && is_array($value) && is_array($merged['components'] ?? null)) {
                $merged['components'] = array_replace_recursive($merged['components'], $value);
                continue;
            }
            $merged[$key] = $value;
        }
        return self::normalizePageDefaults($merged);
    }

    /** @param array<string,mixed> $arrangement @return list<array<string,mixed>> */
    private static function patchesForArrangement(string $windowId, array $arrangement): array
    {
        $safeWindow = preg_replace('/[^A-Za-z0-9_-]/', '-', $windowId) ?: 'window-default';
        $patches = [[
            'op' => 'replaceText',
            'selector' => '[data-jinx-window="' . $safeWindow . '"][data-jinx-zone="title"]',
            'value' => (string) ($arrangement['title'] ?? ''),
        ]];

        foreach ((array) ($arrangement['components'] ?? []) as $zone => $component) {
            if (!is_array($component)) {
                continue;
            }
            if (!array_key_exists('text', $component)) {
                continue;
            }
            $patches[] = [
                'op' => 'replaceText',
                'selector' => '[data-jinx-window="' . $safeWindow . '"][data-jinx-zone="' . addslashes((string) $zone) . '"]',
                'value' => (string) $component['text'],
            ];
        }

        return $patches;
    }
}
