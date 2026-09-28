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

    $logText = is_file($log) ? (string) file_get_contents($log) : '';
    fail("worker did not become ready\n{$logText}");
}

function postJson(string $host, int $port, string $body): array
{
    $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($fp)) {
        fail("connect failed: {$errno} {$errstr}");
    }

    $req =
        "POST / HTTP/1.1\r\n" .
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

    return [(int) $m[1], $responseBody];
}

$host = '127.0.0.1';
$port = 8171;
$log = $root . '/build/test-jinx-worker.log';
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

    [$status, $body] = postJson($host, $port, '{"name":"Anthony"}');

    if ($status !== 200 || json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
        fail("bad success response {$status}: {$body}");
    }

    [$status, $body] = postJson($host, $port, '{}');

    if ($status !== 400 || json_decode($body, true) !== ['ok' => false, 'error' => 'Missing name']) {
        fail("bad error response {$status}: {$body}");
    }
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

echo "PASS: JINX web helper worker serves compiled in-memory Web plan\n";
