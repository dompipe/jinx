<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/bin/jinx';

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

    fail("hybrid server did not become ready\n" . (is_file($log) ? file_get_contents($log) : ''));
}

function request(string $host, int $port, string $method, string $path, string $body = ''): array
{
    $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($fp)) {
        fail("connect failed: {$errno} {$errstr}");
    }

    $req =
        "{$method} {$path} HTTP/1.1\r\n" .
        "Host: {$host}:{$port}\r\n" .
        "Content-Type: application/json\r\n" .
        "Content-Length: " . strlen($body) . "\r\n" .
        "Connection: close\r\n" .
        "\r\n" .
        $body;

    fwrite($fp, $req);

    $raw = '';

    while (!feof($fp)) {
        $chunk = fread($fp, 8192);

        if ($chunk === false) {
            break;
        }

        $raw .= $chunk;
    }

    fclose($fp);

    [$headers, $responseBody] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2), 2, '');

    if (!preg_match('/^HTTP\/\S+\s+(\d+)/', $headers, $m)) {
        fail("bad response:\n{$raw}");
    }

    return [(int) $m[1], $headers, $responseBody];
}

$host = '127.0.0.1';
$port = 8191;
$log = $root . '/build/test-jinx-hybrid-server.log';
@unlink($log);

$cmd = sprintf(
    '%s hybrid-server %s %s > %s 2>&1',
    escapeshellarg($jinx),
    escapeshellarg($root . '/fixtures'),
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
    fail('could not start hybrid server');
}

try {
    waitForPort($host, $port, $log);

    [$status, $headers, $body] = request(
        $host,
        $port,
        'POST',
        '/simple-web-api-validated.php',
        '{"name":"Anthony"}'
    );

    if ($status !== 200 || json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
        fail("bad worker response {$status}: {$body}");
    }

    if (!str_contains($headers, 'X-JINX-Mode: worker')) {
        fail("supported endpoint did not use worker mode:\n{$headers}");
    }

    [$status, $headers, $body] = request($host, $port, 'GET', '/native-fallback-test.php');

    if ($status !== 200 || !str_contains($body, 'native fallback ok: GET')) {
        fail("bad fallback response {$status}: {$body}");
    }

    if (!str_contains($headers, 'X-JINX-Mode: php-fallback')) {
        fail("unsupported endpoint did not use fallback mode:\n{$headers}");
    }
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

echo "PASS: hybrid JINX server uses worker when supported and PHP fallback otherwise\n";
