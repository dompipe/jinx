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

foreach ([
    ['md5', 's:oracle'],
    ['constant', 's:PHP_VERSION'],
    ['class_exists', 's:stdClass'],
] as [$name, $arg]) {
    $output = runCommand(
        escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($name) . ' ' . escapeshellarg($arg),
        $code
    );

    if ($code === 0 || !str_contains($output, 'null/fault: ' . $name)) {
        fwrite(STDERR, "FAIL: {$name} still returned a fabricated native value: {$output}\n");
        exit(1);
    }
}

echo "PASS: unsupported native builtins fault instead of returning fabricated values\n";
