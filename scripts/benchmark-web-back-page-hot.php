<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebBackPageBridge.php';

use jinx\web\WebApiCompiler;
use jinx\web\WebBackPageBridge;

/**
 * Benchmarks web-shaped back-page work in the same hot-worker style as the
 * Oracle builtin benchmark. PHP is the direct route baseline. JINX can run
 * either the response-envelope bridge path, the raw template path, or a
 * near-high-level typed frame path. No shell spawning or socket timing is
 * included.
 *
 * Frame construction is deliberately outside the measured loops. The same
 * prebuilt frame deck is replayed by PHP and JINX so frame generation cannot
 * bend the result toward either side.
 *
 * Run through repository-root native ./jinx:
 *   ./jinx scripts/benchmark-web-back-page-hot.php --workload=large --requests=100000 --warmup=1000 --frame-cap=256 --frame-level=high --jinx-mode=raw-template
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
        'jinx_mode' => 'raw-template',
        'workload' => 'tiny',
        'frame_cap' => 0,
        'frame_level' => 'raw',
        'json' => null,
        'fail_fast' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "Web back-page hot benchmark" . PHP_EOL;
            echo "Usage: ./jinx scripts/benchmark-web-back-page-hot.php [--workload=tiny|large] [--requests=N] [--warmup=N] [--frame-cap=N] [--frame-level=raw|high] [--jinx-mode=raw-template|bridge] [--json=path] [--fail-fast]" . PHP_EOL;
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
        if (preg_match('/^--frame-cap=(\d+)$/', $arg, $m)) {
            $options['frame_cap'] = max(0, (int) $m[1]);
            continue;
        }
        if (preg_match('/^--frame-level=(raw|high)$/', $arg, $m)) {
            $options['frame_level'] = $m[1];
            continue;
        }
        if (preg_match('/^--jinx-mode=(raw-template|bridge)$/', $arg, $m)) {
            $options['jinx_mode'] = $m[1];
            continue;
        }
        if (preg_match('/^--workload=(tiny|large)$/', $arg, $m)) {
            $options['workload'] = $m[1];
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
function make_tiny_request_envelope(int $i): array
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

/** @return array<string,mixed> */
function make_tiny_high_frame(int $i): array
{
    return $i % 10 === 0
        ? ['name_present' => false, 'name' => null]
        : ['name_present' => true, 'name' => 'name-' . $i];
}

/** @return array<string,mixed> */
function make_large_payload(int $i): array
{
    $base = 'jinx-title-' . $i . '-oracle-pasm-worker-route-template';
    $tags = [
        'alpha-' . ($i % 7),
        'beta-' . ($i % 11),
        'gamma-' . ($i % 13),
        'delta-' . ($i % 17),
        'epsilon-' . ($i % 19),
    ];
    $numbers = [
        $i % 97,
        ($i * 3) % 101,
        ($i * 5) % 103,
        ($i * 7) % 107,
        ($i * 11) % 109,
        ($i * 13) % 113,
        ($i * 17) % 127,
        ($i * 19) % 131,
    ];

    $payload = [
        'seq' => $i,
        'title' => $base,
        'tags' => $tags,
        'numbers' => $numbers,
    ];

    if ($i % 10 === 0) {
        $payload['missing'] = 'name-' . $i;
    } else {
        $payload['name'] = 'name-' . $i;
    }

    return $payload;
}

/** @return array<string,mixed> */
function make_large_request_envelope(int $i): array
{
    return [
        'method' => 'POST',
        'path' => '/api/large',
        'headers' => [
            'content-type' => 'application/json',
            'x-jinx-request' => 'large-' . $i,
        ],
        'body' => json_encode(make_large_payload($i), JSON_UNESCAPED_SLASHES) ?: '{}',
    ];
}

/** @return array<string,mixed> */
function make_large_high_frame(int $i): array
{
    $payload = make_large_payload($i);
    return [
        'name_present' => array_key_exists('name', $payload),
        'name' => $payload['name'] ?? null,
        'seq' => (int) $payload['seq'],
        'title' => (string) $payload['title'],
        'tags' => array_values(array_map('strval', $payload['tags'])),
        'numbers' => array_values(array_map('intval', $payload['numbers'])),
    ];
}

/** @return array{request:array<string,mixed>,high:array<string,mixed>} */
function make_frame(int $i, string $workload): array
{
    return [
        'request' => $workload === 'large' ? make_large_request_envelope($i) : make_tiny_request_envelope($i),
        'high' => $workload === 'large' ? make_large_high_frame($i) : make_tiny_high_frame($i),
    ];
}

