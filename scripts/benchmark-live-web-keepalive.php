<?php

declare(strict_types=1);

/**
 * Fair keep-alive live web-request benchmark.
 *
 * Starts two live loopback HTTP workers, then keeps one TCP connection open to
 * each worker and sends all requests through those persistent sockets. This
 * removes connection churn from the measurement and exposes the route execution
 * path more clearly than connection-close HTTP.
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
        'jinx_mode' => 'fast-template',
        'json' => null,
        'fail_fast' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "Live web keep-alive benchmark\n";
            echo "Usage: ./jinx scripts/benchmark-live-web-keepalive.php [--requests=N] [--warmup=N] [--host=127.0.0.1] [--php-port=18080] [--jinx-port=18081] [--jinx-mode=fast-template|plan] [--json=path] [--fail-fast]\n";
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
        if (preg_match('/^--jinx-mode=(fast-template|plan)$/', $arg, $m)) {
            $options['jinx_mode'] = $m[1];
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

/** @return array{process:resource,pipes:array<int,resource>,label:string,command:list<string>} */
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

    return ['process' => $process, 'pipes' => $pipes, 'label' => $label, 'command' => array_values($command)];
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

function drain_pipe_text($pipe): string
{
    if (!is_resource($pipe)) {
        return '';
    }
    $text = stream_get_contents($pipe);
    return is_string($text) ? $text : '';
}

function worker_diagnostics(?array $worker): string
{
    if ($worker === null) {
        return 'worker was not started';
    }

    $status = isset($worker['process']) && is_resource($worker['process']) ? proc_get_status($worker['process']) : [];
    $stdout = drain_pipe_text($worker['pipes'][1] ?? null);
    $stderr = drain_pipe_text($worker['pipes'][2] ?? null);
    $label = (string) ($worker['label'] ?? 'worker');
    $command = implode(' ', array_map('strval', (array) ($worker['command'] ?? [])));
    $running = ($status['running'] ?? null) === true ? 'yes' : 'no';
    $exitCode = array_key_exists('exitcode', $status) ? (string) $status['exitcode'] : 'unknown';

    return "{$label}\ncommand: {$command}\nrunning: {$running}\nexitcode: {$exitCode}\nstdout:\n{$stdout}\nstderr:\n{$stderr}";
}

/** @return resource */
function open_socket_or_throw(string $host, int $port, string $label)
{
    $errno = 0;
    $errstr = '';
    $conn = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if (!is_resource($conn)) {
        throw new RuntimeException("Could not connect to {$label} at {$host}:{$port}: {$errstr}");
    }
    stream_set_timeout($conn, 10);
    return $conn;
}

/** @return resource */
function open_socket(string $host, int $port, string $label, ?array $worker = null)
{
    try {
        return open_socket_or_throw($host, $port, $label);
    } catch (Throwable $e) {
        $details = $worker === null ? '' : "\n\nWorker diagnostics:\n" . worker_diagnostics($worker);
        fail($e->getMessage() . $details);
    }
}

/** @return array{status:int,body:string,headers:string,seconds:float} */
function send_keepalive_request($conn, string $host, int $port, string $method, string $path, string $body, bool $close = false): array
{
    $start = hrtime(true);
    $request = "{$method} {$path} HTTP/1.1\r\n"
        . "Host: {$host}:{$port}\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Connection: " . ($close ? 'close' : 'keep-alive') . "\r\n"
        . "\r\n"
        . $body;
    fwrite($conn, $request);

    $raw = '';
    while (!str_contains($raw, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            throw new RuntimeException('Connection closed before response headers');
        }
        $raw .= $chunk;
    }

    [$headers, $rest] = explode("\r\n\r\n", $raw, 2);
    $status = 0;
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/', $headers, $m)) {
        $status = (int) $m[1];
    }

    $length = 0;
    foreach (explode("\r\n", $headers) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        if (strtolower(trim(substr($line, 0, $pos))) === 'content-length') {
            $length = max(0, (int) trim(substr($line, $pos + 1)));
        }
    }

    while (strlen($rest) < $length) {
        $chunk = fread($conn, $length - strlen($rest));
        if ($chunk === '' || $chunk === false) {
            throw new RuntimeException('Connection closed before response body');
        }
        $rest .= $chunk;
    }

    $seconds = (hrtime(true) - $start) / 1_000_000_000;
    return ['status' => $status, 'body' => substr($rest, 0, $length), 'headers' => $headers, 'seconds' => $seconds];
}

