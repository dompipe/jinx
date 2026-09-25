<?php

declare(strict_types=1);

$manifestPath = getenv('JINX_WEB_MANIFEST');

if (!is_string($manifestPath) || $manifestPath === '' || !is_file($manifestPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Missing JINX web manifest']);
    return;
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);

if (!is_array($manifest)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid JINX web manifest']);
    return;
}

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!is_string($uriPath) || $uriPath === '' || $uriPath === '/') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Missing endpoint']);
    return;
}

$compiled = $manifest[$uriPath] ?? null;

if (!is_string($compiled) || !is_file($compiled)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Compiled endpoint not found']);
    return;
}

require $compiled;
