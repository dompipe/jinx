<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRequestExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIncludeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExitExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectInheritanceExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExpressionBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleBuiltinBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleScalarBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleAppBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDataBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleTextBuiltinExecutor.php';

use jinx\oracle\OracleAppBuiltinExecutor;
use jinx\oracle\OracleBuiltinBatchExecutor;
use jinx\oracle\OracleDataBuiltinExecutor;
use jinx\oracle\OracleExecutionFamilies;
use jinx\oracle\OracleExpressionBatchExecutor;
use jinx\oracle\OracleMathBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleScalarBuiltinExecutor;
use jinx\oracle\OracleTextBuiltinExecutor;

/**
 * Worker/hot Oracle benchmark.
 *
 * This intentionally avoids spawning `php` and `./jinx` for every measured
 * iteration. It compiles each fixture once, warms the current worker, then loops
 * inside this one process. This is the layer that should expose worker-level
 * speedups instead of process/test-harness overhead.
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
        'iterations' => 1000,
        'warmup' => 100,
        'json' => null,
        'only' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "Oracle worker hot benchmark\n";
            echo "Usage: ./jinx scripts/benchmark-oracle-worker-hot.php [--iterations=N] [--warmup=N] [--only=family-or-group] [--json=path]\n";
            echo "Groups: builtin, scalar, app, math, data, text, expression\n";
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
        fail("Unknown worker benchmark option: {$arg}");
    }

    return $options;
}

/** @return array<string,array{group:string,fixture:string,executor:class-string,extra?:string}> */
function fixture_cases(): array
{
    return [
        // Earlier builtin batches.
        'str-replace-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-str-replace.php', 'executor' => OracleBuiltinBatchExecutor::class],
        'strpos-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-strpos.php', 'executor' => OracleBuiltinBatchExecutor::class],
        'explode-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-explode.php', 'executor' => OracleBuiltinBatchExecutor::class],
        'ltrim-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-ltrim.php', 'executor' => OracleBuiltinBatchExecutor::class],
        'array-slice-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-array-slice.php', 'executor' => OracleBuiltinBatchExecutor::class],

        // Scalar/type.
        'is-string-builtins' => ['group' => 'scalar', 'fixture' => 'fixtures/oracle-builtin-is-string.php', 'executor' => OracleScalarBuiltinExecutor::class],
        'intval-builtins' => ['group' => 'scalar', 'fixture' => 'fixtures/oracle-builtin-intval.php', 'executor' => OracleScalarBuiltinExecutor::class],
        'floatval-builtins' => ['group' => 'scalar', 'fixture' => 'fixtures/oracle-builtin-floatval.php', 'executor' => OracleScalarBuiltinExecutor::class],
        'is-numeric-builtins' => ['group' => 'scalar', 'fixture' => 'fixtures/oracle-builtin-is-numeric.php', 'executor' => OracleScalarBuiltinExecutor::class],

        // App/url/html/arrays.
        'str-contains-builtins' => ['group' => 'app', 'fixture' => 'fixtures/oracle-app-builtin-str-contains.php', 'executor' => OracleAppBuiltinExecutor::class],
        'number-format-builtins' => ['group' => 'app', 'fixture' => 'fixtures/oracle-app-builtin-number-format.php', 'executor' => OracleAppBuiltinExecutor::class],
        'array-combine-builtins' => ['group' => 'app', 'fixture' => 'fixtures/oracle-app-builtin-array-combine.php', 'executor' => OracleAppBuiltinExecutor::class],
        'parse-url-builtins' => ['group' => 'app', 'fixture' => 'fixtures/oracle-app-builtin-parse-url.php', 'executor' => OracleAppBuiltinExecutor::class],
        'htmlspecialchars-builtins' => ['group' => 'app', 'fixture' => 'fixtures/oracle-app-builtin-htmlspecialchars.php', 'executor' => OracleAppBuiltinExecutor::class],

        // Math.
        'sqrt-builtins' => ['group' => 'math', 'fixture' => 'fixtures/oracle-app-builtin-sqrt.php', 'executor' => OracleMathBuiltinExecutor::class],
        'pow-builtins' => ['group' => 'math', 'fixture' => 'fixtures/oracle-app-builtin-pow.php', 'executor' => OracleMathBuiltinExecutor::class],
        'sin-builtins' => ['group' => 'math', 'fixture' => 'fixtures/oracle-app-builtin-sin.php', 'executor' => OracleMathBuiltinExecutor::class],
        'hypot-builtins' => ['group' => 'math', 'fixture' => 'fixtures/oracle-app-builtin-hypot.php', 'executor' => OracleMathBuiltinExecutor::class],

        // Data/encoding.
        'base64-encode-builtins' => ['group' => 'data', 'fixture' => 'fixtures/oracle-data-builtin-base64-encode.php', 'executor' => OracleDataBuiltinExecutor::class],
        'hash-generic-builtins' => ['group' => 'data', 'fixture' => 'fixtures/oracle-data-builtin-hash.php', 'executor' => OracleDataBuiltinExecutor::class],
        'serialize-builtins' => ['group' => 'data', 'fixture' => 'fixtures/oracle-data-builtin-serialize.php', 'executor' => OracleDataBuiltinExecutor::class],
        'array-sum-builtins' => ['group' => 'data', 'fixture' => 'fixtures/oracle-data-builtin-array-sum.php', 'executor' => OracleDataBuiltinExecutor::class],

        // Text.
        'chr-builtins' => ['group' => 'text', 'fixture' => 'fixtures/oracle-text-builtin-chr.php', 'executor' => OracleTextBuiltinExecutor::class],
        'strcmp-builtins' => ['group' => 'text', 'fixture' => 'fixtures/oracle-text-builtin-strcmp.php', 'executor' => OracleTextBuiltinExecutor::class],
        'levenshtein-builtins' => ['group' => 'text', 'fixture' => 'fixtures/oracle-text-builtin-levenshtein.php', 'executor' => OracleTextBuiltinExecutor::class],
        'str-getcsv-builtins' => ['group' => 'text', 'fixture' => 'fixtures/oracle-text-builtin-str-getcsv.php', 'executor' => OracleTextBuiltinExecutor::class],
    ];
}

