<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$jinxCommand = escapeshellarg($jinx);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run_native_jinx(string $label, string $command, string $expected): void
{
    global $jinxCommand;

    $out = [];
    $code = 0;
    exec($jinxCommand . ' ' . $command . ' 2>&1', $out, $code);
    $text = implode(PHP_EOL, $out) . (count($out) ? PHP_EOL : '');

    if ($code !== 0) {
        fail("{$label} failed:\n{$text}");
    }

    if (!str_contains($text, $expected)) {
        fail("{$label} did not produce expected marker {$expected}:\n{$text}");
    }

    echo "PASS: {$label}" . PHP_EOL;
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

echo "JINX native verification suite" . PHP_EOL;
echo "Binary: {$jinx}" . PHP_EOL;

$scriptTests = [
    'execution families ledger' => ['scripts/test-oracle-execution-families.php', 'PASS: Oracle execution families expose'],
    'straight-line family' => ['scripts/test-oracle-straightline-execution.php', 'PASS: Oracle executes straight-line PHP subset'],
    'conditionals family' => ['scripts/test-oracle-conditional-execution.php', 'PASS: Oracle executes conditional PHP subset'],
    'loops family' => ['scripts/test-oracle-loop-execution.php', 'PASS: Oracle executes loop PHP subset'],
    'arrays family' => ['scripts/test-oracle-array-execution.php', 'PASS: Oracle executes array PHP subset'],
    'functions family' => ['scripts/test-oracle-function-execution.php', 'PASS: Oracle executes function PHP subset'],
    'request globals family' => ['scripts/test-oracle-request-globals-execution.php', 'PASS: Oracle executes request globals PHP subset'],
    'include require family' => ['scripts/test-oracle-include-require-execution.php', 'PASS: Oracle executes include/require PHP subset'],
    'exit die family' => ['scripts/test-oracle-exit-die-execution.php', 'PASS: Oracle executes exit/die PHP subset'],
    'object basics family' => ['scripts/test-oracle-object-basics-execution.php', 'PASS: Oracle executes object basics PHP subset'],
    'object inheritance family' => ['scripts/test-oracle-object-inheritance-execution.php', 'PASS: Oracle executes object inheritance PHP subset'],
    'next ten families' => ['scripts/test-oracle-next-ten-execution.php', 'PASS: Oracle executes next ten PHP families'],
    'builtin batch families' => ['scripts/test-oracle-builtin-batch-execution.php', 'PASS: Oracle executes builtin batch PHP families'],
    'builtin batch two families' => ['scripts/test-oracle-builtin-batch-two-execution.php', 'PASS: Oracle executes builtin batch two PHP families'],
    'scalar builtin families' => ['scripts/test-oracle-scalar-builtin-execution.php', 'PASS: Oracle executes scalar builtin PHP families'],
    'app builtin families' => ['scripts/test-oracle-app-builtin-execution.php', 'PASS: Oracle executes app builtin PHP families'],
    'math builtin families' => ['scripts/test-oracle-math-builtin-execution.php', 'PASS: Oracle executes math builtin PHP families'],
    'data builtin families' => ['scripts/test-oracle-data-builtin-execution.php', 'PASS: Oracle executes data builtin PHP families'],
    'text builtin families' => ['scripts/test-oracle-text-builtin-execution.php', 'PASS: Oracle executes text builtin PHP families'],
    'program compiler' => ['scripts/test-oracle-program-compiler.php', 'PASS: OracleProgramCompiler interprets PHP'],
    'benchmark command help' => ['scripts/benchmark-oracle-families.php --help', 'Oracle family benchmark'],
    'worker benchmark help' => ['scripts/benchmark-oracle-worker-hot.php --help', 'Oracle worker hot benchmark'],
    'full coverage audit' => ['scripts/test-oracle-full-coverage-audit.php', 'PASS: Oracle full coverage audit validates'],
];

foreach ($scriptTests as $label => [$script, $expected]) {
    run_native_jinx($label, $script, $expected);
}

run_native_jinx(
    'web-plan',
    escapeshellarg('scripts/test-web-plan-native-proxy.php') . ' ' . escapeshellarg($root . '/fixtures/simple-web-api-validated.php'),
    'WEB_IF_MISSING_ARRAY_KEY'
);

$outFile = $root . '/build/web-compiled/native-suite.compiled.php';
run_native_jinx(
    'web-compile',
    escapeshellarg('scripts/web-api-compile.php') . ' ' . escapeshellarg($root . '/fixtures/simple-web-api-validated.php') . ' ' . escapeshellarg($outFile),
    'compiled'
);

if (!is_file($outFile)) {
    fail('web-compile did not write expected output file');
}

$outJson = $root . '/build/web-statements/native-suite.web.json';
run_native_jinx(
    'web-statements',
    escapeshellarg('scripts/web-compile.php') . ' ' . escapeshellarg($root . '/fixtures/oracle-post-curl-dynamic.php') . ' ' . escapeshellarg($outJson),
    'compiled'
);

if (!is_file($outJson)) {
    fail('web-statements did not write expected JSON output file');
}

$json = (string) file_get_contents($outJson);
if (!str_contains($json, 'JINX_WEB_PROGRAM')) {
    fail('web-statements output missing JINX_WEB_PROGRAM');
}

echo "PASS: complete native ./jinx verification suite" . PHP_EOL;
