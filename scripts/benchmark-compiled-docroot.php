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

function percentile(array $values, float $p): float
{
    sort($values);
    $count = count($values);

    if ($count === 0) {
        return 0.0;
    }

    $idx = (int) floor(($count - 1) * $p);

    return $values[$idx];
}

function benchmark(string $label, string $url, int $iterations): array
{
    $warmup = min(100, max(20, (int) floor($iterations / 5)));

    for ($i = 0; $i < $warmup; $i++) {
        [$status, $body] = curlPost($url, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} warmup expected 200, got {$status}: {$body}");
        }

        if (json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
            fail("{$label} warmup bad body: {$body}");
        }
    }

    $samples = [];
    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $oneStart = hrtime(true);
        [$status, $body] = curlPost($url, '{"name":"Anthony"}');
        $oneElapsed = hrtime(true) - $oneStart;

        if ($status !== 200) {
            fail("{$label} expected 200, got {$status}: {$body}");
        }

        $samples[] = $oneElapsed / 1_000_000;
    }

    $elapsedNs = hrtime(true) - $start;
    $totalMs = $elapsedNs / 1_000_000;
    $avgMs = $totalMs / $iterations;
    $rps = $iterations / ($totalMs / 1000);

    return [
        'label' => $label,
        'warmup' => $warmup,
        'total_ms' => $totalMs,
        'avg_ms' => $avgMs,
        'min_ms' => min($samples),
        'p50_ms' => percentile($samples, 0.50),
        'p95_ms' => percentile($samples, 0.95),
        'max_ms' => max($samples),
        'rps' => $rps,
    ];
}

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 100;

$docroot = $root . '/build/web-docroot';

$buildOut = [];
$buildCode = 0;
exec(
    escapeshellarg($jinx) . ' build-docroot ' .
    escapeshellarg($root . '/fixtures') . ' ' .
    escapeshellarg($docroot) . ' 2>&1',
    $buildOut,
    $buildCode
);

if ($buildCode !== 0) {
    fail("build-docroot failed:\n" . implode("\n", $buildOut));
}

$host = '127.0.0.1';
$nativePort = 8151;
$compiledPort = 8152;

$nativeLog = $root . '/build/benchmark-docroot-native.log';
$compiledLog = $root . '/build/benchmark-docroot-compiled.log';

$nativeCmd = sprintf(
    'php -S %s -t %s',
    escapeshellarg("{$host}:{$nativePort}"),
    escapeshellarg($root . '/fixtures')
);

$compiledCmd = sprintf(
    'php -S %s -t %s',
    escapeshellarg("{$host}:{$compiledPort}"),
    escapeshellarg($docroot)
);

$native = startServer($nativeCmd, $root, $nativeLog);
$compiled = startServer($compiledCmd, $root, $compiledLog);

try {
    waitForPort($host, $nativePort, $nativeLog);
    waitForPort($host, $compiledPort, $compiledLog);

    $nativeUrl = "http://{$host}:{$nativePort}/simple-web-api-validated.php";
    $compiledUrl = "http://{$host}:{$compiledPort}/simple-web-api-validated.php";

    $nativeResult = benchmark('native source docroot', $nativeUrl, $iterations);
    $compiledResult = benchmark('compiled docroot', $compiledUrl, $iterations);

    echo PHP_EOL;
    echo "JINX Compiled Docroot Benchmark" . PHP_EOL;
    echo "Iterations: {$iterations}" . PHP_EOL;
    echo PHP_EOL;

    printf(
        "%-28s %8s %10s %10s %10s %10s %10s %10s\n",
        'target',
        'warmup',
        'avg ms',
        'min ms',
        'p50 ms',
        'p95 ms',
        'max ms',
        'req/sec'
    );

    foreach ([$nativeResult, $compiledResult] as $result) {
        printf(
            "%-28s %8d %10.3f %10.3f %10.3f %10.3f %10.3f %10.1f\n",
            $result['label'],
            $result['warmup'],
            $result['avg_ms'],
            $result['min_ms'],
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_ms'],
            $result['rps']
        );
    }

    echo PHP_EOL;
    printf("compiled/native avg latency ratio: %.2fx\n", $compiledResult['avg_ms'] / max($nativeResult['avg_ms'], 0.000001));
    printf("compiled/native p50 latency ratio: %.2fx\n", $compiledResult['p50_ms'] / max($nativeResult['p50_ms'], 0.000001));
    printf("compiled/native p95 latency ratio: %.2fx\n", $compiledResult['p95_ms'] / max($nativeResult['p95_ms'], 0.000001));
    echo PHP_EOL;
} finally {
    proc_terminate($native);
    proc_close($native);

    proc_terminate($compiled);
    proc_close($compiled);
}
