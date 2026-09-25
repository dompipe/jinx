<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';

use jinx\lowering\JinxToPasmLowerer;
use jinx\pasm\PASM;

$path = dirname(__DIR__) . '/fixtures/strlen.jinx';

$result = JinxToPasmLowerer::runFile($path);

if ($result !== 6) {
    fwrite(STDERR, "FAIL: expected 6, got " . var_export($result, true) . PHP_EOL);
    if (PASM::$fault !== null) {
        fwrite(STDERR, "PASM fault: " . PASM::$fault . PHP_EOL);
    }
    exit(1);
}

$expected = [
    'MOV',
    'MOV',
    'MOV',
    'JINX_BUILTIN',
    'RET',
    'END',
];

if (PASM::$chain !== $expected) {
    fwrite(STDERR, "FAIL: PASM chain mismatch\n");
    fwrite(STDERR, json_encode(PASM::$chain) . PHP_EOL);
    exit(1);
}

echo "PASS: .jinx builtin strlen lowers to chained PASM and returns 6\n";
