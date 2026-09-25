<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';

use jinx\lowering\JinxToPasmLowerer;
use jinx\pasm\PASM;

$path = dirname(__DIR__) . '/fixtures/simple-add.jinx';

$result = JinxToPasmLowerer::runFile($path);

if ($result !== 5) {
    fwrite(STDERR, "FAIL: expected 5, got " . var_export($result, true) . PHP_EOL);
    exit(1);
}

$expected = [
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'ADD',
    'RET',
    'END',
];

if (PASM::$chain !== $expected) {
    fwrite(STDERR, "FAIL: PASM chain mismatch\n");
    fwrite(STDERR, json_encode(PASM::$chain) . PHP_EOL);
    exit(1);
}

echo "PASS: .jinx file lowers to chained Oracle-style PASM and returns 5\n";
