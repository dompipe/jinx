<?php

declare(strict_types=1);

/**
 * Minimal live PHP web worker used by live-request benchmarks.
 *
 * This intentionally uses the same tiny loopback socket server shape as the JINX
 * web worker so the benchmark compares route execution instead of comparing two
 * unrelated HTTP stacks. It supports both connection-close and keep-alive mode.
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
        'port' => 18080,
        'max_requests' => 0,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "PHP live web worker\n";
            echo "Usage: php scripts/serve-php-web-worker.php [--host=127.0.0.1] [--port=18080] [--max-requests=N]\n";
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
        if (preg_match('/^--max-requests=(\d+)$/', $arg, $m)) {
            $options['max_requests'] = (int) $m[1];
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

function write_response($conn, int $status, string $body, bool $keepAlive): void
{
    $reason = $status === 200 ? 'OK' : ($status === 400 ? 'Bad Request' : ($status === 404 ? 'Not Found' : 'Error'));
    $response = "HTTP/1.1 {$status} {$reason}\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Connection: " . ($keepAlive ? 'keep-alive' : 'close') . "\r\n"
        . "\r\n"
        . $body;
    fwrite($conn, $response);
}

/** @return array{status:int,body:string} */
function run_php_route(string $body): array
{
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['name'])) {
        return ['status' => 400, 'body' => json_encode(['ok' => false, 'error' => 'Missing name']) ?: ''];
    }

    return ['status' => 200, 'body' => json_encode(['ok' => true, 'name' => $data['name']]) ?: ''];
}

$options = parse_server_args($argv);
$host = (string) $options['host'];
$port = (int) $options['port'];
$maxRequests = (int) $options['max_requests'];
$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!is_resource($server)) {
    fail("Could not listen on {$host}:{$port}: {$errstr}");
}

fwrite(STDERR, "PHP live web worker listening on {$host}:{$port}" . PHP_EOL);
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

        if ($request['method'] === 'GET' && $request['path'] === '/__health') {
            write_response($conn, 200, json_encode(['ok' => true, 'worker' => 'php']) ?: '', $keepAlive);
        } elseif ($request['method'] === 'POST') {
            $response = run_php_route($request['body']);
            write_response($conn, $response['status'], $response['body'], $keepAlive);
        } else {
            write_response($conn, 404, json_encode(['ok' => false, 'error' => 'Not found']) ?: '', $keepAlive);
        }

        $handled++;
        if (!$keepAlive) {
            break;
        }
    }
    fclose($conn);
}
