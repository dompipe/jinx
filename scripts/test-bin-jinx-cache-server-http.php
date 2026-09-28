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

function httpPostJson(string $url, string $json, ?int &$status = null, ?string &$headers = null): string
{
    $cmd = sprintf(
        'curl -i -s -X POST %s -H %s -d %s',
        escapeshellarg($url),
        escapeshellarg('Content-Type: application/json'),
        escapeshellarg($json)
    );

    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        fail('curl failed with exit code ' . $code);
    }

    $raw = implode("\n", $out);

    $parts = preg_split("/\r?\n\r?\n/", $raw, 2);

    if (!is_array($parts) || count($parts) < 2) {
        fail("could not split HTTP response:\n{$raw}");
    }

    $headers = $parts[0];
    $body = $parts[1];

    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $headers, $m)) {
        $status = (int) $m[1];
    } else {
        fail("could not read HTTP status:\n{$raw}");
    }

    return $body;
}

$port = 8127;
$host = '127.0.0.1';
$url = "http://{$host}:{$port}/simple-web-api-validated.php";
$cacheRoot = $root . '/build/web-cache-http-test';
$serverLog = $root . '/build/web-cache-http-test-server.log';

removeTree($cacheRoot);
@mkdir($cacheRoot, 0775, true);
@unlink($serverLog);

$cmd = sprintf(
    '%s -S %s --jinx-cache %s %s > %s 2>&1',
    $webTools,
    escapeshellarg("{$host}:{$port}"),
    escapeshellarg($root . '/fixtures'),
    escapeshellarg($cacheRoot),
    escapeshellarg($serverLog)
);

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['file', $serverLog, 'a'],
    2 => ['file', $serverLog, 'a'],
];

$process = proc_open($cmd, $descriptorSpec, $pipes, $root);

if (!is_resource($process)) {
    fail('could not start JINX web helper cache server');
}

try {
    $ready = false;

    for ($i = 0; $i < 30; $i++) {
        $probe = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }

        usleep(100000);
    }

    if (!$ready) {
        $log = is_file($serverLog) ? (string) file_get_contents($serverLog) : '';
        fail("cache server did not become ready\n{$log}");
    }

    $status = null;
    $headers = null;
    $body = httpPostJson($url, '{"name":"Anthony"}', $status, $headers);

    if ($status !== 200) {
        fail("expected HTTP 200, got {$status}\nheaders:\n{$headers}\nbody:\n{$body}");
    }

    $decoded = json_decode($body, true);

    if ($decoded !== ['ok' => true, 'name' => 'Anthony']) {
        fail("unexpected success body:\n{$body}");
    }

    $status = null;
    $headers = null;
    $body = httpPostJson($url, '{}', $status, $headers);

    if ($status !== 400) {
        fail("expected HTTP 400 for missing name, got {$status}\nheaders:\n{$headers}\nbody:\n{$body}");
    }

    $decoded = json_decode($body, true);

    if ($decoded !== ['ok' => false, 'error' => 'Missing name']) {
        fail("unexpected missing-name body:\n{$body}");
    }

    $compiled = glob($cacheRoot . '/*.compiled.php');

    if (!is_array($compiled) || count($compiled) < 1) {
        fail('cache server did not create compiled endpoint');
    }

    $meta = glob($cacheRoot . '/*.compiled.php.json');

    if (!is_array($meta) || count($meta) < 1) {
        fail('cache server did not create compiled metadata');
    }
} finally {
    proc_terminate($process);
    proc_close($process);
}

echo "PASS: JINX web helper -S --jinx-cache serves source endpoint through cached compiled output\n";
