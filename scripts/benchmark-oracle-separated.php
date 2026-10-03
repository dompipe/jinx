<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForeachExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDoWhileExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGotoExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleBuiltinBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleTextBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleBuiltinBatchExecutor;
use jinx\oracle\OracleConditionalExecutor;
use jinx\oracle\OracleDoWhileExecutor;
use jinx\oracle\OracleExecutionFamilies;
use jinx\oracle\OracleForExecutor;
use jinx\oracle\OracleForeachExecutor;
use jinx\oracle\OracleGotoExecutor;
use jinx\oracle\OracleLoopExecutor;
use jinx\oracle\OracleMathBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleTextBuiltinExecutor;

/**
 * Separates direct PHP fixture timing from JINX/Oracle interpreter timing.
 *
 * Run through repository-root native ./jinx:
 *   ./jinx scripts/benchmark-oracle-separated.php --engine=jinx --iterations=10000
 *   ./jinx scripts/benchmark-oracle-separated.php --engine=php --iterations=10000
 *   ./jinx scripts/benchmark-oracle-separated.php --engine=both --iterations=10000
 */

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array<string,string|int|null> */
function parse_args(array $argv): array
{
    $options = [
        'iterations' => 1000,
        'warmup' => 100,
        'engine' => 'both',
        'only' => null,
        'json' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            echo "Separated JINX/PHP Oracle benchmark\n";
            echo "Usage: ./jinx scripts/benchmark-oracle-separated.php [--engine=php|jinx|both] [--iterations=N] [--warmup=N] [--only=family-or-group] [--json=path]\n";
            echo "Engines: php times direct PHP fixture execution; jinx times the precompiled Oracle interpreter path; both prints both without merging the timed loops.\n";
            echo "Groups: control, builtin, math, text\n";
            echo "Coverage: curated fixture benchmark cases, not the full merged Oracle family ledger.\n";
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
        if (preg_match('/^--engine=(php|jinx|both)$/', $arg, $m)) {
            $options['engine'] = $m[1];
            continue;
        }
        if (str_starts_with($arg, '--only=')) {
            $options['only'] = substr($arg, strlen('--only='));
            continue;
        }
        if (str_starts_with($arg, '--json=')) {
            $options['json'] = substr($arg, strlen('--json='));
            continue;
        }
        fail("Unknown separated benchmark option: {$arg}");
    }

    return $options;
}

/**
 * @return array<string,array{group:string,fixture:string,executor:class-string|null}>
 */
function benchmark_cases(): array
{
    return [
        'straight-line' => ['group' => 'control', 'fixture' => 'fixtures/oracle-executable-straightline.php', 'executor' => null],
        'conditionals' => ['group' => 'control', 'fixture' => 'fixtures/oracle-executable-conditionals.php', 'executor' => OracleConditionalExecutor::class],
        'loops' => ['group' => 'control', 'fixture' => 'fixtures/oracle-executable-loops.php', 'executor' => OracleLoopExecutor::class],
        'foreach-loops' => ['group' => 'control', 'fixture' => 'fixtures/oracle-executable-foreach.php', 'executor' => OracleForeachExecutor::class],
        'for-loops' => ['group' => 'control', 'fixture' => 'fixtures/oracle-executable-for.php', 'executor' => OracleForExecutor::class],
        'do-while-loops' => ['group' => 'control', 'fixture' => 'fixtures/oracle-executable-do-while.php', 'executor' => OracleDoWhileExecutor::class],
        'goto-labels' => ['group' => 'control', 'fixture' => 'fixtures/oracle-executable-goto-label.php', 'executor' => OracleGotoExecutor::class],
        'str-replace-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-str-replace.php', 'executor' => OracleBuiltinBatchExecutor::class],
        'strpos-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-strpos.php', 'executor' => OracleBuiltinBatchExecutor::class],
        'array-slice-builtins' => ['group' => 'builtin', 'fixture' => 'fixtures/oracle-builtin-array-slice.php', 'executor' => OracleBuiltinBatchExecutor::class],
        'sqrt-builtins' => ['group' => 'math', 'fixture' => 'fixtures/oracle-app-builtin-sqrt.php', 'executor' => OracleMathBuiltinExecutor::class],
        'pow-builtins' => ['group' => 'math', 'fixture' => 'fixtures/oracle-app-builtin-pow.php', 'executor' => OracleMathBuiltinExecutor::class],
        'chr-builtins' => ['group' => 'text', 'fixture' => 'fixtures/oracle-text-builtin-chr.php', 'executor' => OracleTextBuiltinExecutor::class],
        'strcmp-builtins' => ['group' => 'text', 'fixture' => 'fixtures/oracle-text-builtin-strcmp.php', 'executor' => OracleTextBuiltinExecutor::class],
    ];
}

