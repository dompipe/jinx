<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;
use jinx\pasm\PASM;

$sourcePath = dirname(__DIR__) . '/fixtures/simple-add.php';

$jinx = PhpToJinxLowerer::lowerFile($sourcePath);

$expectedJinx = implode(PHP_EOL, [
    'assign x int 2',
    'assign y int 3',
    'return add local x local y',
    '',
]);

if ($jinx !== $expectedJinx) {
    fwrite(STDERR, "FAIL: generated .jinx mismatch\n");
    fwrite(STDERR, "Expected:\n{$expectedJinx}\n");
    fwrite(STDERR, "Actual:\n{$jinx}\n");
    exit(1);
}

$result = JinxToPasmLowerer::run($jinx);

if ($result !== 5) {
    fwrite(STDERR, "FAIL: expected result 5, got " . var_export($result, true) . PHP_EOL);
    exit(1);
}

$expectedChain = [
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

if (PASM::$chain !== $expectedChain) {
    fwrite(STDERR, "FAIL: PASM chain mismatch\n");
    fwrite(STDERR, json_encode(PASM::$chain) . PHP_EOL);
    exit(1);
}

echo "PASS: PHP source lowers to .jinx, then chained PASM, and returns 5\n";
