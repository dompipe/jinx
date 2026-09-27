<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

$cases = [
    ['fmod', ['f:5.5', 'f:2'], 'float:1.5'],
    ['intdiv', ['i:7', 'i:2'], 'int:3'],
    ['deg2rad', ['f:180'], 'float:3.14159'],
    ['rad2deg', ['f:3.141592653589793'], 'float:180'],
    ['pi', [], 'float:3.14159'],
    ['hypot', ['f:3', 'f:4'], 'float:5'],
    ['is_finite', ['f:42'], 'bool:true'],
    ['is_infinite', ['f:42'], 'bool:false'],
    ['is_nan', ['f:42'], 'bool:false'],
];

foreach ($cases as [$function, $args, $expected]) {
    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($function);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $text = rtrim(implode(PHP_EOL, $output), "\r\n");

    if ($code !== 0) {
        fail("oracle-call {$function} failed: {$text}");
    }

    if ($text !== $expected) {
        fail("oracle-call {$function} expected {$expected}, got {$text}");
    }
}

echo 'PASS: native Oracle ASM math-core builtins execute exact JinxValue handlers' . PHP_EOL;