function run_php_fixture(string $fixture): mixed
{
    ob_start();
    try {
        return require $fixture;
    } finally {
        ob_end_clean();
    }
}

function run_oracle_fixture(array $program, string $family, string $executor): mixed
{
    if ($executor === OracleExpressionBatchExecutor::class || $executor === OracleBuiltinBatchExecutor::class || $executor === OracleScalarBuiltinExecutor::class || $executor === OracleAppBuiltinExecutor::class || $executor === OracleMathBuiltinExecutor::class || $executor === OracleDataBuiltinExecutor::class || $executor === OracleTextBuiltinExecutor::class) {
        $result = $executor::execute($program, $family);
    } else {
        $result = $executor::execute($program);
    }
    return $result['return'] ?? null;
}

function seconds_for(callable $fn, int $iterations): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    return (hrtime(true) - $start) / 1_000_000_000;
}

function ms(float $seconds): string
{
    return number_format($seconds * 1000, 3);
}

function speedup(float $phpSeconds, float $oracleSeconds): string
{
    if ($oracleSeconds <= 0.0) {
        return 'inf';
    }
    return number_format($phpSeconds / $oracleSeconds, 2) . 'x';
}

$root = dirname(__DIR__);
$options = parse_args($argv);
$iterations = (int) $options['iterations'];
$warmup = (int) $options['warmup'];
$only = is_string($options['only']) && $options['only'] !== '' ? $options['only'] : null;

$cases = fixture_cases();
$families = OracleExecutionFamilies::all();

$selected = [];
foreach ($cases as $family => $case) {
    if (!isset($families[$family])) {
        fail("Worker benchmark case is not in executable family ledger: {$family}");
    }
    if ($only !== null && $only !== $family && $only !== $case['group']) {
        continue;
    }
    $selected[$family] = $case;
}

if ($selected === []) {
    fail('No worker benchmark cases matched filter');
}

