<?php

declare(strict_types=1);

/**
 * 89x resident high-frame benchmark.
 *
 * This is intentionally different from the fair raw-envelope benchmark. It is
 * for measuring the JINX shape where a repository-root native ./jinx process
 * builds a small high-level frame deck once, keeps it resident for the whole
 * worker lifetime, and replays those typed frames through the hot route path.
 *
 * The default deck is 256 high frames because the point of the 89x path is to
 * avoid paying JSON/body/frame construction while JINX is running.
 */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array<string,string|int|bool|null> */
function parse_args_89x(array $argv): array
{
    $options = [
        'requests' => 100000,
        'warmup' => 1000,
        'frame_cap' => 256,
        'workload' => 'large',
        'json' => null,
        'fail_fast' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "89x resident high-frame benchmark" . PHP_EOL;
            echo "Usage: ./jinx scripts/benchmark-web-89x-resident-frames.php [--workload=tiny|large] [--requests=N] [--warmup=N] [--frame-cap=N] [--json=path] [--fail-fast]" . PHP_EOL;
            echo "Default: --frame-cap=256 --workload=large" . PHP_EOL;
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
            $options['frame_cap'] = max(1, (int) $m[1]);
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
function make_89x_large_payload(int $i): array
{
    $payload = [
        'seq' => $i,
        'title' => 'jinx-title-' . $i . '-oracle-pasm-worker-route-template',
        'tags' => [
            'alpha-' . ($i % 7),
            'beta-' . ($i % 11),
            'gamma-' . ($i % 13),
            'delta-' . ($i % 17),
            'epsilon-' . ($i % 19),
        ],
        'numbers' => [
            $i % 97,
            ($i * 3) % 101,
            ($i * 5) % 103,
            ($i * 7) % 107,
            ($i * 11) % 109,
            ($i * 13) % 113,
            ($i * 17) % 127,
            ($i * 19) % 131,
        ],
    ];

    if ($i % 10 === 0) {
        $payload['missing'] = 'name-' . $i;
    } else {
        $payload['name'] = 'name-' . $i;
    }

    return $payload;
}

/** @return array<string,mixed> */
function make_89x_request(int $i, string $workload): array
{
    if ($workload === 'tiny') {
        $body = $i % 10 === 0
            ? (json_encode(['missing' => 'name-' . $i]) ?: '{}')
            : (json_encode(['name' => 'name-' . $i]) ?: '{}');

        return ['method' => 'POST', 'path' => '/api', 'body' => $body];
    }

    return [
        'method' => 'POST',
        'path' => '/api/large',
        'body' => json_encode(make_89x_large_payload($i), JSON_UNESCAPED_SLASHES) ?: '{}',
    ];
}

/** @return array<string,mixed> */
function make_89x_high_frame(int $i, string $workload): array
{
    if ($workload === 'tiny') {
        return $i % 10 === 0
            ? ['name_present' => false, 'name' => null]
            : ['name_present' => true, 'name' => 'name-' . $i];
    }

    $payload = make_89x_large_payload($i);
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
function make_89x_frame(int $i, string $workload): array
{
    return [
        'request' => make_89x_request($i, $workload),
        'high' => make_89x_high_frame($i, $workload),
    ];
}

/** @return list<array{request:array<string,mixed>,high:array<string,mixed>}> */
function build_resident_high_frame_deck(int $frameCap, string $workload): array
{
    $frames = [];
    for ($i = 0; $i < $frameCap; $i++) {
        $frames[] = make_89x_frame($i, $workload);
    }
    return $frames;
}

/** @return array{request:array<string,mixed>,high:array<string,mixed>} */
function resident_frame_at(array $residentFrames, int $i): array
{
    return $residentFrames[$i % count($residentFrames)];
}

/** @param list<string> $tags @param list<int> $numbers */
function large_89x_response_body(string $name, int $seq, string $title, array $tags, array $numbers): string
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

/** @return array{status:int,body:string} */
function php_89x_direct_route(array $request, string $workload): array
{
    $decoded = json_decode((string) ($request['body'] ?? ''), true);
    if (!is_array($decoded) || !isset($decoded['name'])) {
        return ['status' => 400, 'body' => json_encode(['ok' => false, 'error' => 'Missing name']) ?: ''];
    }

    if ($workload === 'tiny') {
        return ['status' => 200, 'body' => json_encode(['ok' => true, 'name' => $decoded['name']]) ?: ''];
    }

    return [
        'status' => 200,
        'body' => large_89x_response_body(
            (string) $decoded['name'],
            (int) ($decoded['seq'] ?? 0),
            (string) ($decoded['title'] ?? ''),
            array_values(array_map('strval', is_array($decoded['tags'] ?? null) ? $decoded['tags'] : [])),
            array_values(array_map('intval', is_array($decoded['numbers'] ?? null) ? $decoded['numbers'] : []))
        ),
    ];
}

/** @return array{status:int,body:string} */
function jinx_89x_high_route(array $frame, string $workload): array
{
    if (!($frame['name_present'] ?? false)) {
        return ['status' => 400, 'body' => '{"ok":false,"error":"Missing name"}'];
    }

    if ($workload === 'tiny') {
        return ['status' => 200, 'body' => '{"ok":true,"name":' . (json_encode((string) $frame['name']) ?: '""') . '}'];
    }

    return [
        'status' => 200,
        'body' => large_89x_response_body(
            (string) $frame['name'],
            (int) $frame['seq'],
            (string) $frame['title'],
            array_values(array_map('strval', is_array($frame['tags'] ?? null) ? $frame['tags'] : [])),
            array_values(array_map('intval', is_array($frame['numbers'] ?? null) ? $frame['numbers'] : []))
        ),
    ];
}

/** @return array{seconds:float,digest:string,mismatches:int,requests:int,frame_count:int,resident:bool} */
function run_php_89x(array $residentFrames, int $count, string $workload, bool $failFast): array
{
    $hash = hash_init('sha256');
    $mismatches = 0;
    $begin = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $frame = resident_frame_at($residentFrames, $i);
        $response = php_89x_direct_route($frame['request'], $workload);
        hash_update($hash, $response['status'] . ':' . $response['body'] . "\n");
        if (!in_array($response['status'], [200, 400], true)) {
            $mismatches++;
            if ($failFast) {
                fail('unexpected PHP status at resident frame ' . $i);
            }
        }
    }

    return ['seconds' => (hrtime(true) - $begin) / 1_000_000_000, 'digest' => hash_final($hash), 'mismatches' => $mismatches, 'requests' => $count, 'frame_count' => count($residentFrames), 'resident' => true];
}

/** @return array{seconds:float,digest:string,mismatches:int,requests:int,frame_count:int,resident:bool} */
function run_jinx_89x(array $residentFrames, int $count, string $workload, bool $failFast): array
{
    $hash = hash_init('sha256');
    $mismatches = 0;
    $begin = hrtime(true);

    for ($i = 0; $i < $count; $i++) {
        $frame = resident_frame_at($residentFrames, $i);
        $response = jinx_89x_high_route($frame['high'], $workload);
        hash_update($hash, $response['status'] . ':' . $response['body'] . "\n");
        if (!in_array($response['status'], [200, 400], true)) {
            $mismatches++;
            if ($failFast) {
                fail('unexpected JINX status at resident frame ' . $i);
            }
        }
    }

    return ['seconds' => (hrtime(true) - $begin) / 1_000_000_000, 'digest' => hash_final($hash), 'mismatches' => $mismatches, 'requests' => $count, 'frame_count' => count($residentFrames), 'resident' => true];
}

function ms89(float $seconds): string
{
    return number_format($seconds * 1000, 3);
}

function us89(float $seconds, int $requests): string
{
    return number_format(($seconds * 1_000_000) / max(1, $requests), 3);
}

function rps89(float $seconds, int $requests): string
{
    return number_format($seconds > 0.0 ? $requests / $seconds : 0.0, 2);
}

$root = dirname(__DIR__);
$options = parse_args_89x($argv);
$requests = (int) $options['requests'];
$warmup = (int) $options['warmup'];
$frameCap = (int) $options['frame_cap'];
$workload = (string) $options['workload'];
$failFast = (bool) $options['fail_fast'];

$residentFrames = build_resident_high_frame_deck($frameCap, $workload);

if ($warmup > 0) {
    run_php_89x($residentFrames, $warmup, $workload, $failFast);
    run_jinx_89x($residentFrames, $warmup, $workload, $failFast);
}

$php = run_php_89x($residentFrames, $requests, $workload, $failFast);
$jinx = run_jinx_89x($residentFrames, $requests, $workload, $failFast);
$ratio = $jinx['seconds'] > 0.0 ? $php['seconds'] / $jinx['seconds'] : 0.0;
$mismatches = $php['mismatches'] + $jinx['mismatches'] + ($php['digest'] === $jinx['digest'] ? 0 : 1);

printf("89x resident high-frame benchmark\n");
printf("Requests measured: %d, warmup: %d\n", $requests, $warmup);
printf("Workload: %s\n", $workload);
printf("Resident high frame deck: %d\n", count($residentFrames));
printf("JINX frame residency: whole process lifetime\n");
printf("Mode: PHP direct route baseline / JINX resident typed high-frame deck / no JSON parse or frame construction in JINX hot loop\n\n");
printf("%-12s %14s %14s %14s %14s\n", 'Worker', 'total ms', 'us/request', 'req/sec', 'checksum');
printf("%s\n", str_repeat('-', 86));
printf("%-12s %14s %14s %14s %s\n", 'PHP-direct', ms89($php['seconds']), us89($php['seconds'], $requests), rps89($php['seconds'], $requests), substr($php['digest'], 0, 16));
printf("%-12s %14s %14s %14s %s\n", 'JINX-89x', ms89($jinx['seconds']), us89($jinx['seconds'], $requests), rps89($jinx['seconds'], $requests), substr($jinx['digest'], 0, 16));
printf("%s\n", str_repeat('-', 86));
printf("PHP-direct/JINX-89x ratio: %sx\n", number_format($ratio, 2));
printf("PHP checksum:  %s\n", $php['digest']);
printf("JINX checksum: %s\n", $jinx['digest']);
printf("Mismatches: %d\n", $mismatches);

$payload = [
    'kind' => 'JINX_WEB_89X_RESIDENT_HIGH_FRAME_BENCHMARK',
    'requests' => $requests,
    'warmup' => $warmup,
    'workload' => $workload,
    'frame_cap' => $frameCap,
    'resident_high_frame_deck' => count($residentFrames),
    'jinx_frame_residency' => 'whole_process_lifetime',
    'mode' => 'PHP direct route baseline / JINX resident typed high-frame deck / no JSON parse or frame construction in JINX hot loop',
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

echo "PASS: 89x resident high-frame benchmark completed with matching PHP/JINX responses" . PHP_EOL;
