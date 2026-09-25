<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/bin/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function startServer(string $cmd, string $cwd, string $log): mixed
{
    @unlink($log);

    $proc = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ],
        $pipes,
        $cwd
    );

    if (!is_resource($proc)) {
        fail("could not start: {$cmd}");
    }

    return $proc;
}

function waitForPort(string $host, int $port, string $log): void
{
    for ($i = 0; $i < 80; $i++) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (is_resource($fp)) {
            fclose($fp);
            return;
        }

        usleep(100000);
    }

    fail("server not ready:\n" . (is_file($log) ? file_get_contents($log) : ''));
}

function postSocket(string $host, int $port, string $path, string $body): array
{
    $fp = @fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($fp)) {
        fail("connect failed: {$errno} {$errstr}");
    }

    $req =
        "POST {$path} HTTP/1.1\r\n" .
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

function percentile(array $values, float $p): float
{
    sort($values);
    return $values[(int) floor((count($values) - 1) * $p)] ?? 0.0;
}

function readKeepAliveResponse($fp): array
{
    $raw = '';

    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = fread($fp, 8192);

        $meta = stream_get_meta_data($fp);
        if (($meta['timed_out'] ?? false) === true) {
            fail("timed out waiting for response headers");
        }

        if ($chunk === false || $chunk === '') {
            fail("connection closed before response headers");
        }

        $raw .= $chunk;
    }

    [$headers, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');

    if (!preg_match('/^HTTP\/\S+\s+(\d+)/', $headers, $m)) {
        fail("bad keep-alive response:\n{$raw}");
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

        $meta = stream_get_meta_data($fp);
        if (($meta['timed_out'] ?? false) === true) {
            fail("timed out waiting for response body");
        }

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    return [(int) $m[1], substr($body, 0, $contentLength)];
}

function writeKeepAlivePost($fp, string $host, int $port, string $path, string $body, bool $close = false): void
{
    $req =
        "POST {$path} HTTP/1.1\r\n" .
        "Host: {$host}:{$port}\r\n" .
        "Content-Type: application/json\r\n" .
        "Content-Length: " . strlen($body) . "\r\n" .
        "Connection: " . ($close ? "close" : "keep-alive") . "\r\n" .
        "\r\n" .
        $body;

    fwrite($fp, $req);
}

function benchKeepAlive(string $label, string $host, int $port, string $path, int $iterations): array
{
    $warmup = min(200, max(50, (int) floor($iterations / 5)));

    $fp = fsockopen($host, $port, $errno, $errstr, 2.0);

    if (!is_resource($fp)) {
        fail("keep-alive connect failed: {$errno} {$errstr}");
    }

    stream_set_timeout($fp, 2);

    if (function_exists('socket_import_stream')) {
        $socket = socket_import_stream($fp);
        if ($socket !== false && defined('TCP_NODELAY')) {
            @socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        }
    }

    for ($i = 0; $i < $warmup; $i++) {
        writeKeepAlivePost($fp, $host, $port, $path, '{"name":"Anthony"}');
        [$status, $body] = readKeepAliveResponse($fp);

        if ($status !== 200 || json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
            fail("{$label} keep-alive warmup failed {$status}: {$body}");
        }
    }

    $samples = [];
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $one = hrtime(true);
        writeKeepAlivePost($fp, $host, $port, $path, '{"name":"Anthony"}', $i === $iterations - 1);
        [$status, $body] = readKeepAliveResponse($fp);

        if ($status !== 200) {
            fail("{$label} keep-alive failed {$status}: {$body}");
        }

        $samples[] = (hrtime(true) - $one) / 1_000_000;
    }

    fclose($fp);

    $totalMs = (hrtime(true) - $start) / 1_000_000;

    return [
        'label' => $label,
        'avg_ms' => $totalMs / $iterations,
        'min_ms' => min($samples),
        'p50_ms' => percentile($samples, 0.50),
        'p95_ms' => percentile($samples, 0.95),
        'max_ms' => max($samples),
        'rps' => $iterations / ($totalMs / 1000),
    ];
}

