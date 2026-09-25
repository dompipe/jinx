<?php

declare(strict_types=1);

namespace jinx\web;

require_once __DIR__ . '/WebApiCompiler.php';
require_once __DIR__ . '/WebWorkerServer.php';

final class HybridJinxServer
{
    /** @var array<string,array<string,mixed>|false> */
    private array $planCache = [];

    public function __construct(
        private readonly string $docroot,
        private readonly string $phpBinary = 'php',
    ) {
    }

    public function serveTcp(string $host, int $port): void
    {
        $server = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!is_resource($server)) {
            fwrite(STDERR, "Could not start hybrid JINX server on {$host}:{$port}: {$errno} {$errstr}" . PHP_EOL);
            exit(1);
        }

        fwrite(STDERR, "Hybrid JINX server serving {$this->docroot} on http://{$host}:{$port}/" . PHP_EOL);

        while (true) {
            $client = @stream_socket_accept($server, -1);

            if (!is_resource($client)) {
                continue;
            }

            try {
                $this->handleClient($client);
            } catch (\Throwable $e) {
                $this->writeResponse(
                    $client,
                    500,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'ok' => false,
                        'error' => 'Hybrid JINX server error',
                        'detail' => $e->getMessage(),
                    ]) ?: ''
                );
            }

            fclose($client);
        }
    }

    /**
     * @param resource $client
     */
    private function handleClient($client): void
    {
        stream_set_timeout($client, 5);

        $request = $this->readHttpRequest($client);

        if ($request === null) {
            return;
        }

        [$method, $path, $headers, $body] = $request;

        $sourcePath = $this->resolvePath($path);

        if ($sourcePath === null) {
            $this->writeResponse(
                $client,
                404,
                ['Content-Type' => 'application/json'],
                json_encode(['ok' => false, 'error' => 'Not found']) ?: ''
            );
            return;
        }

        if (!str_ends_with($sourcePath, '.php')) {
            $this->writeStaticFile($client, $sourcePath);
            return;
        }

        $plan = $this->getExecutablePlan($sourcePath);

        if (is_array($plan) && strtoupper($method) === 'POST') {
            [$status, $responseHeaders, $output] = WebWorkerServer::handleJsonRequest($plan, $body);
            $responseHeaders['X-JINX-Mode'] = 'worker';
            $this->writeResponse($client, $status, $responseHeaders, $output);
            return;
        }

        [$status, $responseHeaders, $output] = $this->runPhpFallback($sourcePath, $method, $path, $headers, $body);
        $responseHeaders['X-JINX-Mode'] = 'php-fallback';
        $this->writeResponse($client, $status, $responseHeaders, $output);
    }

    /**
     * @return array{0:string,1:string,2:array<string,string>,3:string}|null
     */
    private function readHttpRequest($client): ?array
    {
        $requestLine = fgets($client);

        if ($requestLine === false || $requestLine === '') {
            return null;
        }

        $requestLine = rtrim($requestLine, "\r\n");

        if (!preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\/1\.[01]$/', $requestLine, $m)) {
            return null;
        }

        $method = $m[1];
        $path = $m[2];
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

        return [$method, $path, $headers, substr($body, 0, $contentLength)];
    }

    private function resolvePath(string $requestPath): ?string
    {
        $pathOnly = parse_url($requestPath, PHP_URL_PATH);

        if (!is_string($pathOnly) || $pathOnly === '' || $pathOnly === '/') {
            $pathOnly = '/index.php';
        }

        $candidate = realpath(rtrim($this->docroot, '/\\') . '/' . ltrim($pathOnly, '/'));

        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        $docrootReal = realpath($this->docroot);

        if ($docrootReal === false) {
            return null;
        }

        if (!str_starts_with($candidate, rtrim($docrootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @return array<string,mixed>|false
     */
    private function getExecutablePlan(string $sourcePath): array|false
    {
        $mtime = filemtime($sourcePath) ?: 0;
        $cacheKey = $sourcePath . ':' . $mtime;

        if (array_key_exists($cacheKey, $this->planCache)) {
            return $this->planCache[$cacheKey];
        }

        try {
            $plan = WebApiCompiler::compileFileToPlan($sourcePath);
            $this->planCache[$cacheKey] = $plan;
            return $plan;
        } catch (\Throwable) {
            $this->planCache[$cacheKey] = false;
            return false;
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,string>,2:string}
     */
    private function runPhpFallback(
        string $sourcePath,
        string $method,
        string $requestPath,
        array $headers,
        string $body
    ): array {
        $tmpBody = tempnam(sys_get_temp_dir(), 'jinx_php_body_');

        if ($tmpBody === false) {
            throw new \RuntimeException('Could not create PHP fallback temp body');
        }

        file_put_contents($tmpBody, $body);

        $query = parse_url($requestPath, PHP_URL_QUERY);

        $env = [
            'JINX_FALLBACK_BODY_FILE' => $tmpBody,
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $requestPath,
            'QUERY_STRING' => is_string($query) ? $query : '',
            'SCRIPT_FILENAME' => $sourcePath,
            'CONTENT_TYPE' => $headers['content-type'] ?? '',
            'CONTENT_LENGTH' => (string) strlen($body),
        ];

        $wrapper = <<<'PHP'
<?php

declare(strict_types=1);

$bodyFile = getenv('JINX_FALLBACK_BODY_FILE');

if (is_string($bodyFile) && $bodyFile !== '') {
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', class_exists('JinxPhpInputStream') ? 'JinxPhpInputStream' : get_class(new class {
        private int $pos = 0;
        private string $data = '';

        public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
        {
            if ($path !== 'php://input') {
                return false;
            }

            $file = getenv('JINX_FALLBACK_BODY_FILE');
            $this->data = is_string($file) && is_file($file) ? (string) file_get_contents($file) : '';
            $this->pos = 0;
            return true;
        }

        public function stream_read(int $count): string
        {
            $chunk = substr($this->data, $this->pos, $count);
            $this->pos += strlen($chunk);
            return $chunk;
        }

        public function stream_eof(): bool
        {
            return $this->pos >= strlen($this->data);
        }

        public function stream_stat(): array
        {
            return [];
        }
    }));
}

$_SERVER['REQUEST_METHOD'] = getenv('REQUEST_METHOD') ?: 'GET';
$_SERVER['REQUEST_URI'] = getenv('REQUEST_URI') ?: '/';
$_SERVER['QUERY_STRING'] = getenv('QUERY_STRING') ?: '';
$_SERVER['SCRIPT_FILENAME'] = getenv('SCRIPT_FILENAME') ?: '';

parse_str($_SERVER['QUERY_STRING'], $_GET);

require $_SERVER['SCRIPT_FILENAME'];
PHP;

        $tmpWrapper = tempnam(sys_get_temp_dir(), 'jinx_php_wrapper_');

        if ($tmpWrapper === false) {
            @unlink($tmpBody);
            throw new \RuntimeException('Could not create PHP fallback wrapper');
        }

        file_put_contents($tmpWrapper, $wrapper);

        $cmd = escapeshellarg($this->phpBinary) . ' ' . escapeshellarg($tmpWrapper);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, dirname($sourcePath), $env);

        if (!is_resource($process)) {
            @unlink($tmpBody);
            @unlink($tmpWrapper);
            throw new \RuntimeException('Could not start PHP fallback process');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        @unlink($tmpBody);
        @unlink($tmpWrapper);

        if ($code !== 0) {
            return [
                500,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'ok' => false,
                    'error' => 'PHP fallback failed',
                    'detail' => trim((string) $error),
                ]) ?: '',
            ];
        }

        return [
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
            (string) $output,
        ];
    }

    private function writeStaticFile($client, string $path): void
    {
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json',
            'txt' => 'text/plain; charset=UTF-8',
            default => 'application/octet-stream',
        };

        $this->writeResponse(
            $client,
            200,
            ['Content-Type' => $mime],
            (string) file_get_contents($path)
        );
    }

    /**
     * @param resource $client
     * @param array<string,string> $headers
     */
    private function writeResponse($client, int $status, array $headers, string $body): void
    {
        $reason = [
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ][$status] ?? 'OK';

        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] = 'close';

        fwrite($client, "HTTP/1.1 {$status} {$reason}\r\n");

        foreach ($headers as $name => $value) {
            fwrite($client, "{$name}: {$value}\r\n");
        }

        fwrite($client, "\r\n");
        fwrite($client, $body);
    }
}
