<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebCacheServer.php';

use jinx\web\WebCacheServer;

$docroot = $argv[1] ?? null;
$cacheRoot = $argv[2] ?? null;
$manifestPath = $argv[3] ?? null;

if ($docroot === null || $cacheRoot === null || $manifestPath === null) {
    fwrite(STDERR, "Usage: php scripts/build-web-cache-manifest.php <docroot> <cache-root> <manifest.json>\n");
    exit(1);
}

if (!is_dir($docroot)) {
    fwrite(STDERR, "Missing docroot: {$docroot}\n");
    exit(1);
}

@mkdir($cacheRoot, 0775, true);
@mkdir(dirname($manifestPath), 0775, true);

$manifest = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($docroot, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $sourcePath = $file->getPathname();

    try {
        $entry = WebCacheServer::compileIfStale($sourcePath, $cacheRoot);
    } catch (Throwable $e) {
        // Not every PHP file is in the executable Web API subset yet.
        continue;
    }

    $relative = substr($sourcePath, strlen(rtrim($docroot, '/\\')));
    $relative = '/' . ltrim(str_replace('\\', '/', $relative), '/');

    $manifest[$relative] = $entry['compiled'];
}

file_put_contents(
    $manifestPath,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

echo "compiled endpoints: " . count($manifest) . PHP_EOL;
echo "manifest: {$manifestPath}" . PHP_EOL;
