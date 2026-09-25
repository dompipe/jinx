<?php

declare(strict_types=1);

namespace jinx\web;

require_once __DIR__ . '/WebApiCompiler.php';

final class WebCacheServer
{
    /**
     * @return array<string,mixed>
     */
    public static function compileIfStale(string $sourcePath, string $cacheRoot): array
    {
        $realSource = realpath($sourcePath);

        if ($realSource === false || !is_file($realSource)) {
            throw new \RuntimeException("Missing source endpoint: {$sourcePath}");
        }

        if (!is_dir($cacheRoot) && !mkdir($cacheRoot, 0775, true) && !is_dir($cacheRoot)) {
            throw new \RuntimeException("Could not create cache root: {$cacheRoot}");
        }

        $hash = sha1($realSource);
        $compiledPath = rtrim($cacheRoot, '/\\') . '/' . basename($sourcePath, '.php') . '.' . $hash . '.compiled.php';
        $metaPath = $compiledPath . '.json';

        $sourceMtime = filemtime($realSource) ?: 0;
        $compiledMtime = is_file($compiledPath) ? (filemtime($compiledPath) ?: 0) : 0;

        $compiled = false;

        if (!is_file($compiledPath) || $compiledMtime < $sourceMtime) {
            WebApiCompiler::compileFileToEndpoint($realSource, $compiledPath);

            $meta = [
                'kind' => 'JINX_WEB_CACHE_ENTRY',
                'source' => $realSource,
                'source_mtime' => $sourceMtime,
                'source_sha1' => sha1_file($realSource),
                'compiled' => $compiledPath,
                'compiled_at' => date(DATE_ATOM),
            ];

            file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            $compiled = true;
        }

        return [
            'source' => $realSource,
            'compiled' => $compiledPath,
            'meta' => $metaPath,
            'compiled_now' => $compiled,
        ];
    }

    public static function serve(string $docroot, string $cacheRoot): void
    {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        if (!is_string($uriPath) || $uriPath === '' || $uriPath === '/') {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Missing endpoint']);
            return;
        }

        $sourcePath = rtrim($docroot, '/\\') . '/' . ltrim($uriPath, '/');

        if (!str_ends_with($sourcePath, '.php')) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Only PHP endpoints are supported']);
            return;
        }

        try {
            $entry = self::compileIfStale($sourcePath, $cacheRoot);
            require $entry['compiled'];
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => 'JINX compile/server error',
                'detail' => $e->getMessage(),
            ]);
        }
    }
}