/** @return array{output:string,return:mixed} */
function run_php_fixture(string $fixture): array
{
    ob_start();
    try {
        $return = require $fixture;
        $output = (string) ob_get_clean();

        return ['output' => $output, 'return' => $return];
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/**
 * @param array<string,mixed> $program
 * @param class-string|null $executor
 * @return array{output:string,return:mixed}
 */
function run_jinx_oracle_program(array $program, string $family, ?string $executor): array
{
    if ($executor === null) {
        $result = OracleProgramCompiler::executeSupportedOracleProgram($program);
    } elseif ($executor === OracleBuiltinBatchExecutor::class || $executor === OracleMathBuiltinExecutor::class || $executor === OracleTextBuiltinExecutor::class) {
        $result = $executor::execute($program, $family);
    } else {
        $result = $executor::execute($program);
    }

    return [
        'output' => (string) ($result['output'] ?? ''),
        'return' => $result['return'] ?? null,
    ];
}

function seconds_for(callable $fn, int $iterations): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }

    return (hrtime(true) - $start) / 1_000_000_000;
}

function ms(?float $seconds): string
{
    if ($seconds === null) {
        return '-';
    }

    return number_format($seconds * 1000, 3);
}

function speedup(?float $phpSeconds, ?float $jinxSeconds): string
{
    if ($phpSeconds === null || $jinxSeconds === null || $jinxSeconds <= 0.0) {
        return '-';
    }

    return number_format($phpSeconds / $jinxSeconds, 2) . 'x';
}

$root = dirname(__DIR__);
$options = parse_args($argv);
$engine = (string) $options['engine'];
$iterations = (int) $options['iterations'];
$warmup = (int) $options['warmup'];
$only = is_string($options['only']) && $options['only'] !== '' ? $options['only'] : null;

$families = OracleExecutionFamilies::all();
$allBenchmarkCases = benchmark_cases();
$cases = [];
foreach ($allBenchmarkCases as $family => $case) {
    if (!isset($families[$family])) {
        fail("Separated benchmark case is not executable in the family ledger: {$family}");
    }
    if ($only !== null && $only !== $family && $only !== $case['group']) {
        continue;
    }
    $fixture = $root . '/' . $case['fixture'];
    if (!is_file($fixture)) {
        fail("Separated benchmark fixture is missing: {$case['fixture']}");
    }
    $case['absolute_fixture'] = $fixture;
    $case['program'] = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
    $cases[$family] = $case;
}

if ($cases === []) {
    fail('No separated benchmark cases matched filter');
}

$coverageShape = [
    'semantic_coverage' => 'curated fixture benchmark cases, not the full merged Oracle family ledger',
    'benchmark_case_count_total' => count($allBenchmarkCases),
    'benchmark_case_count_measured' => count($cases),
    'regular_ledger_family_count' => count($families),
    'groups_available' => array_values(array_unique(array_column($allBenchmarkCases, 'group'))),
];

if ($engine === 'both') {
    foreach ($cases as $family => $case) {
        $php = run_php_fixture($case['absolute_fixture']);
        $jinx = run_jinx_oracle_program($case['program'], $family, $case['executor']);
        if ($php !== $jinx) {
            fail("Separated benchmark parity mismatch for {$family}: PHP=" . var_export($php, true) . ' JINX=' . var_export($jinx, true));
        }
    }
}

