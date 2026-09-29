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

if (is_file($root . '/bin/jinx')) {
    fail('legacy bin/jinx frontend must not exist; repository-root ./jinx is the only Jinx CLI');
}


$out = run($jinxCommand . ' native-benchmark-id', $code);
if ($code !== 0 || trim($out) !== 'native-root-jinx') {
    fail("repository-root ./jinx did not identify as the compiled native benchmark executable:\n{$out}");
}

$phpGcStatus = gc_status();
$phpGcFields = [
    'running' => 'bool',
    'protected' => 'bool',
    'full' => 'bool',
    'runs' => 'int',
    'collected' => 'int',
    'threshold' => 'int',
    'buffer_size' => 'int',
    'roots' => 'int',
    'application_time' => 'float',
    'collector_time' => 'float',
    'destructor_time' => 'float',
    'free_time' => 'float',
];
foreach ($phpGcFields as $field => $type) {
    if (!array_key_exists($field, $phpGcStatus) ||
        get_debug_type($phpGcStatus[$field]) !== $type) {
        fail("PHP 8.4 gc_status contract missing {$field}:{$type}");
    }
}

$out = run($jinxCommand . ' oracle-gc-smoke', $code);
if ($code !== 0 ||
    !str_contains(
        $out,
        'PASS: native GC enable/disable/collect/cache/status state transitions match PHP 8.4-shaped Jinx runtime semantics'
    )) {
    fail("native GC status/state smoke failed:\n{$out}");
}

$phpPack = pack('nvc*', 0x1234, 0x5678, 65, 66);
if (bin2hex($phpPack) !== '123478564142') {
    fail('PHP pack contract did not match expected nvc* fixture');
}
$phpUnpack = unpack('Cchar/nint', "\x04\x00\xa0");
if (!is_array($phpUnpack) ||
    ($phpUnpack['char'] ?? null) !== 4 ||
    ($phpUnpack['int'] ?? null) !== 160) {
    fail('PHP unpack contract did not match expected Cchar/nint fixture');
}

$out = run($jinxCommand . ' oracle-pack-smoke', $code);
if ($code !== 0 ||
    !str_contains(
        $out,
        'PASS: native pack/unpack match PHP binary layout and named unpack values'
    )) {
    fail("native pack/unpack smoke failed:\n{$out}");
}

$phpScanf = sscanf('10 20.5 hello X', '%d %f %s %c');
if (!is_array($phpScanf) ||
    count($phpScanf) !== 4 ||
    $phpScanf[0] !== 10 ||
    abs((float)$phpScanf[1] - 20.5) > 1e-12 ||
    $phpScanf[2] !== 'hello' ||
    $phpScanf[3] !== 'X') {
    fail('PHP sscanf contract did not match deterministic scalar fixture');
}

$out = run($jinxCommand . ' oracle-scanf-smoke', $code);
if ($code !== 0 ||
    !str_contains(
        $out,
        'PASS: native sscanf array form matches PHP scalar scan values'
    )) {
    fail("native sscanf smoke failed:\n{$out}");
}

$out = run($jinxCommand . ' builtin-id sqrt', $code);
if ($code !== 0 ||
    !str_contains($out, 'Function: sqrt') ||
    !str_contains($out, 'Encoded bytes: 1')) {
    fail("hot builtin sqrt did not receive a one-byte compact ID:\n{$out}");
}

$out = run($jinxCommand . ' builtin-id uniqid', $code);
if ($code !== 0 ||
    !str_contains($out, 'Function: uniqid') ||
    !str_contains($out, 'Encoded bytes: 2')) {
    fail("non-hot builtin uniqid did not receive a two-byte compact ID:\n{$out}");
}

$nativeSource = (string) file_get_contents($root . '/native/jinx_cli.c');

