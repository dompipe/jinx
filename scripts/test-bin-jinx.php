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

$tests = [
    'scripts/test-oracle-program-compiler.php' => 'PASS: OracleProgramCompiler interprets PHP',
    'scripts/test-zend-arbitrary-code-oracle.php' => 'PASS: Oracle records arbitrary Zend-shaped PHP constructs',
    'scripts/test-zend-runtime-ops-oracle.php' => 'PASS: Oracle records Zend runtime body ops',
    'scripts/test-zend-declaration-metadata-oracle.php' => 'PASS: Oracle records Zend declaration metadata',
    'scripts/test-oracle-straightline-execution.php' => 'PASS: Oracle executes straight-line PHP subset',
    'scripts/test-oracle-conditional-execution.php' => 'PASS: Oracle executes conditional PHP subset',
    'scripts/test-oracle-loop-execution.php' => 'PASS: Oracle executes loop PHP subset',
    'scripts/test-oracle-array-execution.php' => 'PASS: Oracle executes array PHP subset',
    'scripts/test-oracle-function-execution.php' => 'PASS: Oracle executes function PHP subset',
    'scripts/test-native-math-core-oracle-asm.php' => 'PASS: native Oracle ASM math-core and cosine handlers match PHP',
    'scripts/test-native-pure-core-oracle-asm.php' => 'PASS: native Oracle ASM pure scalar/string core matches PHP',
    'scripts/test-native-zend-array-core-oracle-asm.php' => 'PASS: native Oracle ASM Zend-array core executes carried PHP-array semantics',
    'scripts/test-native-string-transform-oracle-asm.php' => 'PASS: native Oracle ASM string byte transforms, searches, comparisons, and counts match PHP',
    'scripts/test-oracle-execution-families.php' => 'PASS: Oracle execution families expose',
];

foreach ($tests as $script => $expected) {
    $out = run(sprintf('%s %s', $jinxCommand, escapeshellarg($script)), $code);

    if ($code !== 0) {
        fail("./jinx {$script} failed:\n{$out}");
    }

    if (!str_contains($out, $expected)) {
        fail("./jinx {$script} did not run expected test:\n{$out}");
    }
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

echo "PASS: ./jinx supports PHP script paths, arbitrary Zend records, Zend runtime ops, Zend declaration metadata, Oracle straight-line execution, Oracle conditional execution, Oracle loop execution, Oracle array execution, Oracle function execution, native Oracle ASM PHP parity checks, Oracle execution families, web-plan, web-compile, and web-statements\n";
