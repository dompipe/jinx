<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleExecutionFamilies;

/**
 * Benchmarks every executable Oracle family parity path against PHP and native ./jinx.
 *
 * Run through repository-root native ./jinx:
 *   ./jinx scripts/benchmark-oracle-families.php --iterations=5
 *
 * The benchmark uses the executable family ledger as the source of truth, groups
 * families by their parity test, and measures each group under both the PHP
 * interpreter and repository-root native ./jinx.
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
        'iterations' => 3,
        'warmup' => 1,
        'json' => null,
        'only' => null,
        'fail_fast' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--fail-fast') {
            $options['fail_fast'] = true;
            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            echo "Oracle family benchmark\n";
            echo "Usage: ./jinx scripts/benchmark-oracle-families.php [--iterations=N] [--warmup=N] [--only=family-or-test] [--json=path] [--fail-fast]\n";
            exit(0);
        }
        if (preg_match('/^--iterations=(\d+)$/', $arg, $m)) {
            $options['iterations'] = max(1, (int) $m[1]);
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
        if (str_starts_with($arg, '--only=')) {
            $options['only'] = substr($arg, strlen('--only='));
            continue;
        }
        fail("Unknown benchmark option: {$arg}");
    }

    return $options;
}

/** @return array{code:int,stdout:string,stderr:string,seconds:float} */
function run_command(array $command): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $start = hrtime(true);
    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        fail('Could not start benchmark command: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $seconds = (hrtime(true) - $start) / 1_000_000_000;

    return [
        'code' => $code,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
        'seconds' => $seconds,
    ];
}

/** @return array{best:float,average:float,total:float,runs:list<float>,last:array{code:int,stdout:string,stderr:string,seconds:float}} */
function benchmark_command(array $command, int $warmup, int $iterations): array
{
    for ($i = 0; $i < $warmup; $i++) {
        $warm = run_command($command);
        if ($warm['code'] !== 0) {
            return [
                'best' => INF,
                'average' => INF,
                'total' => INF,
                'runs' => [],
                'last' => $warm,
            ];
        }
    }

    $runs = [];
    $last = ['code' => 0, 'stdout' => '', 'stderr' => '', 'seconds' => 0.0];
    for ($i = 0; $i < $iterations; $i++) {
        $last = run_command($command);
        if ($last['code'] !== 0) {
            return [
                'best' => INF,
                'average' => INF,
                'total' => INF,
                'runs' => $runs,
                'last' => $last,
            ];
        }
        $runs[] = $last['seconds'];
    }

    $total = array_sum($runs);
    return [
        'best' => min($runs),
        'average' => $total / max(1, count($runs)),
        'total' => $total,
        'runs' => $runs,
        'last' => $last,
    ];
}

function ms(float $seconds): string
{
    if (!is_finite($seconds)) {
        return 'FAIL';
    }
    return number_format($seconds * 1000, 3);
}

function ratio(float $phpSeconds, float $jinxSeconds): string
{
    if (!is_finite($phpSeconds) || !is_finite($jinxSeconds) || $phpSeconds <= 0.0) {
        return 'n/a';
    }
    return number_format($jinxSeconds / $phpSeconds, 2) . 'x';
}

$root = dirname(__DIR__);
$options = parse_args($argv);
$iterations = (int) $options['iterations'];
$warmup = (int) $options['warmup'];
$only = is_string($options['only']) && $options['only'] !== '' ? $options['only'] : null;
$failFast = (bool) $options['fail_fast'];

$php = getenv('PHP_BIN') ?: PHP_BINARY;
$jinx = $root . '/jinx';

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}
if (!is_file($php) && !str_contains($php, '/')) {
    // Allow PATH lookup for PHP_BIN=php while keeping PHP_BINARY absolute when available.
    $php = (string) $php;
}

$families = OracleExecutionFamilies::all();
$groups = [];
foreach ($families as $family => $metadata) {
    $test = $metadata['test'] ?? null;
    if (!is_string($test) || $test === '') {
        fail("{$family} missing benchmark test metadata");
    }
    if ($only !== null && $only !== $family && $only !== $test && !str_contains($test, $only)) {
        continue;
    }
    $groups[$test][] = $family;
}
ksort($groups);

