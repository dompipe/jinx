<?php

declare(strict_types=1);

namespace jinx\web;

/**
 * Browser-native no-JS live islands for JINX window frames.
 *
 * This does not mutate the parent DOM. Instead, the parent page declares stable
 * browser-owned islands and each island loads its own JINX-rendered HTML from a
 * server endpoint. The parent page stays loaded; only the island document is
 * refreshed or navigated by the browser.
 */
final class WebNoJsIslandRegistrar
{
    public static function islandFrame(string $windowId, string $zone, string $src, string $title = ''): string
    {
        $safeWindow = self::safeId($windowId);
        $safeZone = self::safeId($zone);
        $title = $title !== '' ? $title : "JINX {$safeWindow} {$safeZone}";

        return '<iframe'
            . ' data-jinx-no-js-island="1"'
            . ' data-jinx-window="' . self::html($safeWindow) . '"'
            . ' data-jinx-zone="' . self::html($safeZone) . '"'
            . ' title="' . self::html($title) . '"'
            . ' src="' . self::html($src) . '"'
            . ' loading="eager"'
            . ' referrerpolicy="same-origin"'
            . ' sandbox="allow-forms allow-same-origin"'
            . '></iframe>';
    }

    /** @param list<string> $zones */
    public static function islandSet(string $windowId, string $srcBase, array $zones): string
    {
        $html = '<section data-jinx-no-js-island-set="1" data-jinx-window="' . self::html(self::safeId($windowId)) . '">' . "\n";
        foreach ($zones as $zone) {
            $src = rtrim($srcBase, '?&');
            $separator = str_contains($src, '?') ? '&' : '?';
            $src .= $separator . 'window=' . rawurlencode($windowId) . '&zone=' . rawurlencode((string) $zone);
            $html .= self::islandFrame($windowId, (string) $zone, $src) . "\n";
        }
        $html .= '</section>';
        return $html;
    }

    /** @param array<string,mixed> $frame */
    public static function islandDocument(array $frame, string $zone, ?string $refreshUrl = null, int $refreshSeconds = 0): string
    {
        $windowId = self::safeId((string) ($frame['window_id'] ?? 'window-default'));
        $pageKey = (string) ($frame['page_key'] ?? 'page:default');
        $arrangement = is_array($frame['arrangement'] ?? null) ? $frame['arrangement'] : [];
        $components = is_array($arrangement['components'] ?? null) ? $arrangement['components'] : [];
        $component = is_array($components[$zone] ?? null) ? $components[$zone] : [];
        $text = array_key_exists('text', $component) ? (string) $component['text'] : '';
        $kind = (string) ($component['kind'] ?? 'slot');
        $title = (string) ($arrangement['title'] ?? $pageKey) . ' / ' . $zone;

        $refresh = '';
        if ($refreshUrl !== null && $refreshUrl !== '') {
            $content = max(0, $refreshSeconds) . ';url=' . $refreshUrl;
            $refresh = '<meta http-equiv="refresh" content="' . self::html($content) . '">' . "\n";
        }

        return '<!doctype html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . $refresh
            . '<title>' . self::html($title) . '</title>' . "\n"
            . '</head>' . "\n"
            . '<body data-jinx-no-js-island-document="1" data-jinx-window="' . self::html($windowId) . '" data-jinx-zone="' . self::html(self::safeId($zone)) . '" data-jinx-page-key="' . self::html($pageKey) . '">' . "\n"
            . '<main data-jinx-island-component-kind="' . self::html($kind) . '">' . self::html($text) . '</main>' . "\n"
            . '<template data-jinx-island-frame-json="1">' . self::html(json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</template>' . "\n"
            . '</body>' . "\n"
            . '</html>';
    }

    private static function safeId(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $value) ?: 'jinx-island';
    }

    private static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