function bench(string $label, string $host, int $port, string $path, int $iterations): array
{
    $warmup = min(200, max(50, (int) floor($iterations / 5)));

    for ($i = 0; $i < $warmup; $i++) {
        [$status, $body] = postSocket($host, $port, $path, '{"name":"Anthony"}');

        if ($status !== 200 || json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
            fail("{$label} warmup failed {$status}: {$body}");
        }
    }

    $samples = [];
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $one = hrtime(true);
        [$status, $body] = postSocket($host, $port, $path, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} failed {$status}: {$body}");
        }

        $samples[] = (hrtime(true) - $one) / 1_000_000;
    }

    $totalMs = (hrtime(true) - $start) / 1_000_000;

    return [
        'label' => $label,
        'avg_ms' => $totalMs / $iterations,
        'min_ms' => min($samples),
        'p50_ms' => percentile($samples, 0.50),
        'p95_ms' => percentile($samples, 0.95),
        'max_ms' => max($samples),
        'rps' => $iterations / ($totalMs / 1000),
    ];
}

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 2000;
$host = '127.0.0.1';

$nativePort = 8181;
$workerPort = 8182;
$workerKeepAlivePort = 8183;

$nativeLog = $root . '/build/benchmark-worker-native.log';
$workerLog = $root . '/build/benchmark-worker.log';
$workerKeepAliveLog = $root . '/build/benchmark-worker-keepalive.log';

$native = startServer(
    'php -S ' . escapeshellarg("{$host}:{$nativePort}") . ' -t ' . escapeshellarg($root . '/fixtures'),
    $root,
    $nativeLog
);

$worker = startServer(
    escapeshellarg($jinx) . ' worker ' .
    escapeshellarg($root . '/fixtures/simple-web-api-validated.php') . ' ' .
    escapeshellarg("{$host}:{$workerPort}"),
    $root,
    $workerLog
);

$workerKeepAlive = startServer(
    escapeshellarg($jinx) . ' worker ' .
    escapeshellarg($root . '/fixtures/simple-web-api-validated.php') . ' ' .
    escapeshellarg("{$host}:{$workerKeepAlivePort}"),
    $root,
    $workerKeepAliveLog
);

try {
    waitForPort($host, $nativePort, $nativeLog);
    waitForPort($host, $workerPort, $workerLog);
    waitForPort($host, $workerKeepAlivePort, $workerKeepAliveLog);

    $nativeResult = bench('native php -S', $host, $nativePort, '/simple-web-api-validated.php', $iterations);
    $workerResult = bench('jinx worker close', $host, $workerPort, '/', $iterations);
    // Keep-alive is experimental for now. Close-per-request is the stable fast path.
    // $workerKeepAliveResult = benchKeepAlive('jinx worker keep-alive', $host, $workerKeepAlivePort, '/', $iterations);

    echo PHP_EOL;
    echo "JINX Worker Benchmark" . PHP_EOL;
    echo "Iterations: {$iterations}" . PHP_EOL;
    echo PHP_EOL;

    printf("%-20s %10s %10s %10s %10s %10s %10s\n", 'target', 'avg ms', 'min ms', 'p50 ms', 'p95 ms', 'max ms', 'req/sec');

    foreach ([$nativeResult, $workerResult] as $r) {
        printf(
            "%-20s %10.3f %10.3f %10.3f %10.3f %10.3f %10.1f\n",
            $r['label'],
            $r['avg_ms'],
            $r['min_ms'],
            $r['p50_ms'],
            $r['p95_ms'],
            $r['max_ms'],
            $r['rps']
        );
    }

    echo PHP_EOL;
    printf("worker-close/native avg latency ratio: %.2fx\n", $workerResult['avg_ms'] / max($nativeResult['avg_ms'], 0.000001));
    printf("worker-close/native p50 latency ratio: %.2fx\n", $workerResult['p50_ms'] / max($nativeResult['p50_ms'], 0.000001));
    echo "keep-alive: experimental / disabled in default benchmark" . PHP_EOL;
    echo PHP_EOL;
} finally {
    proc_terminate($native);
    proc_close($native);

    proc_terminate($worker);
    proc_close($worker);

    proc_terminate($workerKeepAlive);
    proc_close($workerKeepAlive);
}
