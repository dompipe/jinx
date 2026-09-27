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
    ['strtolower', ['s:JiNx'], 'string:jinx'],
    ['strtoupper', ['s:JiNx'], 'string:JINX'],
    ['lcfirst', ['s:JINX'], 'string:jINX'],
    ['ucfirst', ['s:jinx'], 'string:Jinx'],
    ['strrev', ['s:oracle'], 'string:elcaro'],
    ['trim', ["s:\t jinx  "], 'string:jinx'],
    ['ltrim', ["s:\t jinx  x"], 'string:jinx  x'],
    ['rtrim', ["s:x  jinx \t"], 'string:x  jinx'],
    ['chop', ["s:x  jinx \t"], 'string:x  jinx'],
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

echo 'PASS: native Oracle ASM string byte transforms execute exact strlen-era string handlers' . PHP_EOL;
