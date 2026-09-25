<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebBackPageBridge.php';

use jinx\web\WebBackPageBridge;

/**
 * Minimal live JINX web worker used by live-request benchmarks.
 *
 * The front side owns HTTP parsing/writing. Each request is converted into a
 * back-page JSON envelope and handed to WebBackPageBridge, which owns route
 * execution and returns a response envelope for the front side to write.
 */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array<string,string|int|bool> */
function parse_server_args(array $argv): array
{
    $options = [
        'host' => '127.0.0.1',
        'port' => 18081,
        'route' => dirname(__DIR__) . '/fixtures/simple-web-api-validated.php',
        'max_requests' => 0,
        'mode' => 'fast-template',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "JINX live web worker\n";
            echo "Usage: ./jinx scripts/serve-jinx-web-worker.php [--host=127.0.0.1] [--port=18081] [--route=fixtures/simple-web-api-validated.php] [--max-requests=N] [--mode=fast-template|plan]\n";
            exit(0);
        }
        if (preg_match('/^--host=(.+)$/', $arg, $m)) {
            $options['host'] = $m[1];
            continue;
        }
        if (preg_match('/^--port=(\d+)$/', $arg, $m)) {
            $options['port'] = (int) $m[1];
            continue;
        }
        if (preg_match('/^--route=(.+)$/', $arg, $m)) {
            $route = $m[1];
            $options['route'] = str_starts_with($route, '/') ? $route : dirname(__DIR__) . '/' . $route;
            continue;
        }
        if (preg_match('/^--max-requests=(\d+)$/', $arg, $m)) {
            $options['max_requests'] = (int) $m[1];
            continue;
        }
        if (preg_match('/^--mode=(fast-template|plan)$/', $arg, $m)) {
            $options['mode'] = $m[1];
            continue;
        }
        fail("Unknown option: {$arg}");
    }

    return $options;
}

/** @return array{method:string,path:string,headers:array<string,string>,body:string,protocol:string}|null */
function read_http_request($conn, string &$buffer): ?array
{
    while (!str_contains($buffer, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            return null;
        }
        $buffer .= $chunk;
        if (strlen($buffer) > 1024 * 1024) {
            return null;
        }
    }

    [$headerBlock, $rest] = explode("\r\n\r\n", $buffer, 2);
    $lines = explode("\r\n", $headerBlock);
    $requestLine = array_shift($lines) ?? '';
    if (!preg_match('/^(\S+)\s+(\S+)\s+(HTTP\/\d(?:\.\d)?)$/', $requestLine, $m)) {
        return null;
    }

    $headers = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }

    $length = isset($headers['content-length']) ? max(0, (int) $headers['content-length']) : 0;
    while (strlen($rest) < $length) {
        $chunk = fread($conn, $length - strlen($rest));
        if ($chunk === '' || $chunk === false) {
            return null;
        }
        $rest .= $chunk;
    }

    $body = substr($rest, 0, $length);
    $buffer = substr($rest, $length);

    return [
        'method' => strtoupper($m[1]),
        'path' => $m[2],
        'protocol' => $m[3],
        'headers' => $headers,
        'body' => $body,
    ];
}

function wants_keep_alive(array $request): bool
{
    $connection = strtolower((string) ($request['headers']['connection'] ?? ''));
    if ($connection === 'close') {
        return false;
    }
    if ($connection === 'keep-alive') {
        return true;
    }
    return (string) ($request['protocol'] ?? '') === 'HTTP/1.1';
}

/** @param array<string,string> $headers */
function write_response($conn, int $status, array $headers, string $body, bool $keepAlive): void
{
    $reason = $status === 200 ? 'OK' : ($status === 400 ? 'Bad Request' : ($status === 404 ? 'Not Found' : 'Error'));
    $response = "HTTP/1.1 {$status} {$reason}\r\n";
    $headers = array_merge(['Content-Type' => 'application/json'], $headers);
    foreach ($headers as $name => $value) {
        $response .= $name . ': ' . $value . "\r\n";
    }
    $response .= "Content-Length: " . strlen($body) . "\r\n";
    $response .= "Connection: " . ($keepAlive ? 'keep-alive' : 'close') . "\r\n";
    $response .= "\r\n" . $body;
    fwrite($conn, $response);
}

$options = parse_server_args($argv);
$route = (string) $options['route'];
$mode = (string) $options['mode'];
$bridge = WebBackPageBridge::fromRoute($route, $mode);
$host = (string) $options['host'];
$port = (int) $options['port'];
$maxRequests = (int) $options['max_requests'];

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!is_resource($server)) {
    fail("Could not listen on {$host}:{$port}: {$errstr}");
}

fwrite(STDERR, "JINX live web worker listening on {$host}:{$port} route={$route} mode={$mode} back-page=on" . PHP_EOL);
$handled = 0;
while ($maxRequests === 0 || $handled < $maxRequests) {
    $conn = @stream_socket_accept($server, 30);
    if (!is_resource($conn)) {
        continue;
    }
    $buffer = '';
    while ($maxRequests === 0 || $handled < $maxRequests) {
        $request = read_http_request($conn, $buffer);
        if ($request === null) {
            break;
        }
        $keepAlive = wants_keep_alive($request);
        $envelope = $bridge->handleRequestEnvelope([
            'method' => $request['method'],
            'path' => $request['path'],
            'headers' => $request['headers'],
            'body' => $request['body'],
        ]);

        write_response(
            $conn,
            (int) ($envelope['status'] ?? 500),
            is_array($envelope['headers'] ?? null) ? $envelope['headers'] : [],
            (string) ($envelope['body'] ?? ''),
            $keepAlive
        );

        $handled++;
        if (!$keepAlive) {
            break;
        }
    }
    fclose($conn);
}
