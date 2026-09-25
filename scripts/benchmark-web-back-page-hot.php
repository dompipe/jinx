<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebBackPageBridge.php';

use jinx\web\WebBackPageBridge;

/**
 * Benchmarks the web back-page bridge in the same hot-worker style as the
 * Oracle builtin benchmark. PHP is the direct route baseline. JINX is the
 * precompiled back-page bridge path. No shell spawning or socket timing is
 * included.
 *
 * Run through repository-root native ./jinx:
 *   ./jinx scripts/benchmark-web-back-page-hot.php --requests=100000 --warmup=1000
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
        'requests' => 10000,
        'warmup' => 1000,
        'json' => null,
        'fail_fast' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "Web back-page hot benchmark\n";
            echo "Usage: ./jinx scripts/benchmark-web-back-page-hot.php [--requests=N] [--warmup=N] [--json=path] [--fail-fast]\n";
            exit(0);
        }
        if ($arg === '--fail-fast') {
            $options['fail_fast'] = true;
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
        if (str_starts_with($arg, '--json=')) {
            $options['json'] = substr($arg, strlen('--json='));
            continue;
        }
        fail("Unknown option: {$arg}");
    }

    return $options;
}

/** @return array<string,mixed> */
function make_request_envelope(int $i): array
{
    $body = $i % 10 === 0
        ? (json_encode(['missing' => 'name-' . $i]) ?: '{}')
        : (json_encode(['name' => 'name-' . $i]) ?: '{}');

    return [
        'method' => 'POST',
        'path' => '/api',
        'headers' => [
            'content-type' => 'application/json',
            'x-jinx-request' => 'hot-' . $i,
        ],
        'body' => $body,
    ];
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function php_direct_route(array $request): array
{
    $body = (string) ($request['body'] ?? '');
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['name'])) {
        return [
            'status' => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['ok' => false, 'error' => 'Missing name']) ?: '',
        ];
    }

    return [
        'status' => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode(['ok' => true, 'name' => $decoded['name']]) ?: '',
    ];
}

/** @return array{seconds:float,digest:string,mismatches:int,requests:int} */
function run_php_side(int $start, int $count, bool $failFast): array
{
    $hash = hash_init('sha256');
    $mismatches = 0;
    $begin = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $request = make_request_envelope($start + $i);
        $response = php_direct_route($request);
        $line = $response['status'] . ':' . $response['body'];
        hash_update($hash, $line . "\n");

        if (!in_array($response['status'], [200, 400], true)) {
            $mismatches++;
            if ($failFast) {
                fail('unexpected PHP status at request ' . ($start + $i));
            }
        }
    }

    return [
        'seconds' => (hrtime(true) - $begin) / 1_000_000_000,
        'digest' => hash_final($hash),
        'mismatches' => $mismatches,
        'requests' => $count,
    ];
}

/** @return array{seconds:float,digest:string,mismatches:int,requests:int} */
function run_jinx_side(WebBackPageBridge $bridge, int $start, int $count, bool $failFast): array
{
    $hash = hash_init('sha256');
    $mismatches = 0;
    $begin = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $request = make_request_envelope($start + $i);
        $response = $bridge->handleRequestEnvelope($request);
        $line = ((int) ($response['status'] ?? 0)) . ':' . (string) ($response['body'] ?? '');
        hash_update($hash, $line . "\n");

        if (!in_array((int) ($response['status'] ?? 0), [200, 400], true)) {
            $mismatches++;
            if ($failFast) {
                fail('unexpected JINX status at request ' . ($start + $i));
            }
        }
    }

    return [
        'seconds' => (hrtime(true) - $begin) / 1_000_000_000,
        'digest' => hash_final($hash),
        'mismatches' => $mismatches,
        'requests' => $count,
    ];
}

function ms(float $seconds): string
{
    return number_format($seconds * 1000, 3);
}

function us_per_req(float $seconds, int $requests): string
{
    return number_format(($seconds * 1_000_000) / max(1, $requests), 3);
}

function rps(float $seconds, int $requests): string
{
    return number_format($seconds > 0.0 ? $requests / $seconds : 0.0, 2);
}

$root = dirname(__DIR__);
$options = parse_args($argv);
$requests = (int) $options['requests'];
$warmup = (int) $options['warmup'];
$failFast = (bool) $options['fail_fast'];
$route = $root . '/fixtures/simple-web-api-validated.php';
$bridge = WebBackPageBridge::fromRouteFile($route);

if ($warmup > 0) {
    run_php_side(0, $warmup, $failFast);
    run_jinx_side($bridge, 0, $warmup, $failFast);
}

$php = run_php_side($warmup, $requests, $failFast);
$jinx = run_jinx_side($bridge, $warmup, $requests, $failFast);
$ratio = $jinx['seconds'] > 0.0 ? $php['seconds'] / $jinx['seconds'] : 0.0;
$mismatches = $php['mismatches'] + $jinx['mismatches'] + ($php['digest'] === $jinx['digest'] ? 0 : 1);

printf("Web back-page hot benchmark\n");
printf("Requests measured: %d, warmup: %d\n", $requests, $warmup);
printf("Mode: PHP direct route baseline / JINX precompiled back-page bridge / no socket timing / no process-spawn timing\n\n");
printf("%-12s %14s %14s %14s %14s\n", 'Worker', 'total ms', 'us/request', 'req/sec', 'checksum');
printf("%s\n", str_repeat('-', 86));
printf("%-12s %14s %14s %14s %s\n", 'PHP-direct', ms($php['seconds']), us_per_req($php['seconds'], $requests), rps($php['seconds'], $requests), substr($php['digest'], 0, 16));
printf("%-12s %14s %14s %14s %s\n", 'JINX-back', ms($jinx['seconds']), us_per_req($jinx['seconds'], $requests), rps($jinx['seconds'], $requests), substr($jinx['digest'], 0, 16));
printf("%s\n", str_repeat('-', 86));
printf("PHP-direct/JINX-back ratio: %sx\n", number_format($ratio, 2));
printf("PHP checksum:  %s\n", $php['digest']);
printf("JINX checksum: %s\n", $jinx['digest']);
printf("Mismatches: %d\n", $mismatches);

$payload = [
    'kind' => 'JINX_WEB_BACK_PAGE_HOT_BENCHMARK',
    'requests' => $requests,
    'warmup' => $warmup,
    'mode' => 'PHP direct route baseline / JINX precompiled back-page bridge / no socket timing / no process-spawn timing',
    'php' => $php,
    'jinx' => $jinx,
    'ratio_php_direct_over_jinx_back_page' => $ratio,
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

if ($mismatches > 0) {
    exit(1);
}

echo "PASS: web back-page hot benchmark completed with PHP direct route and matching JINX back-page responses" . PHP_EOL;