function wait_for_health(string $host, int $port, string $label, ?array $worker): void
{
    $deadline = microtime(true) + 10.0;
    $last = '';
    while (microtime(true) < $deadline) {
        $status = $worker !== null && isset($worker['process']) && is_resource($worker['process']) ? proc_get_status($worker['process']) : [];
        if (($status['running'] ?? true) === false) {
            fail("{$label} exited before it became healthy\n\nWorker diagnostics:\n" . worker_diagnostics($worker));
        }

        try {
            $conn = open_socket_or_throw($host, $port, $label);
            $response = send_keepalive_request($conn, $host, $port, 'GET', '/__health', '', true);
            fclose($conn);
            if ($response['status'] === 200 && str_contains($response['body'], 'ok')) {
                return;
            }
            $last = 'bad health response: ' . $response['status'] . ' ' . $response['body'];
        } catch (Throwable $e) {
            $last = $e->getMessage();
            usleep(50_000);
        }
    }
    fail("{$label} did not become healthy: {$last}\n\nWorker diagnostics:\n" . worker_diagnostics($worker));
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
$jinxMode = (string) $options['jinx_mode'];
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
$phpWorker = start_worker([$php, $root . '/scripts/serve-php-web-worker.php', "--host={$host}", "--port={$phpPort}", "--max-requests={$totalNeeded}"], 'PHP keep-alive worker');
$jinxWorker = start_worker([$jinx, 'scripts/serve-jinx-web-worker.php', "--host={$host}", "--port={$jinxPort}", "--max-requests={$totalNeeded}", "--mode={$jinxMode}"], 'JINX keep-alive worker');

wait_for_health($host, $phpPort, 'PHP keep-alive worker', $phpWorker);
wait_for_health($host, $jinxPort, 'JINX keep-alive worker', $jinxWorker);

$phpConn = open_socket($host, $phpPort, 'PHP keep-alive worker', $phpWorker);
$jinxConn = open_socket($host, $jinxPort, 'JINX keep-alive worker', $jinxWorker);

$makeBody = static function (int $i): string {
    if ($i % 10 === 0) {
        return json_encode(['missing' => 'name-' . $i]) ?: '{}';
    }
    return json_encode(['name' => 'name-' . $i]) ?: '{}';
};

for ($i = 0; $i < $warmup; $i++) {
    $body = $makeBody($i);
    send_keepalive_request($phpConn, $host, $phpPort, 'POST', '/api', $body);
    send_keepalive_request($jinxConn, $host, $jinxPort, 'POST', '/api', $body);
}

$phpTimes = [];
$jinxTimes = [];
$phpChecksum = hash_init('sha256');
$jinxChecksum = hash_init('sha256');
$mismatches = 0;

for ($i = 0; $i < $requests; $i++) {
    $body = $makeBody($i + $warmup);
    $close = $i === $requests - 1;
    $phpResponse = send_keepalive_request($phpConn, $host, $phpPort, 'POST', '/api', $body, $close);
    $jinxResponse = send_keepalive_request($jinxConn, $host, $jinxPort, 'POST', '/api', $body, $close);

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

fclose($phpConn);
fclose($jinxConn);

$phpStats = stats($phpTimes);
$jinxStats = stats($jinxTimes);
$phpDigest = hash_final($phpChecksum);
$jinxDigest = hash_final($jinxChecksum);
$ratio = $jinxStats['avg'] > 0.0 ? $phpStats['avg'] / $jinxStats['avg'] : 0.0;

printf("Live web keep-alive benchmark\n");
printf("Host: %s PHP:%d JINX:%d mode=%s\n", $host, $phpPort, $jinxPort, $jinxMode);
printf("Requests: %d measured, %d warmup per worker over one persistent socket per worker\n", $requests, $warmup);
printf("%-8s %12s %12s %12s %12s %12s\n", 'Worker', 'avg us', 'p95 us', 'min us', 'max us', 'req/sec');
printf("%s\n", str_repeat('-', 76));
printf("%-8s %12s %12s %12s %12s %12s\n", 'PHP', us($phpStats['avg']), us($phpStats['p95']), us($phpStats['min']), us($phpStats['max']), number_format($phpStats['rps'], 2));
printf("%-8s %12s %12s %12s %12s %12s\n", 'JINX', us($jinxStats['avg']), us($jinxStats['p95']), us($jinxStats['min']), us($jinxStats['max']), number_format($jinxStats['rps'], 2));
printf("PHP/JINX avg latency ratio: %sx\n", number_format($ratio, 2));
printf("PHP checksum:  %s\n", $phpDigest);
printf("JINX checksum: %s\n", $jinxDigest);
printf("Mismatches: %d\n", $mismatches);

$payload = [
    'kind' => 'JINX_LIVE_WEB_KEEPALIVE_BENCHMARK',
    'host' => $host,
    'php_port' => $phpPort,
    'jinx_port' => $jinxPort,
    'jinx_mode' => $jinxMode,
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

echo "PASS: live web keep-alive benchmark completed with matching PHP/JINX responses" . PHP_EOL;
