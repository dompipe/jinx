<?php

declare(strict_types=1);

namespace jinx\web;

require_once __DIR__ . '/WebApiCompiler.php';

/**
 * Back-page bridge for web requests.
 *
 * The front server owns HTTP. The back page owns route execution.
 * A request is handed to this bridge as a JSON envelope, and the bridge returns
 * a JSON response envelope the front server can write back to the socket.
 *
 * Envelope in:
 *   {"method":"POST","path":"/api","body":"{...}","headers":{...}}
 *
 * Envelope out:
 *   {"status":200,"headers":{"Content-Type":"application/json"},"body":"{...}"}
 */
final class WebBackPageBridge
{
    /** @var array<string,mixed> */
    private array $plan;

    /** @var array{required_key:string,success_prefix:string,success_suffix:string,error_body:string}|null */
    private ?array $template;

    /**
     * @param array<string,mixed> $plan
     * @param array{required_key:string,success_prefix:string,success_suffix:string,error_body:string}|null $template
     */
    private function __construct(array $plan, ?array $template)
    {
        $this->plan = $plan;
        $this->template = $template;
    }

    public static function fromRoute(string $routePath, string $mode = 'fast-template'): self
    {
        $plan = WebApiCompiler::compileFileToPlan($routePath);
        $template = $mode === 'fast-template' ? self::compileFastTemplate($plan) : null;

        if ($mode === 'fast-template' && $template === null) {
            throw new \RuntimeException('Route plan is not supported by fast-template back page mode; use plan mode.');
        }

        return new self($plan, $template);
    }

    /**
     * @return array<string,mixed>
     */
    public function handleRequestEnvelope(array $request): array
    {
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $path = (string) ($request['path'] ?? '/');
        $body = (string) ($request['body'] ?? '');

        if ($method === 'GET' && $path === '/__health') {
            return self::responseEnvelope(200, json_encode(['ok' => true, 'worker' => 'jinx-back-page']) ?: '');
        }

        if ($method !== 'POST') {
            return self::responseEnvelope(404, json_encode(['ok' => false, 'error' => 'Not found']) ?: '');
        }

        $response = $this->template !== null
            ? self::executeFastTemplate($this->template, $body)
            : self::executePlan($this->plan, $body);

        return self::responseEnvelope($response['status'], $response['body']);
    }

    public function handleEnvelopeJson(string $json): string
    {
        $request = json_decode($json, true);
        if (!is_array($request)) {
            return json_encode(self::responseEnvelope(400, json_encode(['ok' => false, 'error' => 'Invalid request envelope']) ?: '')) ?: '';
        }

        return json_encode($this->handleRequestEnvelope($request), JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private static function responseEnvelope(int $status, string $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array{required_key:string,success_prefix:string,success_suffix:string,error_body:string}|null
     */
    public static function compileFastTemplate(array $plan): ?array
    {
        $ops = $plan['ops'] ?? [];
        if (!is_array($ops) || count($ops) !== 4) {
            return null;
        }

        if (($ops[0]['op'] ?? null) !== 'WEB_READ_BODY_JSON') {
            return null;
        }
        if (($ops[1]['op'] ?? null) !== 'WEB_IF_MISSING_ARRAY_KEY') {
            return null;
        }
        if (($ops[2]['op'] ?? null) !== 'WEB_ARRAY_GET') {
            return null;
        }
        if (($ops[3]['op'] ?? null) !== 'WEB_ECHO_JSON_ARRAY') {
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

        if (($then[0]['op'] ?? null) !== 'WEB_STATUS_CODE' || (int) ($then[0]['code'] ?? 0) !== 400 || ($then[1]['op'] ?? null) !== 'WEB_ECHO_JSON_ARRAY' || ($then[2]['op'] ?? null) !== 'WEB_RETURN') {
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

    /**
     * @param array{required_key:string,success_prefix:string,success_suffix:string,error_body:string} $template
     * @return array{status:int,body:string}
     */
    public static function executeFastTemplate(array $template, string $body): array
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

    /**
     * @param array<string,mixed> $plan
     * @return array{status:int,body:string}
     */
    public static function executePlan(array $plan, string $body): array
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
                                throw new \RuntimeException('Unsupported web plan JSON item kind: ' . $kind);
                            }
                        }
                        $output .= json_encode($payload) ?: '';
                        break;

                    case 'WEB_RETURN':
                        return false;

                    default:
                        throw new \RuntimeException('Unsupported web plan op: ' . (string) ($op['op'] ?? 'UNKNOWN'));
                }
            }

            return true;
        };

        $runOps((array) ($plan['ops'] ?? []));
        return ['status' => $status, 'body' => $output];
    }
}
