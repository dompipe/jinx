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
    ['is_null', ['null'], 'bool:true'],
    ['is_bool', ['b:true'], 'bool:true'],
    ['is_int', ['i:42'], 'bool:true'],
    ['is_integer', ['i:42'], 'bool:true'],
    ['is_float', ['f:4.25'], 'bool:true'],
    ['is_double', ['f:4.25'], 'bool:true'],
    ['is_string', ['s:jinx'], 'bool:true'],
    ['is_array', ['a:3'], 'bool:true'],
    ['is_scalar', ['s:jinx'], 'bool:true'],
    ['is_numeric', ['s:42.5'], 'bool:true'],
    ['is_numeric', ['s:forty-two'], 'bool:false'],
    ['boolval', ['s:jinx'], 'bool:true'],
    ['boolval', ['null'], 'bool:false'],
    ['intval', ['s:42'], 'int:42'],
    ['intval', ['f:4.75'], 'int:4'],
    ['floatval', ['s:4.25'], 'float:4.25'],
    ['strval', ['i:42'], 'string:42'],
    ['strval', ['b:true'], 'string:1'],
    ['strval', ['null'], 'string:'],
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

echo 'PASS: native Oracle ASM scalar-core builtins execute exact JinxValue handlers' . PHP_EOL;
