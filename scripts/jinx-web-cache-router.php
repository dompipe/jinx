<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebCacheServer.php';

$docroot = getenv('JINX_WEB_DOCROOT');
$cacheRoot = getenv('JINX_WEB_CACHE');

if (!is_string($docroot) || $docroot === '') {
    $docroot = dirname(__DIR__) . '/fixtures';
}

if (!is_string($cacheRoot) || $cacheRoot === '') {
    $cacheRoot = dirname(__DIR__) . '/build/web-cache';
}

jinx\web\WebCacheServer::serve($docroot, $cacheRoot);
