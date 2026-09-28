<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$webTools = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/jinx-web-tools.php');

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function waitForPort(string $host, int $port, string $log): void
{
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (is_resource($fp)) {
            fclose($fp);
            return;
        }

        usleep(100000);
    }

    fail("worker did not become ready\n" . (is_file($log) ? file_get_contents($log) : ''));
}

function readOneResponse($fp): array
{
    $raw = '';

    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = fread($fp, 8192);

        if ($chunk === false || $chunk === '') {
            fail("connection closed before headers");
        }

        $raw .= $chunk;
    }

    [$headers, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');

    if (!preg_match('/^HTTP\/\S+\s+(\d+)/', $headers, $m)) {
        fail("bad response:\n{$raw}");
    }

    $contentLength = 0;

    foreach (explode("\r\n", $headers) as $line) {
        if (stripos($line, 'Content-Length:') === 0) {
            $contentLength = (int) trim(substr($line, strlen('Content-Length:')));
            break;
        }
    }

    while (strlen($body) < $contentLength) {
        $chunk = fread($fp, $contentLength - strlen($body));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    return [(int) $m[1], substr($body, 0, $contentLength), $headers];
}

function writePost($fp, string $host, int $port, string $body, bool $close = false): void
{
    $req =
        "POST / HTTP/1.1\r\n" .
        "Host: {$host}:{$port}\r\n" .
        "Content-Type: application/json\r\n" .
        "Content-Length: " . strlen($body) . "\r\n" .
        "Connection: " . ($close ? "close" : "keep-alive") . "\r\n" .
        "\r\n" .
        $body;

    fwrite($fp, $req);
}

$host = '127.0.0.1';
$port = 8172;
$log = $root . '/build/test-jinx-worker-keepalive.log';
@unlink($log);

$cmd = sprintf(
    '%s worker %s %s > %s 2>&1',
    $webTools,
    escapeshellarg($root . '/fixtures/simple-web-api-validated.php'),
    escapeshellarg("{$host}:{$port}"),
    escapeshellarg($log)
);

$proc = proc_open(
    $cmd,
    [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ],
    $pipes,
    $root
);

if (!is_resource($proc)) {
    fail('could not start worker');
}

try {
    waitForPort($host, $port, $log);

    $fp = fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($fp)) {
        fail("connect failed: {$errno} {$errstr}");
    }

    writePost($fp, $host, $port, '{"name":"Anthony"}');
    [$status, $body, $headers] = readOneResponse($fp);

    if ($status !== 200 || json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
        fail("bad first keep-alive response {$status}: {$body}");
    }

    if (!str_contains(strtolower($headers), 'connection: keep-alive')) {
        fail("first response did not keep connection alive:\n{$headers}");
    }

    writePost($fp, $host, $port, '{}', true);
    [$status, $body] = readOneResponse($fp);

    if ($status !== 400 || json_decode($body, true) !== ['ok' => false, 'error' => 'Missing name']) {
        fail("bad second keep-alive response {$status}: {$body}");
    }

    fclose($fp);
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

echo "PASS: JINX web helper worker supports keep-alive multiple requests per connection\n";
