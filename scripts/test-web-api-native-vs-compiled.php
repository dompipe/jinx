<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

$root = dirname(__DIR__);

$source = $root . '/fixtures/simple-web-api-validated.php';
$compiled = $root . '/build/web-compiled/simple-web-api-validated.compiled.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function startServer(string $docroot, int $port): array
{
    $cmd = sprintf(
        'php -S 127.0.0.1:%d -t %s',
        $port,
        escapeshellarg($docroot)
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', '/tmp/jinx-server-' . $port . '.out', 'w'],
        2 => ['file', '/tmp/jinx-server-' . $port . '.err', 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($proc)) {
        fail("could not start server on port {$port}");
    }

    fclose($pipes[0]);

    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'proc' => $proc,
                'port' => $port,
            ];
        }

        usleep(100000);
    }

    proc_terminate($proc);
    proc_close($proc);

    fail("server on port {$port} did not start");
}

function stopServer(array $server): void
{
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
}

function httpPostJson(string $url, string $body): array
{
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $curl = curl_init($url);

    if ($curl === false) {
        fail("curl_init failed for {$url}");
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        fail("curl_exec failed for {$url}: {$error}");
    }

    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    $rawHeaders = substr((string) $response, 0, $headerSize);
    $rawBody = substr((string) $response, $headerSize);

    return [
        'status' => $status,
        'headers' => $rawHeaders,
        'body' => trim($rawBody),
        'json' => json_decode(trim($rawBody), true),
    ];
}

function normalizeJsonBody(array $response): string
{
    if (!is_array($response['json'])) {
        return $response['body'];
    }

    ksort($response['json']);

    return json_encode($response['json'], JSON_UNESCAPED_SLASHES);
}

if (!is_file($source)) {
    fail("missing source endpoint {$source}");
}

WebApiCompiler::compileFileToEndpoint($source, $compiled);

if (!is_file($compiled)) {
    fail("compiled endpoint was not written");
}

$nativeServer = null;
$compiledServer = null;

try {
    $nativeServer = startServer($root . '/fixtures', 8101);
    $compiledServer = startServer($root . '/build/web-compiled', 8102);

    $cases = [
        [
            'label' => 'valid name',
            'body' => '{"name":"Anthony"}',
            'expectedStatus' => 200,
        ],
        [
            'label' => 'missing name',
            'body' => '{}',
            'expectedStatus' => 400,
        ],
        [
            'label' => 'extra field',
            'body' => '{"name":"Anthony","extra":"kept out"}',
            'expectedStatus' => 200,
        ],
        [
            'label' => 'numeric-looking string',
            'body' => '{"name":"12345"}',
            'expectedStatus' => 200,
        ],
        [
            'label' => 'invalid json',
            'body' => '{"name":',
            'expectedStatus' => 400,
            'allowDifferentErrorText' => true,
        ],
    ];

    foreach ($cases as $case) {
        $native = httpPostJson(
            'http://127.0.0.1:8101/simple-web-api-validated.php',
            $case['body']
        );

        $compiledResp = httpPostJson(
            'http://127.0.0.1:8102/simple-web-api-validated.compiled.php',
            $case['body']
        );

        if ($compiledResp['status'] !== $case['expectedStatus']) {
            fail("{$case['label']} compiled status expected {$case['expectedStatus']}, got {$compiledResp['status']}; body={$compiledResp['body']}");
        }

        if ($native['status'] !== $case['expectedStatus']) {
            fail("{$case['label']} native status expected {$case['expectedStatus']}, got {$native['status']}; body={$native['body']}");
        }

        if (($case['allowDifferentErrorText'] ?? false) === true) {
            if (!is_array($compiledResp['json']) || ($compiledResp['json']['ok'] ?? null) !== false) {
                fail("{$case['label']} compiled invalid-json body should be ok=false; got {$compiledResp['body']}");
            }

            if (!is_array($native['json']) || ($native['json']['ok'] ?? null) !== false) {
                fail("{$case['label']} native invalid-json body should be ok=false; got {$native['body']}");
            }

            continue;
        }

        $nativeNorm = normalizeJsonBody($native);
        $compiledNorm = normalizeJsonBody($compiledResp);

        if ($nativeNorm !== $compiledNorm) {
            fail(
                "{$case['label']} body mismatch\n" .
                "native:   {$native['body']}\n" .
                "compiled: {$compiledResp['body']}"
            );
        }

        $compiledContentType = strtolower($compiledResp['headers']);

        if (!str_contains($compiledContentType, 'content-type: application/json')) {
            fail("{$case['label']} compiled response missing JSON content type");
        }
    }

    echo "PASS: native PHP endpoint and compiled Web endpoint match status/body for API cases" . PHP_EOL;
} finally {
    if ($nativeServer !== null) {
        stopServer($nativeServer);
    }

    if ($compiledServer !== null) {
        stopServer($compiledServer);
    }
}
