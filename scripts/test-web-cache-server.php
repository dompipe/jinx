<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebCacheServer.php';

use jinx\web\WebCacheServer;

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$cacheRoot = $root . '/build/web-cache-test';

if (is_dir($cacheRoot)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($cacheRoot);
}

@mkdir($cacheRoot, 0775, true);

$entry1 = WebCacheServer::compileIfStale($root . '/fixtures/simple-web-api-validated.php', $cacheRoot);

if (!is_file($entry1['compiled'])) {
    fail('first cache compile did not create compiled file');
}

if (!is_file($entry1['meta'])) {
    fail('first cache compile did not create metadata file');
}

if (($entry1['compiled_now'] ?? null) !== true) {
    fail('first cache compile should compile_now=true');
}

$mtime1 = filemtime($entry1['compiled']);
sleep(1);

$entry2 = WebCacheServer::compileIfStale($root . '/fixtures/simple-web-api-validated.php', $cacheRoot);

if (($entry2['compiled_now'] ?? null) !== false) {
    fail('second cache compile should compile_now=false');
}

$mtime2 = filemtime($entry2['compiled']);

if ($mtime1 !== $mtime2) {
    fail('second cache compile should not rewrite compiled file');
}

echo "PASS: WebCacheServer compiles endpoint cache only when stale\n";
