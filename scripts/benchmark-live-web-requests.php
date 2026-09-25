<?php

declare(strict_types=1);

/**
 * Fair live web-request benchmark.
 *
 * Starts two live loopback HTTP workers:
 *   - PHP live route worker through PHP
 *   - JINX live compiled-plan worker through repository-root ./jinx
 *
 * Then sends identical real HTTP POST requests to both and compares status/body
 * checksums, average latency, p95 latency, and requests/second.
 */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array<string,string|int|bool|null> */
function parse_args(array $argv): array
{
    $options = [
        'host' => '127.0.0.1',
        'php_port' => 18080,
        'jinx_port' => 18081,
        'requests' => 1000,
        'warmup' => 100,
        'json' => null,
        'fail_fast' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "Live web request benchmark\n";
            echo "Usage: ./jinx scripts/benchmark-live-web-requests.php [--requests=N] [--warmup=N] [--host=127.0.0.1] [--php-port=18080] [--jinx-port=18081] [--json=path] [--fail-fast]\n";
            exit(0);
        }
        if ($arg === '--fail-fast') {
            $options['fail_fast'] = true;
            continue;
        }
        if (preg_match('/^--host=(.+)$/', $arg, $m)) {
            $options['host'] = $m[1];
            continue;
        }
        if (preg_match('/^--php-port=(\d+)$/', $arg, $m)) {
            $options['php_port'] = (int) $m[1];
            continue;
        }
        if (preg_match('/^--jinx-port=(\d+)$/', $arg, $m)) {
            $options['jinx_port'] = (int) $m[1];
            continue;
        }
        if (preg_match('/^--requests=(\d+)$/', $arg, $m)) {
            $options['requests'] = max(1, (int) $m[1]);
            continue;
        }
        if (preg_match('/^--warmup=(\d+)$/', $arg, $m)) {
            $options['warmup'] = max(0, (int) $m[1]);
            continue;
        }
        if (str_starts_with($arg, '--json=')) {
            $options['json'] = substr($arg, strlen('--json='));
            continue;
        }
        fail("Unknown option: {$arg}");
    }

    return $options;
}

/** @return array{process:resource,pipes:array<int,resource>} */
function start_worker(array $command, string $label): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fail("Could not start {$label}");
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return ['process' => $process, 'pipes' => $pipes];
}

