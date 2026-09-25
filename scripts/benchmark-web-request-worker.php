<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

/**
 * Warmed web-request worker benchmark.
 *
 * Run through repository-root native ./jinx:
 *   ./jinx scripts/benchmark-web-request-worker.php --requests=10000 --warmup=500
 *
 * This benchmark answers the internet/server question: how fast does the
 * already-running JINX web plan handle repeated request-shaped inputs compared
 * with equivalent PHP route logic inside the same warmed process?
 */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array<string,int|string|bool|null> */
function parse_args(array $argv): array
{
    $options = [
        'requests' => 10000,
        'warmup' => 500,
        'fixture' => 'fixtures/simple-web-api-validated.php',
        'json' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "JINX warmed web request worker benchmark\n";
            echo "Usage: ./jinx scripts/benchmark-web-request-worker.php [--requests=N] [--warmup=N] [--fixture=path] [--json=path]\n";
            exit(0);
        }
        if (preg_match('/^--requests=(\d+)$/', $arg, $m)) {
            $options['requests'] = max(1, (int) $m[1]);
            continue;
        }
        if (preg_match('/^--warmup=(\d+)$/', $arg, $m)) {
            $options['warmup'] = max(0, (int) $m[1]);
            continue;
        }
        if (str_starts_with($arg, '--fixture=')) {
            $options['fixture'] = substr($arg, strlen('--fixture='));
            continue;
        }
        if (str_starts_with($arg, '--json=')) {
            $options['json'] = substr($arg, strlen('--json='));
            continue;
        }
        fail("Unknown web benchmark option: {$arg}");
    }

    return $options;
}

/** @return list<array{body:string,expect_status:int,expect_output:string}> */
function build_requests(int $count): array
{
    $requests = [];
    for ($i = 0; $i < $count; $i++) {
        if (($i % 10) === 0) {
            $body = json_encode(['other' => 'missing-' . $i], JSON_UNESCAPED_SLASHES);
            $requests[] = [
                'body' => (string) $body,
                'expect_status' => 400,
                'expect_output' => json_encode(['ok' => false, 'error' => 'Missing name'], JSON_UNESCAPED_SLASHES),
            ];
            continue;
        }

        $name = 'jinx-user-' . $i;
        $body = json_encode(['name' => $name], JSON_UNESCAPED_SLASHES);
        $requests[] = [
            'body' => (string) $body,
            'expect_status' => 200,
            'expect_output' => json_encode(['ok' => true, 'name' => $name], JSON_UNESCAPED_SLASHES),
        ];
    }
    return $requests;
}

/** @return array{status:int,output:string} */
function php_route_worker(string $body): array
{
    $status = 200;
    $data = json_decode($body, true);

    if (!isset($data['name'])) {
        $status = 400;
        return [
            'status' => $status,
            'output' => (string) json_encode(['ok' => false, 'error' => 'Missing name'], JSON_UNESCAPED_SLASHES),
        ];
    }

    $name = $data['name'];
    return [
        'status' => $status,
        'output' => (string) json_encode(['ok' => true, 'name' => $name], JSON_UNESCAPED_SLASHES),
    ];
}

/** @param array<string,mixed> $plan @return array{status:int,output:string} */
function jinx_web_plan_worker(array $plan, string $body): array
{
    $locals = [];
    $status = 200;
    $output = '';
    $returned = false;

    execute_web_ops($plan['ops'] ?? [], $body, $locals, $status, $output, $returned);

    return [
        'status' => $status,
        'output' => $output,
    ];
}

/**
 * @param list<array<string,mixed>> $ops
 * @param array<string,mixed> $locals
 */
