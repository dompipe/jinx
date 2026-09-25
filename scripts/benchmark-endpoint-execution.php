<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

require_once $root . '/runtime/WebApiCompiler.php';

$source = $root . '/fixtures/simple-web-api-validated.php';
$compiled = $root . '/build/web-compiled/endpoint-execution-benchmark.compiled.php';

jinx\web\WebApiCompiler::compileFileToEndpoint($source, $compiled);

if (!is_file($compiled)) {
    fail('compiled endpoint was not created');
}

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 10000;

function runEndpointFile(string $file, string $json): array
{
    $_SERVER['REQUEST_METHOD'] = 'POST';

    $tmp = tempnam(sys_get_temp_dir(), 'jinx_input_');

    if ($tmp === false) {
        fail('could not create temp input');
    }

    file_put_contents($tmp, $json);

    // Endpoint files read php://input, which cannot be injected directly in CLI.
    // So this benchmark uses a generated CLI wrapper by replacing php://input reads.
    $code = (string) file_get_contents($file);
    $code = preg_replace('/<\?php\s*/', '', $code, 1) ?? $code;
    $code = str_replace('file_get_contents("php://input")', 'file_get_contents(' . var_export($tmp, true) . ')', $code);
    $code = str_replace("file_get_contents('php://input')", 'file_get_contents(' . var_export($tmp, true) . ')', $code);

    ob_start();
    $status = 200;

    $http_response_code = function (int $code) use (&$status): void {
        $status = $code;
    };

    // Rewrite http_response_code(...) so it does not leak process-global benchmark state.
    $code = preg_replace('/\bhttp_response_code\s*\(/', '$http_response_code(', $code) ?? $code;

    try {
        eval($code);
    } finally {
        $body = ob_get_clean();
        @unlink($tmp);
    }

    return [$status, $body];
}

function benchmarkEndpoint(string $label, string $file, int $iterations): array
{
    for ($i = 0; $i < 100; $i++) {
        [$status, $body] = runEndpointFile($file, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} warmup bad status {$status}: {$body}");
        }

        if (json_decode($body, true) !== ['ok' => true, 'name' => 'Anthony']) {
            fail("{$label} warmup bad body: {$body}");
        }
    }

    $start = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        [$status, $body] = runEndpointFile($file, '{"name":"Anthony"}');

        if ($status !== 200) {
            fail("{$label} bad status {$status}: {$body}");
        }
    }

    $elapsedNs = hrtime(true) - $start;
    $totalMs = $elapsedNs / 1_000_000;
    $avgUs = ($elapsedNs / 1000) / $iterations;

    return [
        'label' => $label,
        'total_ms' => $totalMs,
        'avg_us' => $avgUs,
        'runs_per_sec' => $iterations / ($totalMs / 1000),
    ];
}

$native = benchmarkEndpoint('native endpoint source', $source, $iterations);
$compiledResult = benchmarkEndpoint('compiled endpoint direct PHP', $compiled, $iterations);

$ratio = $compiledResult['avg_us'] / max($native['avg_us'], 0.000001);

echo PHP_EOL;
echo "JINX Endpoint Execution Benchmark" . PHP_EOL;
echo "Iterations: {$iterations}" . PHP_EOL;
echo PHP_EOL;

printf("%-34s %12s %12s %12s\n", 'target', 'total ms', 'avg us', 'runs/sec');
printf(
    "%-34s %12.3f %12.3f %12.1f\n",
    $native['label'],
    $native['total_ms'],
    $native['avg_us'],
    $native['runs_per_sec']
);
printf(
    "%-34s %12.3f %12.3f %12.1f\n",
    $compiledResult['label'],
    $compiledResult['total_ms'],
    $compiledResult['avg_us'],
    $compiledResult['runs_per_sec']
);

echo PHP_EOL;
printf("compiled/native execution ratio: %.2fx\n", $ratio);
echo PHP_EOL;