/** @return list<array{request:array<string,mixed>,high:array<string,mixed>}> */
function build_frame_deck(int $start, int $count, int $frameCap, string $workload): array
{
    $deckSize = $frameCap > 0 ? min($count, $frameCap) : $count;
    $frames = [];
    for ($i = 0; $i < $deckSize; $i++) {
        $frames[] = make_frame($start + $i, $workload);
    }
    return $frames;
}

/** @return array{request:array<string,mixed>,high:array<string,mixed>} */
function frame_at(array $frames, int $i): array
{
    return $frames[$i % max(1, count($frames))];
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function php_tiny_route(array $request): array
{
    $body = (string) ($request['body'] ?? '');
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['name'])) {
        return ['status' => 400, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['ok' => false, 'error' => 'Missing name']) ?: ''];
    }

    return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['ok' => true, 'name' => $decoded['name']]) ?: ''];
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function php_large_route(array $request): array
{
    $body = (string) ($request['body'] ?? '');
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['name'])) {
        return ['status' => 400, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['ok' => false, 'error' => 'Missing name']) ?: ''];
    }

    $name = (string) $decoded['name'];
    $seq = (int) ($decoded['seq'] ?? 0);
    $title = (string) ($decoded['title'] ?? '');
    $tags = array_values(array_map('strval', is_array($decoded['tags'] ?? null) ? $decoded['tags'] : []));
    $numbers = array_values(array_map('intval', is_array($decoded['numbers'] ?? null) ? $decoded['numbers'] : []));

    return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => large_response_body($name, $seq, $title, $tags, $numbers)];
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function php_direct_route(array $request, string $workload): array
{
    return $workload === 'large' ? php_large_route($request) : php_tiny_route($request);
}

/** @param list<string> $tags @param list<int> $numbers */
function large_response_body(string $name, int $seq, string $title, array $tags, array $numbers): string
{
    $sum = 0;
    foreach ($numbers as $n) {
        $sum += ($n * 3) + ($seq % 17);
    }

    $tagLine = implode('|', $tags);
    $normalized = strtoupper(str_replace(['-', '_'], ' ', trim($title . ' ' . $tagLine)));
    $vowels = substr_count($normalized, 'A') + substr_count($normalized, 'E') + substr_count($normalized, 'I') + substr_count($normalized, 'O') + substr_count($normalized, 'U');
    $score = strlen($name) + strlen($normalized) + $vowels + ($sum % 997);
    $hash = substr(hash('sha256', $name . '|' . $seq . '|' . $normalized . '|' . $sum), 0, 16);
    $label = substr(strtolower(str_replace(' ', '-', $normalized)), 0, 48);

    return json_encode([
        'ok' => true,
        'name' => $name,
        'seq' => $seq,
        'score' => $score,
        'hash' => $hash,
        'label' => $label,
    ], JSON_UNESCAPED_SLASHES) ?: '';
}

function json_string_field(string $body, string $field): ?string
{
    $needle = '"' . $field . '":"';
    $start = strpos($body, $needle);
    if ($start === false) {
        return null;
    }
    $start += strlen($needle);
    $end = strpos($body, '"', $start);
    return $end === false ? null : substr($body, $start, $end - $start);
}

function json_int_field(string $body, string $field): int
{
    $needle = '"' . $field . '":';
    $start = strpos($body, $needle);
    if ($start === false) {
        return 0;
    }
    $start += strlen($needle);
    $end = $start;
    $len = strlen($body);
    while ($end < $len && ctype_digit($body[$end])) {
        $end++;
    }
    return (int) substr($body, $start, $end - $start);
}

/** @return list<int> */
function json_int_array_field(string $body, string $field): array
{
    $needle = '"' . $field . '":[';
    $start = strpos($body, $needle);
    if ($start === false) {
        return [];
    }
    $start += strlen($needle);
    $end = strpos($body, ']', $start);
    if ($end === false) {
        return [];
    }
    $raw = substr($body, $start, $end - $start);
    return $raw === '' ? [] : array_map('intval', explode(',', $raw));
}

/** @return list<string> */
function json_string_array_field(string $body, string $field): array
{
    $needle = '"' . $field . '":[';
    $start = strpos($body, $needle);
    if ($start === false) {
        return [];
    }
    $start += strlen($needle);
    $end = strpos($body, ']', $start);
    if ($end === false) {
        return [];
    }
    $raw = substr($body, $start, $end - $start);
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $out[] = trim($part, '"');
    }
    return $out;
}

/** @return array{status:int,body:string} */
function jinx_tiny_high_route(array $frame): array
{
    if (!($frame['name_present'] ?? false)) {
        return ['status' => 400, 'body' => '{"ok":false,"error":"Missing name"}'];
    }
    return ['status' => 200, 'body' => '{"ok":true,"name":' . (json_encode((string) $frame['name']) ?: '""') . '}'];
}

