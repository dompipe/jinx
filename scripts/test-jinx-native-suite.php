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
    'string interpolation family' => ['scripts/test-oracle-string-interpolation-execution.php', 'PASS: Oracle executes PHP string interpolation'],
    'string interpolation facets' => ['scripts/test-oracle-string-interpolation-facets.php', 'PASS: Oracle string interpolation facets resolve every tested PHP form'],
    'coalesced string interpolation' => ['scripts/test-oracle-string-interpolation-coalesced.php', 'PASS: Coalesced Oracle compiles PHP string interpolation'],
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
    'math builtin batch two families' => ['scripts/test-oracle-math-builtin-two-execution.php', 'PASS: Oracle executes math builtin batch two PHP families'],
    'data builtin families' => ['scripts/test-oracle-data-builtin-execution.php', 'PASS: Oracle executes data builtin PHP families'],
    'data builtin batch two families' => ['scripts/test-oracle-data-builtin-two-execution.php', 'PASS: Oracle executes data builtin batch two PHP families'],
    'array set/key builtin families' => ['scripts/test-oracle-array-set-builtin-execution.php', 'PASS: Oracle executes array set/key builtin PHP families'],
    'filesystem builtin families' => ['scripts/test-oracle-filesystem-builtin-execution.php', 'PASS: Oracle executes filesystem/stat builtin PHP families'],
    'generated 275 family group' => ['scripts/test-oracle-generated-family-group.php', 'PASS: Oracle generated 275 family group exposes'],
    'generated 275 builtin families' => ['scripts/test-oracle-generated-175-builtin-execution.php', 'PASS: Oracle executes generated pure builtin PHP families'],
    'text builtin families' => ['scripts/test-oracle-text-builtin-execution.php', 'PASS: Oracle executes text builtin PHP families'],
    'text builtin batch two families' => ['scripts/test-oracle-text-builtin-two-execution.php', 'PASS: Oracle executes text builtin batch two PHP families'],
    'regex string builtin families' => ['scripts/test-oracle-regex-string-builtin-execution.php', 'PASS: Oracle executes regex/string builtin PHP families'],
    'array mutation builtin families' => ['scripts/test-oracle-array-mutation-builtin-execution.php', 'PASS: Oracle executes array mutation builtin PHP families'],
    'security network builtin families' => ['scripts/test-oracle-security-network-builtin-execution.php', 'PASS: Oracle executes security/network builtin PHP families'],
    'runtime info builtin families' => ['scripts/test-oracle-runtime-info-builtin-execution.php', 'PASS: Oracle executes runtime-info builtin PHP families'],
    'date time builtin families' => ['scripts/test-oracle-date-time-builtin-execution.php', 'PASS: Oracle executes date/time builtin PHP families'],
    'introspection builtin families' => ['scripts/test-oracle-introspection-builtin-execution.php', 'PASS: Oracle executes introspection builtin PHP families'],
    'program compiler' => ['scripts/test-oracle-program-compiler.php', 'PASS: OracleProgramCompiler interprets PHP'],
    'web back-page bridge' => ['scripts/test-web-back-page-bridge.php', 'PASS: WebBackPageBridge handles request/response envelopes'],
    'web window index' => ['scripts/test-web-window-index.php', 'PASS: WebWindowIndex emits JINX stream runtime, no-JS registrar DOM frames, and no-JS live islands'],
    'no-JS island demo page' => ['scripts/demo-no-js-islands.php', 'JINX no-JS live islands'],
    'benchmark command help' => ['scripts/benchmark-oracle-families.php --help', 'Oracle family benchmark'],
    'worker benchmark help' => ['scripts/benchmark-oracle-worker-hot.php --help', 'Oracle worker hot benchmark'],
    'web back-page hot benchmark help' => ['scripts/benchmark-web-back-page-hot.php --help', 'Web back-page hot benchmark'],
    '89x resident frame benchmark help' => ['scripts/benchmark-web-89x-resident-frames.php --help', '89x resident high-frame benchmark'],
    'web request worker benchmark help' => ['scripts/benchmark-web-request-worker.php --help', 'JINX warmed web request worker benchmark'],
    'live web request benchmark help' => ['scripts/benchmark-live-web-requests.php --help', 'Live web request benchmark'],
    'live keep-alive benchmark help' => ['scripts/benchmark-live-web-keepalive.php --help', 'Live web keep-alive benchmark'],
    'jinx live worker help' => ['scripts/serve-jinx-web-worker.php --help', 'JINX live web worker'],
    'family facet audit' => ['scripts/test-oracle-family-facet-audit.php', 'PASS: Oracle family facet audit validates'],
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
