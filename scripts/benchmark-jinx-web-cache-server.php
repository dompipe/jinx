<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$webTools = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/jinx-web-tools.php');

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
    // Warm up.
    for ($i = 0; $i < 10; $i++) {
        [$status, $body] = curlPost($url, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} warmup expected 200, got {$status}: {$body}");
        }
    }

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        [$status, $body] = curlPost($url, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} expected 200, got {$status}: {$body}");
        }

        $decoded = json_decode($body, true);

        if ($decoded !== ['ok' => true, 'name' => 'Anthony']) {
            fail("{$label} unexpected body: {$body}");
        }
    }

    $elapsedNs = hrtime(true) - $start;
    $totalMs = $elapsedNs / 1_000_000;
    $avgMs = $totalMs / $iterations;
    $rps = $iterations / ($totalMs / 1000);

    return [
        'label' => $label,
        'iterations' => $iterations,
        'total_ms' => $totalMs,
        'avg_ms' => $avgMs,
        'rps' => $rps,
    ];
}

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 100;

$host = '127.0.0.1';
$nativePort = 8131;
$jinxPort = 8132;

$nativeLog = $root . '/build/benchmark-native-server.log';
$jinxLog = $root . '/build/benchmark-jinx-cache-server.log';
$cacheRoot = $root . '/build/web-cache-benchmark';

removeTree($cacheRoot);
@mkdir($cacheRoot, 0775, true);

$nativeCmd = sprintf(
    'php -S %s -t %s',
    escapeshellarg("{$host}:{$nativePort}"),
    escapeshellarg($root . '/fixtures')
);

$jinxCmd = sprintf(
    '%s -S %s --jinx-cache %s %s',
    $webTools,
    escapeshellarg("{$host}:{$jinxPort}"),
    escapeshellarg($root . '/fixtures'),
    escapeshellarg($cacheRoot)
);

$native = startServer($nativeCmd, $root, $nativeLog);
$jinxServer = startServer($jinxCmd, $root, $jinxLog);

try {
    waitForPort($host, $nativePort, $nativeLog);
    waitForPort($host, $jinxPort, $jinxLog);

    $nativeUrl = "http://{$host}:{$nativePort}/simple-web-api-validated.php";
    $jinxUrl = "http://{$host}:{$jinxPort}/simple-web-api-validated.php";

    // Force cache creation before timing.
    [$status, $body] = curlPost($jinxUrl, '{"name":"Anthony"}');

    if ($status !== 200) {
        fail("JINX cache precompile request failed: {$status} {$body}");
    }

    $nativeResult = benchmark('native php -S source endpoint', $nativeUrl, $iterations);
    $jinxResult = benchmark('JINX web helper -S --jinx-cache compiled endpoint', $jinxUrl, $iterations);

    $ratio = $jinxResult['avg_ms'] / max($nativeResult['avg_ms'], 0.000001);

    echo PHP_EOL;
    echo "JINX Web Cache Server Benchmark" . PHP_EOL;
    echo "Iterations: {$iterations}" . PHP_EOL;
    echo PHP_EOL;

    printf("%-44s %12s %12s %12s\n", 'target', 'total ms', 'avg ms', 'req/sec');
    printf(
        "%-44s %12.3f %12.3f %12.1f\n",
        $nativeResult['label'],
        $nativeResult['total_ms'],
        $nativeResult['avg_ms'],
        $nativeResult['rps']
    );
    printf(
        "%-44s %12.3f %12.3f %12.1f\n",
        $jinxResult['label'],
        $jinxResult['total_ms'],
        $jinxResult['avg_ms'],
        $jinxResult['rps']
    );

    echo PHP_EOL;
    printf("compiled/native avg latency ratio: %.2fx\n", $ratio);

    $compiled = glob($cacheRoot . '/*.compiled.php');
    echo "compiled cache files: " . (is_array($compiled) ? count($compiled) : 0) . PHP_EOL;
    echo PHP_EOL;
} finally {
    proc_terminate($native);
    proc_close($native);

    proc_terminate($jinxServer);
    proc_close($jinxServer);
}