function stop_worker(?array $worker): void
{
    if ($worker === null) {
        return;
    }
    foreach (($worker['pipes'] ?? []) as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (isset($worker['process']) && is_resource($worker['process'])) {
        proc_terminate($worker['process']);
        proc_close($worker['process']);
    }
}

/** @return array{status:int,body:string,headers:string,seconds:float} */
function http_request(string $host, int $port, string $method, string $path, string $body = ''): array
{
    $start = hrtime(true);
    $conn = @fsockopen($host, $port, $errno, $errstr, 5.0);
    if (!is_resource($conn)) {
        throw new RuntimeException("Could not connect to {$host}:{$port}: {$errstr}");
    }
    stream_set_timeout($conn, 5);

    $request = "{$method} {$path} HTTP/1.1\r\n"
        . "Host: {$host}:{$port}\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Connection: close\r\n"
        . "\r\n"
        . $body;
    fwrite($conn, $request);

    $raw = '';
    while (!feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    fclose($conn);
    $seconds = (hrtime(true) - $start) / 1_000_000_000;

    [$headers, $responseBody] = str_contains($raw, "\r\n\r\n") ? explode("\r\n\r\n", $raw, 2) : [$raw, ''];
    $status = 0;
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/', $headers, $m)) {
        $status = (int) $m[1];
    }

    return ['status' => $status, 'body' => $responseBody, 'headers' => $headers, 'seconds' => $seconds];
}

function wait_for_health(string $host, int $port, string $label): void
{
    $deadline = microtime(true) + 10.0;
    $last = '';
    while (microtime(true) < $deadline) {
        try {
            $response = http_request($host, $port, 'GET', '/__health');
            if ($response['status'] === 200 && str_contains($response['body'], 'ok')) {
                return;
            }
            $last = 'bad health response: ' . $response['status'] . ' ' . $response['body'];
        } catch (Throwable $e) {
            $last = $e->getMessage();
            usleep(50_000);
        }
    }
    fail("{$label} did not become healthy: {$last}");
}

/** @return array{avg:float,p95:float,total:float,rps:float,min:float,max:float} */
function stats(array $seconds): array
{
    sort($seconds);
    $count = count($seconds);
    $total = array_sum($seconds);
    $p95Index = max(0, min($count - 1, (int) ceil($count * 0.95) - 1));
    return [
        'avg' => $total / max(1, $count),
        'p95' => $seconds[$p95Index] ?? 0.0,
        'total' => $total,
        'rps' => $total > 0.0 ? $count / $total : 0.0,
        'min' => $seconds[0] ?? 0.0,
        'max' => $seconds[$count - 1] ?? 0.0,
    ];
}

function us(float $seconds): string
{
    return number_format($seconds * 1_000_000, 2);
}

$root = dirname(__DIR__);
$options = parse_args($argv);
$host = (string) $options['host'];
$phpPort = (int) $options['php_port'];
$jinxPort = (int) $options['jinx_port'];
$requests = (int) $options['requests'];
$warmup = (int) $options['warmup'];
$php = getenv('PHP_BIN') ?: PHP_BINARY;
$jinx = $root . '/jinx';
$phpWorker = null;
$jinxWorker = null;

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

register_shutdown_function(static function () use (&$phpWorker, &$jinxWorker): void {
    stop_worker($phpWorker);
    stop_worker($jinxWorker);
});

$totalNeeded = $warmup + $requests + 8;
$phpWorker = start_worker([$php, $root . '/scripts/serve-php-web-worker.php', "--host={$host}", "--port={$phpPort}", "--max-requests={$totalNeeded}"], 'PHP live worker');
$jinxWorker = start_worker([$jinx, 'scripts/serve-jinx-web-worker.php', "--host={$host}", "--port={$jinxPort}", "--max-requests={$totalNeeded}"], 'JINX live worker');

wait_for_health($host, $phpPort, 'PHP live worker');
wait_for_health($host, $jinxPort, 'JINX live worker');

$makeBody = static function (int $i): string {
    if ($i % 10 === 0) {
        return json_encode(['missing' => 'name-' . $i]) ?: '{}';
    }
    return json_encode(['name' => 'name-' . $i]) ?: '{}';
};

for ($i = 0; $i < $warmup; $i++) {
    $body = $makeBody($i);
    http_request($host, $phpPort, 'POST', '/api', $body);
    http_request($host, $jinxPort, 'POST', '/api', $body);
}

$phpTimes = [];
$jinxTimes = [];
$phpChecksum = hash_init('sha256');
$jinxChecksum = hash_init('sha256');
$mismatches = 0;

for ($i = 0; $i < $requests; $i++) {
    $body = $makeBody($i + $warmup);
    $phpResponse = http_request($host, $phpPort, 'POST', '/api', $body);
    $jinxResponse = http_request($host, $jinxPort, 'POST', '/api', $body);

    $phpTimes[] = $phpResponse['seconds'];
    $jinxTimes[] = $jinxResponse['seconds'];

    $phpLine = $phpResponse['status'] . ':' . $phpResponse['body'];
    $jinxLine = $jinxResponse['status'] . ':' . $jinxResponse['body'];
    hash_update($phpChecksum, $phpLine . "\n");
    hash_update($jinxChecksum, $jinxLine . "\n");

    if ($phpLine !== $jinxLine) {
        $mismatches++;
        if ((bool) $options['fail_fast']) {
            fail("response mismatch at request {$i}: PHP={$phpLine} JINX={$jinxLine}");
        }
    }
}

$phpStats = stats($phpTimes);
$jinxStats = stats($jinxTimes);
$phpDigest = hash_final($phpChecksum);
$jinxDigest = hash_final($jinxChecksum);
$ratio = $jinxStats['avg'] > 0.0 ? $phpStats['avg'] / $jinxStats['avg'] : 0.0;

printf("Live web request benchmark\n");
printf("Host: %s PHP:%d JINX:%d\n", $host, $phpPort, $jinxPort);
printf("Requests: %d measured, %d warmup per worker\n", $requests, $warmup);
printf("%-8s %12s %12s %12s %12s %12s\n", 'Worker', 'avg us', 'p95 us', 'min us', 'max us', 'req/sec');
printf("%s\n", str_repeat('-', 76));
printf("%-8s %12s %12s %12s %12s %12s\n", 'PHP', us($phpStats['avg']), us($phpStats['p95']), us($phpStats['min']), us($phpStats['max']), number_format($phpStats['rps'], 2));
printf("%-8s %12s %12s %12s %12s %12s\n", 'JINX', us($jinxStats['avg']), us($jinxStats['p95']), us($jinxStats['min']), us($jinxStats['max']), number_format($jinxStats['rps'], 2));
printf("PHP/JINX avg latency ratio: %sx\n", number_format($ratio, 2));
printf("PHP checksum:  %s\n", $phpDigest);
printf("JINX checksum: %s\n", $jinxDigest);
printf("Mismatches: %d\n", $mismatches);

$payload = [
    'kind' => 'JINX_LIVE_WEB_REQUEST_BENCHMARK',
    'host' => $host,
    'php_port' => $phpPort,
    'jinx_port' => $jinxPort,
    'requests' => $requests,
    'warmup' => $warmup,
    'php' => $phpStats,
    'jinx' => $jinxStats,
    'ratio_php_over_jinx_avg_latency' => $ratio,
    'php_checksum' => $phpDigest,
    'jinx_checksum' => $jinxDigest,
    'mismatches' => $mismatches,
];

$jsonPath = is_string($options['json']) && $options['json'] !== '' ? $options['json'] : null;
if ($jsonPath !== null) {
    $target = str_starts_with($jsonPath, '/') ? $jsonPath : $root . '/' . $jsonPath;
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("Could not create benchmark JSON directory: {$dir}");
    }
    file_put_contents($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo "JSON: {$target}" . PHP_EOL;
}

if ($mismatches > 0 || $phpDigest !== $jinxDigest) {
    exit(1);
}

echo "PASS: live web request benchmark completed with matching PHP/JINX responses" . PHP_EOL;
