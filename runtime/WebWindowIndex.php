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

    /** @param array<string,array<string,mixed>> $pageDefaults */
    public function __construct(array $pageDefaults = [])
    {
        foreach ($pageDefaults as $pageKey => $defaults) {
            $this->registerPageDefault((string) $pageKey, $defaults);
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
        $pageKey = trim($pageKey);
        if ($pageKey === '') {
            throw new \InvalidArgumentException('Window page key cannot be empty.');
        }

        $defaults['page_key'] = $pageKey;
        $defaults['resident'] = true;
        $this->pageDefaults[$pageKey] = self::normalizePageDefaults($defaults);
    }

    /** @return array<string,array<string,mixed>> */
    public function pageDefaults(): array
    {
        return $this->pageDefaults;
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
            'window_id' => $windowId,
            'page_key' => $pageKey,
            'resident_index_fingerprint' => $this->residentIndexFingerprint(),
            'arrangement' => $arrangement,
            'patches' => self::patchesForArrangement($windowId, $arrangement),
            'safety' => [
                'resident_state' => 'page defaults and arrangements only',
                'request_state' => 'must be isolated outside the resident window index',
            ],
        ];
    }

    /** @param array<string,mixed> $windowState @return array<string,mixed> */
    public function feedWindow(array &$windowState, string $windowId, string $pageKey, array $overrides = []): array
    {
        $frame = $this->arrangeWindow($windowId, $pageKey, $overrides);
        $windowState[$windowId] = [
            'page_key' => $pageKey,
            'arrangement' => $frame['arrangement'],
            'last_patch_count' => count($frame['patches']),
        ];
        return $frame;
    }

    public function residentIndexFingerprint(): string
    {
        return hash('sha256', json_encode($this->pageDefaults, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '');
    }

    /** @param array<string,mixed> $frame */
    public static function toBrowserScript(array $frame): string
    {
        $json = json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('Could not encode JINX window frame for browser.');
        }

        return "(function(window, document){\n"
            . "  const frame = {$json};\n"
            . "  window.__JINX_WINDOW_INDEX__ = window.__JINX_WINDOW_INDEX__ || {frames:{}, defaults:{}};\n"
            . "  window.__JINX_WINDOW_INDEX__.frames[frame.window_id] = frame;\n"
            . "  window.__JINX_WINDOW_INDEX__.defaults[frame.page_key] = frame.arrangement;\n"
            . "  for (const patch of frame.patches || []) {\n"
            . "    if (patch.op !== 'replaceText') continue;\n"
            . "    const el = document.querySelector(patch.selector);\n"
            . "    if (el) el.textContent = patch.value == null ? '' : String(patch.value);\n"
            . "  }\n"
            . "  window.dispatchEvent(new CustomEvent('jinx-window-frame', {detail: frame}));\n"
            . "})(window, document);";
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