/** @return array{status:int,body:string} */
function jinx_large_high_route(array $frame): array
{
    if (!($frame['name_present'] ?? false)) {
        return ['status' => 400, 'body' => '{"ok":false,"error":"Missing name"}'];
    }

    return [
        'status' => 200,
        'body' => large_response_body(
            (string) $frame['name'],
            (int) $frame['seq'],
            (string) $frame['title'],
            array_values(array_map('strval', is_array($frame['tags'] ?? null) ? $frame['tags'] : [])),
            array_values(array_map('intval', is_array($frame['numbers'] ?? null) ? $frame['numbers'] : []))
        ),
    ];
}

/** @return array{status:int,body:string} */
function jinx_large_raw_route(string $body): array
{
    $name = json_string_field($body, 'name');
    if ($name === null) {
        return ['status' => 400, 'body' => '{"ok":false,"error":"Missing name"}'];
    }

    return [
        'status' => 200,
        'body' => large_response_body(
            $name,
            json_int_field($body, 'seq'),
            json_string_field($body, 'title') ?? '',
            json_string_array_field($body, 'tags'),
            json_int_array_field($body, 'numbers')
        ),
    ];
}

/** @return array{seconds:float,digest:string,mismatches:int,requests:int,frame_count:int} */
function run_php_side(array $frames, int $count, bool $failFast, string $workload): array
{
    $hash = hash_init('sha256');
    $mismatches = 0;
    $begin = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $frame = frame_at($frames, $i);
        $response = php_direct_route($frame['request'], $workload);
        $line = $response['status'] . ':' . $response['body'];
        hash_update($hash, $line . "\n");

        if (!in_array($response['status'], [200, 400], true)) {
            $mismatches++;
            if ($failFast) {
                fail('unexpected PHP status at request frame ' . $i);
            }
        }
    }

    return ['seconds' => (hrtime(true) - $begin) / 1_000_000_000, 'digest' => hash_final($hash), 'mismatches' => $mismatches, 'requests' => $count, 'frame_count' => count($frames)];
}

/** @return array{seconds:float,digest:string,mismatches:int,requests:int,frame_count:int} */
function run_jinx_bridge_side(WebBackPageBridge $bridge, array $frames, int $count, bool $failFast): array
{
    $hash = hash_init('sha256');
    $mismatches = 0;
    $begin = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $frame = frame_at($frames, $i);
        $response = $bridge->handleRequestEnvelope($frame['request']);
        $line = ((int) ($response['status'] ?? 0)) . ':' . (string) ($response['body'] ?? '');
        hash_update($hash, $line . "\n");

        if (!in_array((int) ($response['status'] ?? 0), [200, 400], true)) {
            $mismatches++;
            if ($failFast) {
                fail('unexpected JINX status at request frame ' . $i);
            }
        }
    }

    return ['seconds' => (hrtime(true) - $begin) / 1_000_000_000, 'digest' => hash_final($hash), 'mismatches' => $mismatches, 'requests' => $count, 'frame_count' => count($frames)];
}

/**
 * @param array{required_key:string,success_prefix:string,success_suffix:string,error_body:string}|null $template
 * @return array{seconds:float,digest:string,mismatches:int,requests:int,frame_count:int}
 */
function run_jinx_raw_template_side(?array $template, array $frames, int $count, bool $failFast, string $workload, string $frameLevel): array
{
    $hash = hash_init('sha256');
    $mismatches = 0;
    $begin = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $frame = frame_at($frames, $i);
        if ($frameLevel === 'high') {
            $response = $workload === 'large'
                ? jinx_large_high_route($frame['high'])
                : jinx_tiny_high_route($frame['high']);
        } else {
            $body = (string) ($frame['request']['body'] ?? '');
            $response = $workload === 'large'
                ? jinx_large_raw_route($body)
                : WebBackPageBridge::executeFastTemplate($template ?? [], $body);
        }
        $line = $response['status'] . ':' . $response['body'];
        hash_update($hash, $line . "\n");

        if (!in_array($response['status'], [200, 400], true)) {
            $mismatches++;
            if ($failFast) {
                fail('unexpected JINX raw-template status at request frame ' . $i);
            }
        }
    }

    return ['seconds' => (hrtime(true) - $begin) / 1_000_000_000, 'digest' => hash_final($hash), 'mismatches' => $mismatches, 'requests' => $count, 'frame_count' => count($frames)];
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
$jinxMode = (string) $options['jinx_mode'];
$workload = (string) $options['workload'];
$frameCap = (int) $options['frame_cap'];
$frameLevel = (string) $options['frame_level'];
$failFast = (bool) $options['fail_fast'];
$route = $root . '/fixtures/simple-web-api-validated.php';
$bridge = null;
$template = null;