function execute_web_ops(array $ops, string $body, array &$locals, int &$status, string &$output, bool &$returned): void
{
    foreach ($ops as $op) {
        if ($returned) {
            return;
        }

        $kind = (string) ($op['op'] ?? '');
        switch ($kind) {
            case 'WEB_READ_BODY_JSON':
                $locals[(string) $op['dst']] = json_decode($body, true);
                break;

            case 'WEB_IF_MISSING_ARRAY_KEY':
                $array = $locals[(string) $op['array']] ?? null;
                $key = (string) $op['key'];
                if (!is_array($array) || !isset($array[$key])) {
                    execute_web_ops($op['then'] ?? [], $body, $locals, $status, $output, $returned);
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
                foreach (($op['items'] ?? []) as $item) {
                    $payload[(string) $item['key']] = match ((string) $item['kind']) {
                        'bool', 'string' => $item['value'],
                        'local' => $locals[(string) $item['local']] ?? null,
                        default => throw new RuntimeException('Unsupported web JSON item kind: ' . (string) $item['kind']),
                    };
                }
                $output .= (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
                break;

            case 'WEB_RETURN':
                $returned = true;
                return;

            default:
                throw new RuntimeException("Unsupported web benchmark op: {$kind}");
        }
    }
}

/** @param list<array{body:string,expect_status:int,expect_output:string}> $requests */
function warm_and_verify(array $plan, array $requests, int $warmup): void
{
    $limit = min(max(1, $warmup), count($requests));
    for ($i = 0; $i < $limit; $i++) {
        $php = php_route_worker($requests[$i]['body']);
        $jinx = jinx_web_plan_worker($plan, $requests[$i]['body']);
        if ($php !== $jinx || $php['status'] !== $requests[$i]['expect_status'] || $php['output'] !== $requests[$i]['expect_output']) {
            fail('web worker warmup parity mismatch at request ' . $i . ': PHP=' . json_encode($php) . ' JINX=' . json_encode($jinx));
        }
    }
}

/** @param list<array{body:string,expect_status:int,expect_output:string}> $requests @return array{seconds:float,checksum:string,p95_seconds:float,average_seconds:float,rps:float} */
function bench_php_worker(array $requests): array
{
    $times = [];
    $ctx = hash_init('xxh3');
    $startAll = hrtime(true);
    foreach ($requests as $i => $request) {
        $start = hrtime(true);
        $result = php_route_worker($request['body']);
        $times[] = (hrtime(true) - $start) / 1_000_000_000;
        if ($result['status'] !== $request['expect_status'] || $result['output'] !== $request['expect_output']) {
            fail('PHP worker result mismatch at request ' . $i);
        }
        hash_update($ctx, $result['status'] . ':' . $result['output'] . "\n");
    }
    $seconds = (hrtime(true) - $startAll) / 1_000_000_000;
    sort($times);
    return [
        'seconds' => $seconds,
        'checksum' => hash_final($ctx),
        'p95_seconds' => percentile($times, 95),
        'average_seconds' => $seconds / max(1, count($requests)),
        'rps' => count($requests) / max(0.000000001, $seconds),
    ];
}

/** @param list<array{body:string,expect_status:int,expect_output:string}> $requests @return array{seconds:float,checksum:string,p95_seconds:float,average_seconds:float,rps:float} */
function bench_jinx_worker(array $plan, array $requests): array
{
    $times = [];
    $ctx = hash_init('xxh3');
    $startAll = hrtime(true);
    foreach ($requests as $i => $request) {
        $start = hrtime(true);
        $result = jinx_web_plan_worker($plan, $request['body']);
        $times[] = (hrtime(true) - $start) / 1_000_000_000;
        if ($result['status'] !== $request['expect_status'] || $result['output'] !== $request['expect_output']) {
            fail('JINX web worker result mismatch at request ' . $i . ': ' . json_encode($result));
        }
        hash_update($ctx, $result['status'] . ':' . $result['output'] . "\n");
    }
    $seconds = (hrtime(true) - $startAll) / 1_000_000_000;
    sort($times);
    return [
        'seconds' => $seconds,
        'checksum' => hash_final($ctx),
        'p95_seconds' => percentile($times, 95),
        'average_seconds' => $seconds / max(1, count($requests)),
        'rps' => count($requests) / max(0.000000001, $seconds),
    ];
}

/** @param list<float> $sorted */
function percentile(array $sorted, int $p): float
{
    if ($sorted === []) {
        return 0.0;
    }
    $idx = (int) floor((count($sorted) - 1) * ($p / 100));
    return $sorted[max(0, min(count($sorted) - 1, $idx))];
}

function ms(float $seconds): string
{
    return number_format($seconds * 1000, 4);
}

$root = dirname(__DIR__);
$options = parse_args($argv);
$requestsCount = (int) $options['requests'];
$warmup = (int) $options['warmup'];
$fixture = (string) $options['fixture'];
$fixturePath = str_starts_with($fixture, '/') ? $fixture : $root . '/' . $fixture;

if (!is_file($fixturePath)) {
    fail("Missing web benchmark fixture: {$fixturePath}");
}

$compileStart = hrtime(true);
$plan = WebApiCompiler::compileFileToPlan($fixturePath);
$compileSeconds = (hrtime(true) - $compileStart) / 1_000_000_000;

$requests = build_requests($requestsCount);
warm_and_verify($plan, $requests, $warmup);

$php = bench_php_worker($requests);
$jinx = bench_jinx_worker($plan, $requests);

if ($php['checksum'] !== $jinx['checksum']) {
    fail('web worker checksum mismatch: PHP=' . $php['checksum'] . ' JINX=' . $jinx['checksum']);
}

$ratio = $jinx['seconds'] > 0.0 ? $php['seconds'] / $jinx['seconds'] : INF;

printf("JINX warmed web request worker benchmark\n");
printf("Fixture: %s\n", $fixture);
printf("Requests: %d measured, %d warmup\n", $requestsCount, $warmup);
printf("Compile once: %s ms\n\n", ms($compileSeconds));
printf("%-12s %12s %12s %12s %12s\n", 'Path', 'total ms', 'avg us', 'p95 us', 'req/sec');
printf("%s\n", str_repeat('-', 68));
printf("%-12s %12s %12s %12s %12s\n", 'PHP route', ms($php['seconds']), number_format($php['average_seconds'] * 1_000_000, 2), number_format($php['p95_seconds'] * 1_000_000, 2), number_format($php['rps'], 2));
printf("%-12s %12s %12s %12s %12s\n", 'JINX plan', ms($jinx['seconds']), number_format($jinx['average_seconds'] * 1_000_000, 2), number_format($jinx['p95_seconds'] * 1_000_000, 2), number_format($jinx['rps'], 2));
printf("\nPHP/JINX worker speed ratio: %s x\n", number_format($ratio, 2));
printf("Checksum: %s\n", $php['checksum']);

$payload = [
    'kind' => 'JINX_WEB_REQUEST_WORKER_BENCHMARK',
    'fixture' => $fixture,
    'requests' => $requestsCount,
    'warmup' => $warmup,
    'compile_seconds' => $compileSeconds,
    'php' => $php,
    'jinx' => $jinx,
    'php_over_jinx_speed_ratio' => $ratio,
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

echo "PASS: warmed web request worker benchmark completed" . PHP_EOL;