if ($groups === []) {
    fail('No benchmark groups matched the requested filter');
}

printf("Oracle family benchmark\n");
printf("Repository: %s\n", $root);
printf("Families: %d total, %d benchmarked, %d unique parity tests\n", count($families), array_sum(array_map('count', $groups)), count($groups));
printf("Iterations: %d measured, %d warmup per side\n", $iterations, $warmup);
printf("PHP baseline: %s\n", $php);
printf("JINX native: %s\n\n", $jinx);

$results = [];
$phpTotal = 0.0;
$jinxTotal = 0.0;
$failed = 0;

printf("%-44s %8s %12s %12s %10s %s\n", 'Parity test', 'Families', 'PHP avg ms', 'JINX avg ms', 'JINX/PHP', 'Status');
printf("%s\n", str_repeat('-', 104));

foreach ($groups as $test => $coveredFamilies) {
    $path = $root . '/' . $test;
    if (!is_file($path)) {
        fail("Benchmark test file does not exist: {$test}");
    }

    $phpBench = benchmark_command([$php, $path], $warmup, $iterations);
    $jinxBench = benchmark_command([$jinx, $test], $warmup, $iterations);

    $status = 'OK';
    if (($phpBench['last']['code'] ?? 0) !== 0 || ($jinxBench['last']['code'] ?? 0) !== 0) {
        $status = 'FAIL';
        $failed++;
        if ($failFast) {
            fwrite(STDERR, "FAIL: {$test}\nPHP stderr:\n" . $phpBench['last']['stderr'] . "\nJINX stderr:\n" . $jinxBench['last']['stderr'] . "\n");
            exit(1);
        }
    } else {
        $phpTotal += $phpBench['average'];
        $jinxTotal += $jinxBench['average'];
    }

    printf(
        "%-44s %8d %12s %12s %10s %s\n",
        strlen($test) > 44 ? substr($test, 0, 41) . '...' : $test,
        count($coveredFamilies),
        ms($phpBench['average']),
        ms($jinxBench['average']),
        ratio($phpBench['average'], $jinxBench['average']),
        $status
    );

    $results[] = [
        'test' => $test,
        'families' => $coveredFamilies,
        'family_count' => count($coveredFamilies),
        'php' => [
            'average_seconds' => $phpBench['average'],
            'best_seconds' => $phpBench['best'],
            'runs_seconds' => $phpBench['runs'],
            'exit_code' => $phpBench['last']['code'],
        ],
        'jinx' => [
            'average_seconds' => $jinxBench['average'],
            'best_seconds' => $jinxBench['best'],
            'runs_seconds' => $jinxBench['runs'],
            'exit_code' => $jinxBench['last']['code'],
        ],
        'ratio_jinx_over_php' => is_finite($phpBench['average']) && $phpBench['average'] > 0.0 && is_finite($jinxBench['average']) ? $jinxBench['average'] / $phpBench['average'] : null,
        'status' => $status,
    ];
}

printf("%s\n", str_repeat('-', 104));
printf("%-44s %8d %12s %12s %10s %s\n", 'TOTAL passing groups', array_sum(array_map('count', $groups)), ms($phpTotal), ms($jinxTotal), ratio($phpTotal, $jinxTotal), $failed === 0 ? 'OK' : "FAIL={$failed}");

$payload = [
    'kind' => 'JINX_ORACLE_BENCHMARK',
    'iterations' => $iterations,
    'warmup' => $warmup,
    'family_count_total' => count($families),
    'family_count_benchmarked' => array_sum(array_map('count', $groups)),
    'test_group_count' => count($groups),
    'php_binary' => $php,
    'jinx_binary' => $jinx,
    'totals' => [
        'php_average_seconds_sum' => $phpTotal,
        'jinx_average_seconds_sum' => $jinxTotal,
        'ratio_jinx_over_php' => $phpTotal > 0.0 ? $jinxTotal / $phpTotal : null,
        'failed_groups' => $failed,
    ],
    'results' => $results,
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

if ($failed > 0) {
    exit(1);
}

echo "PASS: Oracle benchmark completed " . count($groups) . " parity groups covering " . array_sum(array_map('count', $groups)) . " executable families" . PHP_EOL;
