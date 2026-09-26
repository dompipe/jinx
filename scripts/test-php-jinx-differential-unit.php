<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function assert_contains_text(string $haystack, string $needle, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        fail("{$label}: missing {$needle}");
    }
}

$root = dirname(__DIR__);
$runnerPath = $root . '/scripts/test-php-jinx-differential.php';
$suitePath = $root . '/scripts/test-jinx-native-suite.php';

if (!is_file($runnerPath)) {
    fail('differential runner script is missing');
}
if (!is_file($suitePath)) {
    fail('native suite script is missing');
}

$runner = (string) file_get_contents($runnerPath);
$suite = (string) file_get_contents($suitePath);

foreach ([
    '$jinx = $root . \'/jinx\';',
    '$php = PHP_BINARY;',
    'function run_process(array $command): array',
    'normalize_stderr',
    'straight-line-echo',
    'string-builtins',
    'array-builtins',
    'json-base64-hash',
    'conditionals-loops',
    'function-call',
    'object-basics',
    'PASS: PHP vs JINX differential runner matches PHP stdout/exit behavior',
] as $marker) {
    assert_contains_text($runner, $marker, 'runner source marker');
}

foreach ([
    'scripts/test-php-jinx-differential.php',
    'PASS: PHP vs JINX differential runner matches PHP stdout/exit behavior',
] as $marker) {
    assert_contains_text($suite, $marker, 'native suite wiring marker');
}

$caseCount = substr_count($runner, "PHP,\n");
if ($caseCount < 7) {
    fail('expected at least 7 deterministic differential cases, found ' . $caseCount);
}

if (!preg_match('/\$jinxResult\[\'stdout\'\]\s*!==\s*\$phpResult\[\'stdout\'\]/', $runner)) {
    fail('runner no longer compares JINX stdout against PHP stdout');
}
if (!preg_match('/\$jinxResult\[\'exit\'\]\s*!==\s*\$phpResult\[\'exit\'\]/', $runner)) {
    fail('runner no longer compares JINX exit code against PHP exit code');
}

echo 'PASS: PHP vs JINX differential unit test validates runner cases, parity checks, and native-suite wiring' . PHP_EOL;
