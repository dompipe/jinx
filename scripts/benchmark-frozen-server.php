<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/bin/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($path);
}

function startServer(string $cmd, string $cwd, string $log): mixed
{
    @unlink($log);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, $cwd);

    if (!is_resource($process)) {
        fail("could not start server: {$cmd}");
    }

    return $process;
}

function waitForPort(string $host, int $port, string $log): void
{
    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (is_resource($probe)) {
            fclose($probe);
            return;
        }

        usleep(100000);
    }

    $logText = is_file($log) ? (string) file_get_contents($log) : '';
    fail("server did not become ready on {$host}:{$port}\n{$logText}");
}

function curlPost(string $url, string $json): array
{
    $cmd = sprintf(
        'curl -s -w "\\n%%{http_code}" -X POST %s -H %s -d %s',
        escapeshellarg($url),
        escapeshellarg('Content-Type: application/json'),
        escapeshellarg($json)
    );

    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        fail("curl failed with exit {$code}");
    }

    $status = (int) array_pop($out);
    $body = implode("\n", $out);

    return [$status, $body];
}

function benchmark(string $label, string $url, int $iterations): array
{
    for ($i = 0; $i < 10; $i++) {
        [$status, $body] = curlPost($url, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} warmup expected 200, got {$status}: {$body}");
        }

        if (json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
            fail("{$label} warmup bad body: {$body}");
        }
    }

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        [$status, $body] = curlPost($url, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} expected 200, got {$status}: {$body}");
        }
    }

    $elapsedNs = hrtime(true) - $start;
    $totalMs = $elapsedNs / 1_000_000;
    $avgMs = $totalMs / $iterations;
    $rps = $iterations / ($totalMs / 1000);

    return [
        'label' => $label,
        'total_ms' => $totalMs,
        'avg_ms' => $avgMs,
        'rps' => $rps,
    ];
}

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 100;

$host = '127.0.0.1';
$nativePort = 8141;
$cachePort = 8142;
$frozenPort = 8143;

$nativeLog = $root . '/build/benchmark-native-frozen.log';
$cacheLog = $root . '/build/benchmark-cache-router.log';
$frozenLog = $root . '/build/benchmark-frozen-router.log';

$cacheRoot = $root . '/build/web-cache-benchmark-cache';
$frozenRoot = $root . '/build/web-cache-benchmark-frozen';

removeTree($cacheRoot);
removeTree($frozenRoot);

@mkdir($cacheRoot, 0775, true);
@mkdir($frozenRoot, 0775, true);

$nativeCmd = sprintf(
    'php -S %s -t %s',
    escapeshellarg("{$host}:{$nativePort}"),
    escapeshellarg($root . '/fixtures')
);

$cacheCmd = sprintf(
    '%s -S %s --jinx-cache %s %s',
    escapeshellarg($jinx),
    escapeshellarg("{$host}:{$cachePort}"),
    escapeshellarg($root . '/fixtures'),
    escapeshellarg($cacheRoot)
);

$frozenCmd = sprintf(
    '%s serve-frozen %s %s %s',
    escapeshellarg($jinx),
    escapeshellarg("{$host}:{$frozenPort}"),
    escapeshellarg($root . '/fixtures'),
    escapeshellarg($frozenRoot)
);

$native = startServer($nativeCmd, $root, $nativeLog);
$cache = startServer($cacheCmd, $root, $cacheLog);
$frozen = startServer($frozenCmd, $root, $frozenLog);

try {
    waitForPort($host, $nativePort, $nativeLog);
    waitForPort($host, $cachePort, $cacheLog);
    waitForPort($host, $frozenPort, $frozenLog);

    $nativeUrl = "http://{$host}:{$nativePort}/simple-web-api-validated.php";
    $cacheUrl = "http://{$host}:{$cachePort}/simple-web-api-validated.php";
    $frozenUrl = "http://{$host}:{$frozenPort}/simple-web-api-validated.php";

    // Precompile the cache router once before timing.
    [$status, $body] = curlPost($cacheUrl, '{"name":"Anthony"}');

    if ($status !== 200) {
        fail("cache precompile request failed: {$status} {$body}");
    }

    $nativeResult = benchmark('native php -S source endpoint', $nativeUrl, $iterations);
    $cacheResult = benchmark('jinx stale-check cache router', $cacheUrl, $iterations);
    $frozenResult = benchmark('jinx frozen manifest router', $frozenUrl, $iterations);

    echo PHP_EOL;
    echo "JINX Router Benchmark" . PHP_EOL;
    echo "Iterations: {$iterations}" . PHP_EOL;
    echo PHP_EOL;

    printf("%-36s %12s %12s %12s\n", 'target', 'total ms', 'avg ms', 'req/sec');

    foreach ([$nativeResult, $cacheResult, $frozenResult] as $result) {
        printf(
            "%-36s %12.3f %12.3f %12.1f\n",
            $result['label'],
            $result['total_ms'],
            $result['avg_ms'],
            $result['rps']
        );
    }

    echo PHP_EOL;
    printf("cache/native avg latency ratio: %.2fx\n", $cacheResult['avg_ms'] / max($nativeResult['avg_ms'], 0.000001));
    printf("frozen/native avg latency ratio: %.2fx\n", $frozenResult['avg_ms'] / max($nativeResult['avg_ms'], 0.000001));
    printf("frozen/cache avg latency ratio: %.2fx\n", $frozenResult['avg_ms'] / max($cacheResult['avg_ms'], 0.000001));
    echo PHP_EOL;
} finally {
    proc_terminate($native);
    proc_close($native);

    proc_terminate($cache);
    proc_close($cache);

    proc_terminate($frozen);
    proc_close($frozen);
}
