<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/JinxCompiledCache.php';

use jinx\server\JinxCompiledCache;

$root = dirname(__DIR__);
$webRoot = $root . '/web';

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uriPath = is_string($uriPath) ? $uriPath : '/';

if ($uriPath === '/') {
    $uriPath = '/index.php';
}

$sourcePath = realpath($webRoot . '/' . ltrim($uriPath, '/'));

if ($sourcePath === false || !str_starts_with($sourcePath, realpath($webRoot) ?: $webRoot)) {
    http_response_code(404);
    echo "Not found\n";
    return;
}

if (is_file($sourcePath) && pathinfo($sourcePath, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

if (!is_file($sourcePath)) {
    http_response_code(404);
    echo "Not found\n";
    return;
}

try {
    $runtimeLocals = [];

    foreach ($_GET as $key => $value) {
        if (is_scalar($value)) {
            $runtimeLocals['LOCAL:' . $key] = is_numeric($value) ? (int) $value : (string) $value;
        }
    }

    $result = JinxCompiledCache::runPhpFile($sourcePath, $runtimeLocals);

    if ($result !== null) {
        echo (string) $result;
        echo "\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "JINX server error: " . $e->getMessage() . "\n";
}
