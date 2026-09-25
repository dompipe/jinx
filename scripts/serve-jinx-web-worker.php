<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

/**
 * Minimal live JINX web worker used by live-request benchmarks.
 *
 * Run through repository-root native ./jinx:
 *   ./jinx scripts/serve-jinx-web-worker.php --port=18081
 *
 * It compiles the route fixture once at startup, then serves requests from a
 * direct web response template when the plan matches the supported route shape.
 * It supports both connection-close and keep-alive mode.
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

/** @return array{required_key:string,success_prefix:string,success_suffix:string,error_body:string}|null */
function compile_fast_template(array $plan): ?array
{
    $ops = $plan['ops'] ?? [];
    if (!is_array($ops) || count($ops) !== 4) {
        return null;
    }

    if (($ops[0]['op'] ?? null) !== 'WEB_READ_BODY_JSON' || ($ops[1]['op'] ?? null) !== 'WEB_IF_MISSING_ARRAY_KEY' || ($ops[2]['op'] ?? null) !== 'WEB_ARRAY_GET' || ($ops[3]['op'] ?? null) !== 'WEB_ECHO_JSON_ARRAY') {
        return null;
    }

    $requiredKey = (string) ($ops[1]['key'] ?? '');
    if ($requiredKey === '' || (string) ($ops[2]['key'] ?? '') !== $requiredKey) {
        return null;
    }

    $then = $ops[1]['then'] ?? [];
    if (!is_array($then) || count($then) < 3) {
        return null;
    }
    $errorStatus = (int) ($then[0]['code'] ?? 0);
    if (($then[0]['op'] ?? null) !== 'WEB_STATUS_CODE' || $errorStatus !== 400 || ($then[1]['op'] ?? null) !== 'WEB_ECHO_JSON_ARRAY' || ($then[2]['op'] ?? null) !== 'WEB_RETURN') {
        return null;
    }

    $errorPayload = [];
    foreach ((array) ($then[1]['items'] ?? []) as $item) {
        $key = (string) ($item['key'] ?? '');
        $kind = (string) ($item['kind'] ?? '');
        if ($kind === 'bool' || $kind === 'string') {
            $errorPayload[$key] = $item['value'] ?? null;
        } else {
            return null;
        }
    }

    $successItems = (array) ($ops[3]['items'] ?? []);
    if (count($successItems) !== 2) {
        return null;
    }

    $static = [];
    $dynamicKey = null;
    foreach ($successItems as $item) {
        $key = (string) ($item['key'] ?? '');
        $kind = (string) ($item['kind'] ?? '');
        if ($kind === 'bool') {
            $static[$key] = (bool) ($item['value'] ?? false);
        } elseif ($kind === 'local') {
            $dynamicKey = $key;
        } else {
            return null;
        }
    }

    if ($dynamicKey === null || !array_key_exists('ok', $static) || $static['ok'] !== true) {
        return null;
    }

    return [
        'required_key' => $requiredKey,
        'success_prefix' => '{"ok":true,"' . addslashes($dynamicKey) . '":',
        'success_suffix' => '}',
        'error_body' => json_encode($errorPayload) ?: '{"ok":false,"error":"Missing name"}',
    ];
}

/** @param array{required_key:string,success_prefix:string,success_suffix:string,error_body:string} $template @return array{status:int,body:string} */
function execute_fast_template(array $template, string $body): array
{
    $decoded = json_decode($body, true);
    $key = $template['required_key'];
    if (!is_array($decoded) || !isset($decoded[$key])) {
        return ['status' => 400, 'body' => $template['error_body']];
    }

    $value = json_encode((string) $decoded[$key]);
    return [
        'status' => 200,
        'body' => $template['success_prefix'] . ($value === false ? '""' : $value) . $template['success_suffix'],
    ];
}

/** @param array<string,mixed> $plan @return array{status:int,body:string} */
function execute_jinx_web_plan(array $plan, string $body): array
{
    $locals = [];
    $status = 200;
    $output = '';

    $runOps = static function (array $ops) use (&$runOps, &$locals, &$status, &$output, $body): bool {
        foreach ($ops as $op) {
            switch ((string) ($op['op'] ?? '')) {
                case 'WEB_READ_BODY_JSON':
                    $decoded = json_decode($body, true);
                    $locals[(string) $op['dst']] = is_array($decoded) ? $decoded : null;
                    break;
                case 'WEB_IF_MISSING_ARRAY_KEY':
                    $array = $locals[(string) $op['array']] ?? null;
                    $key = (string) $op['key'];
                    if (!is_array($array) || !isset($array[$key])) {
                        if ($runOps((array) ($op['then'] ?? [])) === false) {
                            return false;
                        }
                    }
                    break;
                case 'WEB_ARRAY_GET':
                    $array = $locals[(string) $op['array']] ?? [];
                    $locals[(string) $op['dst']] = is_array($array) ? ($array[(string) $op['key']] ?? null) : null;
                    break;
                case 'WEB_STATUS_CODE':
                    $status = (int) $op['code'];
                    break;
                case 'WEB_ECHO_JSON_ARRAY':
                    $payload = [];
                    foreach ((array) ($op['items'] ?? []) as $item) {
                        $key = (string) $item['key'];
                        $kind = (string) $item['kind'];
                        if ($kind === 'bool' || $kind === 'string') {
                            $payload[$key] = $item['value'];
                        } elseif ($kind === 'local') {
                            $localName = (string) ($item['local'] ?? $item['value'] ?? '');
                            $payload[$key] = $locals[$localName] ?? null;
                        } else {
                            throw new RuntimeException('Unsupported web plan JSON item kind: ' . $kind);
                        }
                    }
                    $output .= json_encode($payload) ?: '';
                    break;
                case 'WEB_RETURN':
                    return false;
                default:
                    throw new RuntimeException('Unsupported web plan op: ' . (string) ($op['op'] ?? 'UNKNOWN'));
            }
        }
        return true;
    };

    $runOps((array) ($plan['ops'] ?? []));
    return ['status' => $status, 'body' => $output];
}

$options = parse_server_args($argv);
$route = (string) $options['route'];
$plan = WebApiCompiler::compileFileToPlan($route);
$template = (string) $options['mode'] === 'fast-template' ? compile_fast_template($plan) : null;
if ((string) $options['mode'] === 'fast-template' && $template === null) {
    fail('Route plan is not supported by fast-template mode; rerun with --mode=plan');
}
$host = (string) $options['host'];
$port = (int) $options['port'];
$maxRequests = (int) $options['max_requests'];

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!is_resource($server)) {
    fail("Could not listen on {$host}:{$port}: {$errstr}");
}

fwrite(STDERR, "JINX live web worker listening on {$host}:{$port} route={$route} mode=" . $options['mode'] . PHP_EOL);
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
            write_response($conn, 200, json_encode(['ok' => true, 'worker' => 'jinx']) ?: '', $keepAlive);
        } elseif ($request['method'] === 'POST') {
            $response = $template !== null
                ? execute_fast_template($template, $request['body'])
                : execute_jinx_web_plan($plan, $request['body']);
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
