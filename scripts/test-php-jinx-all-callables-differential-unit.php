<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$runnerPath = $root . '/scripts/test-php-jinx-all-callables-differential.php';
$suitePath = $root . '/scripts/test-jinx-native-suite.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($runnerPath)) {
    fail('all-callables differential runner is missing');
}
if (!is_file($suitePath)) {
    fail('native suite is missing');
}

$runner = (string) file_get_contents($runnerPath);
$suite = (string) file_get_contents($suitePath);

$requiredRunnerMarkers = [
    "require_once dirname(__DIR__) . '/runtime/OracleMergedExecutionFamilies.php'",
    'OracleMergedExecutionFamilies::all()',
    'function safe_callable_cases(): array',
    "'strlen' =>",
    "'strtoupper' =>",
    "'substr' =>",
    "'array_sum' =>",
    "'json_encode' =>",
    "'base64_encode' =>",
    "'md5' =>",
    "'sha1' =>",
    "'str_replace' =>",
    '$declaredBuiltins',
    '$checked',
    '$skipped',
    'exit mismatch',
    'stdout mismatch',
    'PASS: PHP vs JINX all-callables differential checked',
];

foreach ($requiredRunnerMarkers as $marker) {
    if (!str_contains($runner, $marker)) {
        fail('all-callables differential runner missing marker: ' . $marker);
    }
}

if (!str_contains($suite, "'php vs jinx all-callables differential'")) {
    fail('native suite missing all-callables differential label');
}
if (!str_contains($suite, 'scripts/test-php-jinx-all-callables-differential.php')) {
    fail('native suite missing all-callables differential path');
}
if (!str_contains($suite, 'PASS: PHP vs JINX all-callables differential checked')) {
    fail('native suite missing all-callables differential PASS marker');
}

$caseCount = preg_match_all('/^\s*\'[^\']+\'\s*=>/m', $runner, $matches);
if ($caseCount < 20) {
    fail('expected at least 20 safe callable cases, found ' . $caseCount);
}

echo 'PASS: PHP vs JINX all-callables differential unit validates ledger collection, safe cases, parity checks, skipped coverage, and native-suite wiring' . PHP_EOL;
