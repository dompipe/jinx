<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;
use jinx\pasm\PASM;

$sourcePath = dirname(__DIR__) . '/fixtures/strlen.php';

$jinx = PhpToJinxLowerer::lowerFile($sourcePath);

$expectedJinx = implode(PHP_EOL, [
    'assign s string oracle',
    'return builtin strlen local s',
    '',
]);

if ($jinx !== $expectedJinx) {
    fwrite(STDERR, "FAIL: generated .jinx mismatch\n");
    fwrite(STDERR, "Expected:\n{$expectedJinx}\n");
    fwrite(STDERR, "Actual:\n{$jinx}\n");
    exit(1);
}

$result = JinxToPasmLowerer::run($jinx);

if ($result !== 6) {
    fwrite(STDERR, "FAIL: expected result 6, got " . var_export($result, true) . PHP_EOL);
    if (PASM::$fault !== null) {
        fwrite(STDERR, "PASM fault: " . PASM::$fault . PHP_EOL);
    }
    exit(1);
}

$expectedChain = [
    'MOV',
    'MOV',
    'MOV',
    'JINX_BUILTIN',
    'RET',
    'END',
];

if (PASM::$chain !== $expectedChain) {
    fwrite(STDERR, "FAIL: PASM chain mismatch\n");
    fwrite(STDERR, json_encode(PASM::$chain) . PHP_EOL);
    exit(1);
}

echo "PASS: PHP source builtin strlen lowers to .jinx, chained PASM, and returns 6\n";