$compiled = [];
foreach ($selected as $family => $case) {
    $fixture = $root . '/' . $case['fixture'];
    if (!is_file($fixture)) {
        fail("Worker benchmark fixture missing: {$case['fixture']}");
    }
    $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
    $phpValue = run_php_fixture($fixture);
    $oracleValue = run_oracle_fixture($program, $family, $case['executor']);
    if ($phpValue !== $oracleValue) {
        fail("Worker benchmark parity mismatch for {$family}: PHP=" . var_export($phpValue, true) . ' Oracle=' . var_export($oracleValue, true));
    }
    $compiled[$family] = ['case' => $case, 'fixture' => $fixture, 'program' => $program];
}

foreach ($compiled as $family => $entry) {
    $case = $entry['case'];
    for ($i = 0; $i < $warmup; $i++) {
        run_php_fixture($entry['fixture']);
        run_oracle_fixture($entry['program'], $family, $case['executor']);
    }
}

printf("Oracle worker hot benchmark\n");
printf("Families measured: %d of %d executable families\n", count($compiled), count($families));
printf("Iterations per family: %d measured, %d warmup\n", $iterations, $warmup);
printf("Mode: single process / precompiled Oracle programs / no process-spawn timing\n\n");
printf("%-30s %-8s %12s %12s %12s\n", 'Family', 'Group', 'PHP ms', 'Oracle ms', 'PHP/Oracle');
printf("%s\n", str_repeat('-', 82));

$results = [];
$groupTotals = [];
$phpTotal = 0.0;
$oracleTotal = 0.0;

foreach ($compiled as $family => $entry) {
    $case = $entry['case'];
    $phpSeconds = seconds_for(static fn () => run_php_fixture($entry['fixture']), $iterations);
    $oracleSeconds = seconds_for(static fn () => run_oracle_fixture($entry['program'], $family, $case['executor']), $iterations);
    $phpTotal += $phpSeconds;
    $oracleTotal += $oracleSeconds;

    $group = $case['group'];
    $groupTotals[$group]['php'] = ($groupTotals[$group]['php'] ?? 0.0) + $phpSeconds;
    $groupTotals[$group]['oracle'] = ($groupTotals[$group]['oracle'] ?? 0.0) + $oracleSeconds;
    $groupTotals[$group]['families'] = ($groupTotals[$group]['families'] ?? 0) + 1;

    printf("%-30s %-8s %12s %12s %12s\n", $family, $group, ms($phpSeconds), ms($oracleSeconds), speedup($phpSeconds, $oracleSeconds));

    $results[] = [
        'family' => $family,
        'group' => $group,
        'fixture' => $case['fixture'],
        'iterations' => $iterations,
        'php_seconds' => $phpSeconds,
        'oracle_seconds' => $oracleSeconds,
        'speedup_php_over_oracle' => $oracleSeconds > 0.0 ? $phpSeconds / $oracleSeconds : null,
    ];
}

printf("%s\n", str_repeat('-', 82));
printf("%-30s %-8s %12s %12s %12s\n\n", 'TOTAL', 'all', ms($phpTotal), ms($oracleTotal), speedup($phpTotal, $oracleTotal));

printf("Group totals\n");
printf("%-12s %8s %12s %12s %12s\n", 'Group', 'Families', 'PHP ms', 'Oracle ms', 'PHP/Oracle');
printf("%s\n", str_repeat('-', 62));
ksort($groupTotals);
foreach ($groupTotals as $group => $totals) {
    printf("%-12s %8d %12s %12s %12s\n", $group, (int) $totals['families'], ms((float) $totals['php']), ms((float) $totals['oracle']), speedup((float) $totals['php'], (float) $totals['oracle']));
}

$payload = [
    'kind' => 'JINX_ORACLE_WORKER_HOT_BENCHMARK',
    'mode' => 'single-process-precompiled-worker',
    'iterations' => $iterations,
    'warmup' => $warmup,
    'family_count_total' => count($families),
    'family_count_measured' => count($compiled),
    'totals' => [
        'php_seconds' => $phpTotal,
        'oracle_seconds' => $oracleTotal,
        'speedup_php_over_oracle' => $oracleTotal > 0.0 ? $phpTotal / $oracleTotal : null,
    ],
    'groups' => $groupTotals,
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

echo "PASS: Oracle worker hot benchmark completed " . count($compiled) . " families" . PHP_EOL;