if (str_contains($nativeSource, 'command_php_frontend') ||
    str_contains($nativeSource, '"bin/jinx"')) {
    fail('native ./jinx must not contain a bin/jinx PHP frontend fallback');
}

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
    'scripts/test-native-oracle-wiring.php' => 'PASS: native Oracle wiring inventory and intentional faults verified',
    'scripts/test-native-error-parity-oracle-asm.php' => 'PASS: native Oracle ASM exceptional scalar/string paths reject where PHP rejects',
    'scripts/test-native-no-fabricated-builtins.php' => 'PASS: promoted native builtins execute exactly while reviewed unsupported builtins still fault',
    'scripts/test-native-return-contracts.php' => 'PASS: native Oracle return contracts reject invalid argument paths, preserve legitimate nulls, and classify stream resources correctly',
    'scripts/test-native-math-core-oracle-asm.php' => 'PASS: native Oracle ASM math-core and cosine handlers match PHP',
    'scripts/test-native-scalar-core-oracle-asm.php' => 'PASS: native Oracle ASM scalar-core builtins match PHP for covered JinxValue semantics',
    'scripts/test-native-pure-core-oracle-asm.php' => 'PASS: native Oracle ASM pure scalar/string core matches PHP',
    'scripts/test-native-zend-array-core-oracle-asm.php' => 'PASS: native Oracle ASM Zend-array core matches PHP for covered carried-array semantics',
    'scripts/test-native-procedural-needed-100-oracle-asm.php' => 'PASS: first 100 needed procedural Oracle ASM targets execute without placeholders',
    'scripts/test-native-procedural-needed-200-oracle-asm.php' => 'PASS: second-wave procedural Oracle handlers preserve deterministic PHP return contracts',
    'scripts/test-native-password-hash.php' => 'PASS: native password_hash bcrypt output verifies in PHP and Jinx',
    'scripts/test-native-preg-match.php' => 'PASS: native PCRE2 preg_match, preg_match_all, preg_split, preg_replace, preg_filter, preg_grep, preg_last_error, and preg_last_error_msg core forms match PHP',
    'scripts/test-native-serialize.php' => 'PASS: native serialize/unserialize scalar, binary-string, mixed-array, and nested-array core matches PHP',
    'scripts/test-native-timezone-abbr-oracle.php' => 'PASS: native timezone_name_from_abbr matches PHP abbreviation resolution',
    'scripts/test-native-pfsockopen-oracle.php' => 'PASS: native pfsockopen matches bounded PHP socket behavior',
    'scripts/test-native-pcntl-oracle.php' => 'PASS: native PCNTL alarm, priority, CPU, and error helpers match PHP',
    'scripts/test-native-method-oracle-asm.php' => 'PASS: native Oracle method receiver proves 500 newly added callable routes',
    'scripts/test-native-zlib-oracle-asm.php' => 'PASS: native Oracle zlib helpers match PHP for covered one-shot semantics',
    'scripts/test-native-sodium-oracle.php' => 'PASS: native libsodium core functions match PHP sodium byte-for-byte',
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
    '%s bench-call abs 3 %s',
    $jinxCommand,
    escapeshellarg('i:-42')
), $code);

if ($code !== 0 ||
    !str_contains($out, 'Function: abs') ||
    !str_contains($out, 'Encoded bytes: 1') ||
    !str_contains($out, 'Per call ns:')) {
    fail("bench-call did not execute a compact-ID native implementation:\n{$out}");
}

$out = run(sprintf(
    '%s bench-method-call %s 3 %s %s',
    $jinxCommand,
    escapeshellarg('DateTime::format'),
    escapeshellarg('dt:2024-01-02 03:04:05'),
    escapeshellarg('s:Y-m-d')
), $code);

if ($code !== 0 ||
    !str_contains($out, 'Method: DateTime::format') ||
    !str_contains($out, 'Per call ns:')) {
    fail("bench-method-call did not execute the parity-proven native method:\n{$out}");
}

$out = run(
    'JINX_SKIP_BUILD=1 '
    . escapeshellarg(PHP_BINARY)
    . ' '
    . escapeshellarg($root . '/scripts/benchmark-native-implemented-functions.php')
    . ' 3 --limit=3',
    $code
);

if ($code !== 0 ||
    !str_contains($out, 'PHP vs native ./jinx implemented-function benchmark') ||
    !str_contains($out, 'Benchmarked implementations: 3')) {
    fail("implemented-function benchmark script did not complete its smoke run:\n{$out}");
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

echo "PASS: ./jinx supports PHP script paths, arbitrary Zend records, Zend runtime ops, Zend declaration metadata, Oracle straight-line execution, Oracle conditional execution, Oracle loop execution, Oracle array execution, Oracle function execution, native Oracle ASM PHP parity checks, named implementation benchmarks, Oracle execution families, web-plan, web-compile, and web-statements\n";
