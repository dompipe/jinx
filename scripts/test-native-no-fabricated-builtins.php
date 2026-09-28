<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function runCommand(string $command, ?int &$status = null): string
{
    $out = [];
    exec($command . ' 2>&1', $out, $code);
    $status = $code;
    return implode(PHP_EOL, $out);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fwrite(STDERR, "FAIL: native ./jinx is missing; run scripts/build-native-jinx.sh first\n");
    exit(1);
}

$exact = runCommand(
    escapeshellarg($jinx) . ' oracle-call strlen ' . escapeshellarg('s:oracle'),
    $code
);
if ($code !== 0 || trim($exact) !== 'int:6') {
    fwrite(STDERR, "FAIL: exact native strlen did not execute: {$exact}\n");
    exit(1);
}

$constant = runCommand(
    escapeshellarg($jinx) . ' oracle-call constant ' . escapeshellarg('s:PHP_VERSION_ID'),
    $code
);
if ($code !== 0 || trim($constant) !== 'int:' . PHP_VERSION_ID) {
    fwrite(STDERR, "FAIL: metadata-backed constant() did not match PHP: {$constant}\n");
    exit(1);
}

$classExists = runCommand(
    escapeshellarg($jinx) . ' oracle-call class_exists ' . escapeshellarg('s:stdClass'),
    $code
);
if ($code !== 0 || trim($classExists) !== 'bool:true') {
    fwrite(STDERR, "FAIL: metadata-backed class_exists() did not match PHP: {$classExists}\n");
    exit(1);
}

$md5 = runCommand(
    escapeshellarg($jinx) . ' oracle-call md5 ' . escapeshellarg('s:oracle'),
    $code
);
if ($code !== 0 || trim($md5) !== 'string:' . md5('oracle')) {
    fwrite(STDERR, "FAIL: newly promoted native md5 did not match PHP: {$md5}\n");
    exit(1);
}

$ledger = json_decode(
    (string) file_get_contents($root . '/spec/native-oracle-wiring.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$manifest = json_decode(
    (string) file_get_contents($root . '/spec/php-functions.from-runtime.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$metadata = [];
foreach ($manifest['functions'] as $row) {
    $metadata[strtolower((string) $row['name'])] = $row;
}

$unsupportedName = null;
$unsupportedArgs = [];
foreach ($ledger['routes'] as $name => $route) {
    if ($route !== 'intentional-native-fault') continue;
    $row = $metadata[$name] ?? null;
    if (!is_array($row) || ($row['kind'] ?? null) !== 'builtin') continue;

    $required = (int) ($row['arity']['required'] ?? 0);
    $unsupportedName = (string) $row['name'];
    $unsupportedArgs = array_fill(0, $required, 's:oracle');
    break;
}

if ($unsupportedName === null) {
    fwrite(STDERR, "FAIL: could not select a reviewed unsupported builtin fixture\n");
    exit(1);
}

$command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($unsupportedName);
foreach ($unsupportedArgs as $arg) {
    $command .= ' ' . escapeshellarg($arg);
}
$output = runCommand($command, $code);

if ($code === 0 || !str_contains($output, 'null/fault: ' . $unsupportedName)) {
    fwrite(
        STDERR,
        "FAIL: reviewed unsupported {$unsupportedName} returned a fabricated native value: {$output}\n"
    );
    exit(1);
}

echo "PASS: promoted native builtins execute exactly while reviewed unsupported builtins still fault\n";