if ($jinxMode === 'bridge') {
    if ($workload === 'large') {
        fail('Large workload currently supports --jinx-mode=raw-template only.');
    }
    if ($frameLevel === 'high') {
        fail('High-level frames currently support --jinx-mode=raw-template only.');
    }
    $bridge = WebBackPageBridge::fromRouteFile($route);
} elseif ($workload === 'tiny' && $frameLevel === 'raw') {
    $plan = WebApiCompiler::compileFileToPlan($route);
    $template = WebBackPageBridge::compileFastTemplate($plan);
    if ($template === null) {
        fail('Route plan is not supported by raw-template mode.');
    }
}

$warmupFrames = $warmup > 0 ? build_frame_deck(0, $warmup, $frameCap, $workload) : [];
$measureFrames = build_frame_deck($warmup, $requests, $frameCap, $workload);

$runJinx = static function (array $frames, int $count) use ($jinxMode, $failFast, $workload, $frameLevel, &$bridge, &$template): array {
    if ($jinxMode === 'bridge') {
        return run_jinx_bridge_side($bridge, $frames, $count, $failFast);
    }
    return run_jinx_raw_template_side($template, $frames, $count, $failFast, $workload, $frameLevel);
};

if ($warmup > 0) {
    run_php_side($warmupFrames, $warmup, $failFast, $workload);
    $runJinx($warmupFrames, $warmup);
}

$php = run_php_side($measureFrames, $requests, $failFast, $workload);
$jinx = $runJinx($measureFrames, $requests);
$ratio = $jinx['seconds'] > 0.0 ? $php['seconds'] / $jinx['seconds'] : 0.0;
$mismatches = $php['mismatches'] + $jinx['mismatches'] + ($php['digest'] === $jinx['digest'] ? 0 : 1);
$jinxLabel = $jinxMode === 'bridge' ? 'JINX-bridge' : ($frameLevel === 'high' ? 'JINX-high' : 'JINX-raw');
$modeText = $jinxMode === 'bridge'
    ? 'PHP direct route baseline / JINX response-envelope back-page bridge / prebuilt shared frames / no socket timing / no process-spawn timing'
    : ($frameLevel === 'high'
        ? 'PHP direct route baseline / JINX near-high-level typed frame route / prebuilt shared frames / no JSON parse in JINX hot loop / no socket timing / no process-spawn timing'
        : 'PHP direct route baseline / JINX raw body-to-status-body template / prebuilt shared frames / no response envelope timing / no socket timing / no process-spawn timing');

printf("Web back-page hot benchmark\n");
printf("Requests measured: %d, warmup: %d\n", $requests, $warmup);
printf("Workload: %s\n", $workload);
printf("Frame cap: %s\n", $frameCap > 0 ? (string) $frameCap : 'off');
printf("Frame level: %s\n", $frameLevel);
printf("Measured frame deck: %d\n", count($measureFrames));
printf("JINX mode: %s\n", $jinxMode);
printf("Mode: %s\n\n", $modeText);
printf("%-12s %14s %14s %14s %14s\n", 'Worker', 'total ms', 'us/request', 'req/sec', 'checksum');
printf("%s\n", str_repeat('-', 86));
printf("%-12s %14s %14s %14s %s\n", 'PHP-direct', ms($php['seconds']), us_per_req($php['seconds'], $requests), rps($php['seconds'], $requests), substr($php['digest'], 0, 16));
printf("%-12s %14s %14s %14s %s\n", $jinxLabel, ms($jinx['seconds']), us_per_req($jinx['seconds'], $requests), rps($jinx['seconds'], $requests), substr($jinx['digest'], 0, 16));
printf("%s\n", str_repeat('-', 86));
printf("PHP-direct/%s ratio: %sx\n", $jinxLabel, number_format($ratio, 2));
printf("PHP checksum:  %s\n", $php['digest']);
printf("JINX checksum: %s\n", $jinx['digest']);
printf("Mismatches: %d\n", $mismatches);

$payload = [
    'kind' => 'JINX_WEB_BACK_PAGE_HOT_BENCHMARK',
    'requests' => $requests,
    'warmup' => $warmup,
    'workload' => $workload,
    'frame_cap' => $frameCap,
    'frame_level' => $frameLevel,
    'measured_frame_deck' => count($measureFrames),
    'jinx_mode' => $jinxMode,
    'mode' => $modeText,
    'php' => $php,
    'jinx' => $jinx,
    'ratio_php_direct_over_jinx' => $ratio,
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

echo "PASS: web back-page hot benchmark completed with PHP direct route and matching JINX responses" . PHP_EOL;
