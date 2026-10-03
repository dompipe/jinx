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
    'switch family' => ['scripts/test-oracle-switch-execution.php', 'PASS: Oracle executes switch/case/default PHP subset'],
    'match family' => ['scripts/test-oracle-match-execution.php', 'PASS: Oracle executes match-expression PHP subset'],
    'exceptions family' => ['scripts/test-oracle-exception-execution.php', 'PASS: Oracle executes try/throw/catch/finally PHP subset'],
    'loops family' => ['scripts/test-oracle-loop-execution.php', 'PASS: Oracle executes loop PHP subset'],
    'foreach family' => ['scripts/test-oracle-foreach-execution.php', 'PASS: Oracle executes foreach PHP subset'],
    'for-loop family' => ['scripts/test-oracle-for-execution.php', 'PASS: Oracle executes for-loop PHP subset'],
    'do-while family' => ['scripts/test-oracle-do-while-execution.php', 'PASS: Oracle executes do-while PHP subset'],
    'goto label family' => ['scripts/test-oracle-goto-execution.php', 'PASS: Oracle executes goto/label PHP subset'],
    'arrays family' => ['scripts/test-oracle-array-execution.php', 'PASS: Oracle executes array PHP subset'],
    'functions family' => ['scripts/test-oracle-function-execution.php', 'PASS: Oracle executes function PHP subset'],
    'global scope family' => ['scripts/test-oracle-global-scope-execution.php', 'PASS: Oracle executes global-scope PHP subset'],
    'static locals family' => ['scripts/test-oracle-static-local-execution.php', 'PASS: Oracle executes static-local PHP subset'],
    'closures family' => ['scripts/test-oracle-closure-execution.php', 'PASS: Oracle executes closure PHP subset'],
    'specialized assignment classifier' => ['scripts/test-oracle-specialized-assignment-classification.php', 'PASS: Oracle classifier preserves specialized assignment families'],
    'late differential classifier' => ['scripts/test-oracle-late-differential-classification.php', 'PASS: Oracle classifier preserves late differential statement shapes'],
    'decimal literal parity' => ['scripts/test-oracle-decimal-literal-execution.php', 'PASS: Straight-line Oracle decimal literals match PHP round behavior'],
    'request globals family' => ['scripts/test-oracle-request-globals-execution.php', 'PASS: Oracle executes request globals PHP subset'],
    'include require family' => ['scripts/test-oracle-include-require-execution.php', 'PASS: Oracle executes include/require PHP subset'],
    'exit die family' => ['scripts/test-oracle-exit-die-execution.php', 'PASS: Oracle executes exit/die PHP subset'],
    'object basics family' => ['scripts/test-oracle-object-basics-execution.php', 'PASS: Oracle executes object basics PHP subset'],
    'static method family' => ['scripts/test-oracle-static-method-execution.php', 'PASS: Oracle executes static-method PHP subset'],
    'static property family' => ['scripts/test-oracle-static-property-execution.php', 'PASS: Oracle executes static-property PHP subset'],
    'instanceof family' => ['scripts/test-oracle-instanceof-execution.php', 'PASS: Oracle executes instanceof PHP subset'],
    'object clone family' => ['scripts/test-oracle-clone-execution.php', 'PASS: Oracle executes object clone PHP subset'],
    'object inheritance family' => ['scripts/test-oracle-object-inheritance-execution.php', 'PASS: Oracle executes object inheritance PHP subset'],
    'late static binding family' => ['scripts/test-oracle-late-static-binding-execution.php', 'PASS: Oracle executes late static binding PHP subset'],
    'nullsafe object family' => ['scripts/test-oracle-nullsafe-object-execution.php', 'PASS: Oracle executes nullsafe object PHP subset'],
    'readonly property family' => ['scripts/test-oracle-readonly-property-execution.php', 'PASS: Oracle enforces readonly property PHP subset'],
    'magic method family' => ['scripts/test-oracle-magic-method-execution.php', 'PASS: Oracle executes magic method PHP subset'],
    'for append ternary family' => ['scripts/test-oracle-for-append-ternary-execution.php', 'PASS: Oracle executes for-loop array append ternary subset'],
    'comparison boolean family' => ['scripts/test-oracle-comparison-boolean-execution.php', 'PASS: Oracle executes comparison boolean ternary subset'],
    'second 100 remaining families' => ['scripts/test-oracle-second-batch-execution.php', 'PASS: Oracle executes remaining second-100-case families'],
    'third 100 new families' => ['scripts/test-oracle-third-batch-execution.php', 'PASS: Oracle executes third-batch nested-array and elseif families'],
    'fourth 100 new families' => ['scripts/test-oracle-fourth-batch-execution.php', 'PASS: Oracle executes fourth-batch numeric foreach and function families'],
    'fifth 100 new families' => ['scripts/test-oracle-fifth-batch-execution.php', 'PASS: Oracle executes fifth-batch ternary string foreach and function families'],
    'sixth 100 new families' => ['scripts/test-oracle-sixth-batch-execution.php', 'PASS: Oracle executes sixth-batch string foreach and function families'],
    'seventh 100 new families' => ['scripts/test-oracle-seventh-batch-execution.php', 'PASS: Oracle executes seventh-batch numeric foreach and function families'],
    'late 9th-final shared families' => ['scripts/test-oracle-late-batch-execution.php', 'PASS: Oracle executes late-batch foreach function and for reductions'],
    'typed direct function family' => ['scripts/test-oracle-function-typed-direct-execution.php', 'PASS: Oracle executes typed direct user function subset'],
    'function control-loop family' => ['scripts/test-oracle-function-control-loop-execution.php', 'PASS: Oracle executes function control-loop subset'],
    'promoted object family' => ['scripts/test-oracle-object-promoted-execution.php', 'PASS: Oracle executes promoted final-class object subset'],
    'generator family' => ['scripts/test-oracle-generator-execution.php', 'PASS: Oracle executes generator PHP subsets'],
    'native script routing' => ['scripts/test-native-jinx-script-routing.php', 'PASS: native ./jinx runs supported PHP fixtures through Oracle and refuses PHP fallback for unsupported scripts'],
    'zend declarations family' => ['scripts/test-oracle-zend-declaration-execution.php', 'PASS: Oracle executes Zend declaration/interface/trait/enum PHP subset'],
    'zend arbitrary family' => ['scripts/test-oracle-zend-arbitrary-execution.php', 'PASS: Oracle executes arbitrary Zend function/control PHP subset'],
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
    'generated 782 family group' => ['scripts/test-oracle-generated-family-group.php', 'PASS: Oracle generated 782 family IDs across 25 unique expression templates merged into the normal family set'],
    'generated 782 builtin families' => ['scripts/test-oracle-generated-175-builtin-execution.php', 'PASS: Oracle executes generated pure builtin PHP families'],
    'php vs jinx differential unit' => ['scripts/test-php-jinx-differential-unit.php', 'PASS: PHP vs JINX differential unit test validates runner cases, parity checks, and native-suite wiring'],
    'php vs jinx differential' => ['scripts/test-php-jinx-differential.php', 'PASS: PHP vs JINX differential runner matches PHP stdout/exit behavior'],
    'php vs jinx 1144 unit coverage audit' => ['scripts/test-php-jinx-1144-unit-coverage-audit.php', 'PASS: PHP vs JINX 1144 unit coverage audit validates'],
    'php vs jinx 100 unit cases' => ['scripts/test-php-jinx-100-unit-cases.php', 'PASS: PHP vs JINX 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx second 100 unit cases' => ['scripts/test-php-jinx-100-more-unit-cases.php', 'PASS: PHP vs JINX second 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx third 100 unit cases' => ['scripts/test-php-jinx-100-third-unit-cases.php', 'PASS: PHP vs JINX third 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx fourth 100 unit cases' => ['scripts/test-php-jinx-100-fourth-unit-cases.php', 'PASS: PHP vs JINX fourth 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx fifth 100 unit cases' => ['scripts/test-php-jinx-100-fifth-unit-cases.php', 'PASS: PHP vs JINX fifth 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx sixth 100 unit cases' => ['scripts/test-php-jinx-100-sixth-unit-cases.php', 'PASS: PHP vs JINX sixth 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx seventh 100 unit cases' => ['scripts/test-php-jinx-100-seventh-unit-cases.php', 'PASS: PHP vs JINX seventh 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx eighth 50 unit cases' => ['scripts/test-php-jinx-50-eighth-unit-cases.php', 'PASS: PHP vs JINX eighth 50 unit cases match PHP stdout/exit behavior'],
    'php vs jinx ninth 100 unit cases' => ['scripts/test-php-jinx-100-ninth-unit-cases.php', 'PASS: PHP vs JINX ninth 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx tenth 100 unit cases' => ['scripts/test-php-jinx-100-tenth-unit-cases.php', 'PASS: PHP vs JINX tenth 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx eleventh 50 unit cases' => ['scripts/test-php-jinx-50-eleventh-unit-cases.php', 'PASS: PHP vs JINX eleventh 50 unit cases match PHP stdout/exit behavior'],
    'php vs jinx twelfth 100 unit cases' => ['scripts/test-php-jinx-100-twelfth-unit-cases.php', 'PASS: PHP vs JINX twelfth 100 unit cases match PHP stdout/exit behavior'],
    'php vs jinx final 44 unit cases' => ['scripts/test-php-jinx-44-final-unit-cases.php', 'PASS: PHP vs JINX final 44 unit cases match PHP stdout/exit behavior'],
    'php vs jinx all-callables differential unit' => ['scripts/test-php-jinx-all-callables-differential-unit.php', 'PASS: PHP vs JINX all-callables differential unit validates ledger collection, safe cases, parity checks, skipped coverage, and native-suite wiring'],
    'php vs jinx all-callables differential' => ['scripts/test-php-jinx-all-callables-differential.php', 'PASS: PHP vs JINX all-callables differential checked'],
    'text builtin families' => ['scripts/test-oracle-text-builtin-execution.php', 'PASS: Oracle executes text builtin PHP families'],
    'text builtin batch two families' => ['scripts/test-oracle-text-builtin-two-execution.php', 'PASS: Oracle executes text builtin batch two PHP families'],
    'native string transform oracle asm' => ['scripts/test-native-string-transform-oracle-asm.php', 'PASS: native Oracle ASM string byte transforms, searches, comparisons, and counts match PHP for covered scalar cases'],
    'native scalar core oracle asm' => ['scripts/test-native-scalar-core-oracle-asm.php', 'PASS: native Oracle ASM scalar-core builtins execute'],
    'native return contracts' => ['scripts/test-native-return-contracts.php', 'PASS: native Oracle return contracts reject invalid argument paths, preserve legitimate nulls, and classify stream resources correctly'],
    'native math core oracle asm' => ['scripts/test-native-math-core-oracle-asm.php', 'PASS: native Oracle ASM math-core builtins execute'],
    'first 100 procedural oracle asm' => ['scripts/test-native-procedural-needed-100-oracle-asm.php', 'PASS: first 100 needed procedural Oracle ASM targets execute without placeholders'],
    'regex string builtin families' => ['scripts/test-oracle-regex-string-builtin-execution.php', 'PASS: Oracle executes regex/string builtin PHP families'],
    'array mutation builtin families' => ['scripts/test-oracle-array-mutation-builtin-execution.php', 'PASS: Oracle executes array mutation builtin PHP families'],
    'security network builtin families' => ['scripts/test-oracle-security-network-builtin-execution.php', 'PASS: Oracle executes security/network builtin PHP families'],
    'runtime info builtin families' => ['scripts/test-oracle-runtime-info-builtin-execution.php', 'PASS: Oracle executes runtime-info builtin PHP families'],
    'date time builtin families' => ['scripts/test-oracle-date-time-builtin-execution.php', 'PASS: Oracle executes date/time builtin PHP families'],
    'introspection builtin families' => ['scripts/test-oracle-introspection-builtin-execution.php', 'PASS: Oracle executes introspection builtin PHP families'],
    'program compiler' => ['scripts/test-oracle-program-compiler.php', 'PASS: OracleProgramCompiler interprets PHP'],
    'modern php oracle recording' => ['scripts/test-oracle-modern-php-recording.php', 'PASS: Oracle modern PHP recorder distinguishes high-risk PHP 8+ semantic constructs without claiming execution'],
    'web back-page bridge' => ['scripts/test-web-back-page-bridge.php', 'PASS: WebBackPageBridge handles request/response envelopes'],
    'web window index' => ['scripts/test-web-window-index.php', 'PASS: WebWindowIndex emits JINX stream runtime, no-JS registrar DOM frames, and no-JS live islands'],
    'oracle jinx island server' => ['scripts/test-oracle-jinx-island-server-execution.php', 'PASS: Oracle JINX island server executes route table, back-page API state, iframe island render, and EventSource invalidation'],
    'no-JS island demo page' => ['scripts/demo-no-js-islands.php', 'Back-page/API backed'],
    'no-JS island demo server helper' => ['scripts/serve-no-js-islands-demo.php --check', 'PASS: no-JS island demo server command is available'],
    'benchmark command help' => ['scripts/benchmark-oracle-families.php --help', 'Oracle family benchmark'],
    'worker benchmark help' => ['scripts/benchmark-oracle-worker-hot.php --help', 'Oracle worker hot benchmark'],
    'separated benchmark help' => ['scripts/benchmark-oracle-separated.php --help', 'Separated JINX/PHP Oracle benchmark'],
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