foreach ($cases as $family => $case) {
    for ($i = 0; $i < $warmup; $i++) {
        if ($engine === 'php' || $engine === 'both') {
            run_php_fixture($case['absolute_fixture']);
        }
        if ($engine === 'jinx' || $engine === 'both') {
            run_jinx_oracle_program($case['program'], $family, $case['executor']);
        }
    }
}

printf("Separated JINX/PHP Oracle benchmark\n");
printf("Repository: %s\n", $root);
printf("Engine: %s\n", $engine);
printf("Families measured: %d curated benchmark cases of %d regular executable families\n", count($cases), count($families));
printf("Semantic coverage: %s\n", $coverageShape['semantic_coverage']);
printf("Iterations per family: %d measured, %d warmup\n", $iterations, $warmup);
printf("JINX mode: precompiled Oracle program interpreted by the JINX Oracle executor\n");
printf("PHP mode: direct require of the original PHP fixture\n\n");

printf("%-28s %-8s %12s %12s %12s\n", 'Family', 'Group', 'PHP ms', 'JINX ms', 'PHP/JINX');
printf("%s\n", str_repeat('-', 78));

$results = [];
$phpTotal = null;
$jinxTotal = null;
foreach ($cases as $family => $case) {
    $phpSeconds = null;
    $jinxSeconds = null;

    if ($engine === 'php' || $engine === 'both') {
        $phpSeconds = seconds_for(static fn () => run_php_fixture($case['absolute_fixture']), $iterations);
        $phpTotal = ($phpTotal ?? 0.0) + $phpSeconds;
    }

    if ($engine === 'jinx' || $engine === 'both') {
        $jinxSeconds = seconds_for(static fn () => run_jinx_oracle_program($case['program'], $family, $case['executor']), $iterations);
        $jinxTotal = ($jinxTotal ?? 0.0) + $jinxSeconds;
    }

    printf(
        "%-28s %-8s %12s %12s %12s\n",
        $family,
        $case['group'],
        ms($phpSeconds),
        ms($jinxSeconds),
        speedup($phpSeconds, $jinxSeconds)
    );

    $results[] = [
        'family' => $family,
        'group' => $case['group'],
        'fixture' => $case['fixture'],
        'iterations' => $iterations,
        'php_seconds' => $phpSeconds,
        'jinx_seconds' => $jinxSeconds,
        'speedup_php_over_jinx' => $phpSeconds !== null && $jinxSeconds !== null && $jinxSeconds > 0.0 ? $phpSeconds / $jinxSeconds : null,
    ];
}

printf("%s\n", str_repeat('-', 78));
printf("%-28s %-8s %12s %12s %12s\n", 'TOTAL', 'all', ms($phpTotal), ms($jinxTotal), speedup($phpTotal, $jinxTotal));

$payload = [
    'kind' => 'JINX_ORACLE_SEPARATED_BENCHMARK',
    'engine' => $engine,
    'iterations' => $iterations,
    'warmup' => $warmup,
    'coverage_shape' => $coverageShape,
    'family_count_total' => count($families),
    'family_count_measured' => count($cases),
    'totals' => [
        'php_seconds' => $phpTotal,
        'jinx_seconds' => $jinxTotal,
        'speedup_php_over_jinx' => $phpTotal !== null && $jinxTotal !== null && $jinxTotal > 0.0 ? $phpTotal / $jinxTotal : null,
    ],
    'results' => $results,
];

$jsonPath = is_string($options['json']) && $options['json'] !== '' ? $options['json'] : null;
if ($jsonPath !== null) {
    $target = str_starts_with($jsonPath, '/') ? $jsonPath : $root . '/' . $jsonPath;
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("Could not create separated benchmark JSON directory: {$dir}");
    }
    file_put_contents($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    echo "JSON: {$target}" . PHP_EOL;
}

echo "PASS: separated JINX/PHP Oracle benchmark completed with engine={$engine}" . PHP_EOL;
