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

function run(string $cmd, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($cmd . ' 2>&1', $out, $status);
    $code = $status;

    return implode(PHP_EOL, $out) . (count($out) ? PHP_EOL : '');
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

$nativeSource = (string) file_get_contents($root . '/native/jinx_cli.c');

if (!str_contains($nativeSource, 'ends_with(argv[1], ".php")') ||
    !str_contains($nativeSource, 'execvp("php", php_argv)')) {
    fail('native ./jinx source does not route .php script paths through PHP');
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-oracle-program-compiler.php')
), $code);

if ($code !== 0) {
    fail("./jinx PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: OracleProgramCompiler interprets PHP')) {
    fail("./jinx PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-zend-arbitrary-code-oracle.php')
), $code);

if ($code !== 0) {
    fail("./jinx arbitrary Zend PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle records arbitrary Zend-shaped PHP constructs')) {
    fail("./jinx arbitrary Zend PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-zend-runtime-ops-oracle.php')
), $code);

if ($code !== 0) {
    fail("./jinx Zend runtime ops PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle records Zend runtime body ops')) {
    fail("./jinx Zend runtime ops PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-zend-declaration-metadata-oracle.php')
), $code);

if ($code !== 0) {
    fail("./jinx Zend declaration metadata PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle records Zend declaration metadata')) {
    fail("./jinx Zend declaration metadata PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-oracle-straightline-execution.php')
), $code);

if ($code !== 0) {
    fail("./jinx Oracle straight-line execution PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle executes straight-line PHP subset')) {
    fail("./jinx Oracle straight-line execution PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-oracle-conditional-execution.php')
), $code);

if ($code !== 0) {
    fail("./jinx Oracle conditional execution PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle executes conditional PHP subset')) {
    fail("./jinx Oracle conditional execution PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-oracle-loop-execution.php')
), $code);

if ($code !== 0) {
    fail("./jinx Oracle loop execution PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle executes loop PHP subset')) {
    fail("./jinx Oracle loop execution PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-oracle-array-execution.php')
), $code);

if ($code !== 0) {
    fail("./jinx Oracle array execution PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle executes array PHP subset')) {
    fail("./jinx Oracle array execution PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s %s',
    $jinxCommand,
    escapeshellarg('scripts/test-oracle-execution-families.php')
), $code);

if ($code !== 0) {
    fail("./jinx Oracle execution families PHP script path failed:\n{$out}");
}

if (!str_contains($out, 'PASS: Oracle execution families expose')) {
    fail("./jinx Oracle execution families PHP script path did not run expected test:\n{$out}");
}

$out = run(sprintf(
    '%s web-plan %s',
    $jinxCommand,
    escapeshellarg($root . '/fixtures/simple-web-api-validated.php')
), $code);

if ($code !== 0) {
    fail("web-plan failed:\n{$out}");
}

if (!str_contains($out, 'WEB_IF_MISSING_ARRAY_KEY')) {
    fail("web-plan did not contain WEB_IF_MISSING_ARRAY_KEY:\n{$out}");
}

$outFile = $root . '/build/web-compiled/bin-jinx-test.compiled.php';

$out = run(sprintf(
    '%s web-compile %s %s',
    $jinxCommand,
    escapeshellarg($root . '/fixtures/simple-web-api-validated.php'),
    escapeshellarg($outFile)
), $code);

if ($code !== 0) {
    fail("web-compile failed:\n{$out}");
}

if (!is_file($outFile)) {
    fail('web-compile did not write output');
}

$outJson = $root . '/build/web-statements/bin-jinx-test.web.json';

$out = run(sprintf(
    '%s web-statements %s %s',
    $jinxCommand,
    escapeshellarg($root . '/fixtures/oracle-post-curl-dynamic.php'),
    escapeshellarg($outJson)
), $code);

if ($code !== 0) {
    fail("web-statements failed:\n{$out}");
}

if (!is_file($outJson)) {
    fail('web-statements did not write output');
}

$json = (string) file_get_contents($outJson);

if (!str_contains($json, 'JINX_WEB_PROGRAM')) {
    fail('web-statements output missing JINX_WEB_PROGRAM');
}

echo "PASS: ./jinx supports PHP script paths, arbitrary Zend records, Zend runtime ops, Zend declaration metadata, Oracle straight-line execution, Oracle conditional execution, Oracle loop execution, Oracle array execution, Oracle execution families, web-plan, web-compile, and web-statements\n";
