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
    ['str_repeat', ['x', -1], ['s:x', 'i:-1']],
    ['chunk_split', ['x', 0], ['s:x', 'i:0']],
    ['strncmp', ['a', 'b', -1], ['s:a', 's:b', 'i:-1']],
    ['strncasecmp', ['a', 'b', -1], ['s:a', 's:b', 'i:-1']],
    ['substr_count', ['abc', ''], ['s:abc', 's:']],
    ['substr_count', ['abc', 'a', 4], ['s:abc', 's:a', 'i:4']],
    ['substr_count', ['abc', 'a', 1, 5], ['s:abc', 's:a', 'i:1', 'i:5']],
    ['substr_count', ['abc', 'a', 0, -4], ['s:abc', 's:a', 'i:0', 'i:-4']],
    ['intdiv', [1, 0], ['i:1', 'i:0']],
    ['intdiv', [PHP_INT_MIN, -1], ['i:' . PHP_INT_MIN, 'i:-1']],
];

foreach ($cases as [$function, $phpArgs, $typedArgs]) {
    $phpRejected = false;

    try {
        $function(...$phpArgs);
    } catch (Throwable) {
        $phpRejected = true;
    }

    if (!$phpRejected) {
        fail("PHP did not reject expected exceptional case for {$function}");
    }

    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($function);
    foreach ($typedArgs as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $text = implode(PHP_EOL, $output);

    if ($code === 0 || !str_contains($text, 'null/fault: ' . $function)) {
        fail("JINX did not fault where PHP rejects {$function}: {$text}");
    }
}

echo 'PASS: native Oracle ASM exceptional scalar/string paths reject where PHP rejects' . PHP_EOL;
