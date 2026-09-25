<?php

declare(strict_types=1);

namespace jinx\web;

require_once __DIR__ . '/WebApiCompiler.php';

final class WebWorkerServer
{
    /**
     * @param array<string,mixed> $plan
     * @return array{0:int,1:array<string,string>,2:string}
     */
    public static function handleJsonRequest(array $plan, string $body): array
    {
        $locals = [];
        $status = 200;
        $headers = ['Content-Type' => 'application/json'];
        $output = '';

        foreach ($plan['ops'] as $op) {
            $result = self::executeOp($op, $locals, $body, $status, $headers, $output);

            if ($result === 'return') {
                break;
            }
        }

        return [$status, $headers, $output];
    }

    /**
     * @param array<string,mixed> $op
     * @param array<string,mixed> $locals
     * @param array<string,string> $headers
     */
    private static function executeOp(
        array $op,
        array &$locals,
        string $body,
        int &$status,
        array &$headers,
        string &$output
    ): ?string {
        switch ($op['op']) {
            case 'WEB_READ_BODY_JSON':
                $dst = self::localKey((string) $op['dst']);
                $decoded = json_decode($body, true);
                $locals[$dst] = is_array($decoded) ? $decoded : null;
                return null;

            case 'WEB_IF_MISSING_ARRAY_KEY':
                $arrayKey = self::localKey((string) $op['array']);
                $key = (string) $op['key'];
                $value = $locals[$arrayKey] ?? null;

                if (!is_array($value) || !isset($value[$key])) {
                    foreach (($op['then'] ?? []) as $thenOp) {
                        $result = self::executeOp($thenOp, $locals, $body, $status, $headers, $output);

                        if ($result === 'return') {
                            return 'return';
                        }
                    }
                }

                return null;

            case 'WEB_ARRAY_GET':
                $dst = self::localKey((string) $op['dst']);
                $arrayKey = self::localKey((string) $op['array']);
                $key = (string) $op['key'];
                $array = $locals[$arrayKey] ?? [];
                $locals[$dst] = is_array($array) ? ($array[$key] ?? null) : null;
                return null;

            case 'WEB_STATUS_CODE':
                $status = (int) $op['code'];
                return null;

            case 'WEB_ECHO_JSON_ARRAY':
                $output .= json_encode(self::buildJsonArray($op['items'], $locals));
                return null;

            case 'WEB_RETURN':
                return 'return';
        }

        throw new \RuntimeException('Worker cannot execute Web op: ' . (string) ($op['op'] ?? 'UNKNOWN'));
    }

    private static function localKey(string $name): string
    {
        return preg_replace('/^LOCAL:/', '', $name) ?? $name;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $locals
     * @return array<string,mixed>
     */
    private static function buildJsonArray(array $items, array $locals): array
    {
        $out = [];

        foreach ($items as $item) {
            $key = (string) $item['key'];
            $kind = (string) $item['kind'];

            if ($kind === 'bool') {
                $out[$key] = (bool) $item['value'];
                continue;
            }

            if ($kind === 'string') {
                $out[$key] = (string) $item['value'];
                continue;
            }

            if ($kind === 'local') {
                $localName = (string) (
                    $item['value']
                    ?? $item['name']
                    ?? $item['local']
                    ?? $item['var']
                    ?? ''
                );

                if ($localName === '') {
                    throw new \RuntimeException('Cannot read local JSON item without local name');
                }

                $out[$key] = $locals[self::localKey($localName)] ?? null;
                continue;
            }

            throw new \RuntimeException('Worker cannot build JSON item kind: ' . $kind);
        }

        return $out;
    }

    public static function serveTcp(string $sourcePath, string $host, int $port): void
    {
        $plan = WebApiCompiler::compileFileToPlan($sourcePath);

        $server = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!is_resource($server)) {
            fwrite(STDERR, "Could not start JINX worker on {$host}:{$port}: {$errno} {$errstr}" . PHP_EOL);
            exit(1);
        }

        fwrite(STDERR, "JINX worker serving {$sourcePath} on http://{$host}:{$port}/" . PHP_EOL);

        while (true) {
            $client = @stream_socket_accept($server, -1);

            if (!is_resource($client)) {
                continue;
            }

            try {
                self::handleClientConnection($client, $plan);
            } catch (\Throwable $e) {
                self::writeResponse(
                    $client,
                    500,
                    ['Content-Type' => 'application/json'],
                    json_encode(['ok' => false, 'error' => 'JINX worker error', 'detail' => $e->getMessage()]) ?: '',
                    false
                );
            }

            fclose($client);
        }
    }

    /**
     * @param resource $client
     * @param array<string,mixed> $plan
     */
    private static function handleClientConnection($client, array $plan): void
    {
        stream_set_timeout($client, 5);

        while (!feof($client)) {
            $request = self::readHttpRequest($client);

            if ($request === null) {
                return;
            }

            [$requestLine, $headers, $body] = $request;

            $close = strtolower($headers['connection'] ?? '') === 'close';

            if (!preg_match('/^POST\s+\/\S*\s+HTTP\/1\.[01]$/', $requestLine)) {
                self::writeResponse(
                    $client,
                    404,
                    ['Content-Type' => 'application/json'],
                    json_encode(['ok' => false, 'error' => 'Only POST / is supported']) ?: '',
                    !$close
                );

                if ($close) {
                    return;
                }

                continue;
            }

            [$status, $responseHeaders, $output] = self::handleJsonRequest($plan, $body);

            self::writeResponse($client, $status, $responseHeaders, $output, !$close);

            if ($close) {
                return;
            }
        }
    }

    /**
     * @param resource $client
     * @return array{0:string,1:array<string,string>,2:string}|null
     */
    private static function readHttpRequest($client): ?array
    {
        $requestLine = fgets($client);

        if ($requestLine === false || $requestLine === '') {
            return null;
        }

        $requestLine = rtrim($requestLine, "\r\n");
        $headers = [];

        while (true) {
            $line = fgets($client);

            if ($line === false || $line === '') {
                return null;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                break;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $contentLength = max(0, (int) ($headers['content-length'] ?? 0));
        $body = '';

        while (strlen($body) < $contentLength) {
            $chunk = fread($client, $contentLength - strlen($body));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return [$requestLine, $headers, $body];
    }


    /**
     * @param resource $client
     * @param array<string,string> $headers
     */
    private static function writeResponse($client, int $status, array $headers, string $body, bool $keepAlive = false): void
    {
        $reason = [
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ][$status] ?? 'OK';

        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] = $keepAlive ? 'keep-alive' : 'close';

        fwrite($client, "HTTP/1.1 {$status} {$reason}\r\n");

        foreach ($headers as $name => $value) {
            fwrite($client, "{$name}: {$value}\r\n");
        }

        fwrite($client, "\r\n");
        fwrite($client, $body);
    }
}
