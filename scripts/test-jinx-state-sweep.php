<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PASM.php';
require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;
use jinx\pasm\PASM;

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function assertSameValue(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail("{$label}: expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

function assertChain(array $expected, string $label): void
{
    if (PASM::$chain !== $expected) {
        fail("{$label}: PASM chain mismatch " . json_encode(PASM::$chain));
    }
}

function runPhpFixture(string $file, string $expectedJinx, mixed $expectedResult, string $label): void
{
    global $root;

    $jinx = PhpToJinxLowerer::lowerFile($root . '/fixtures/' . $file);
    assertSameValue($jinx, $expectedJinx, "{$label} generated .jinx");

    $result = JinxToPasmLowerer::run($jinx);
    assertSameValue($result, $expectedResult, "{$label} result");
}

function runJinxFixture(string $file, mixed $expectedResult, string $label): void
{
    global $root;

    $result = JinxToPasmLowerer::runFile($root . '/fixtures/' . $file);
    assertSameValue($result, $expectedResult, "{$label} result");
}

$binaryOpChain = ['MOV', 'MOV', 'MOV', 'MOV', 'MOV', 'MOV'];

// Direct PASM chain tests.
$result = PASM::start()
    ->mov('AH', 9)
    ->mov('ECX', 4)
    ->sub('RDX', 'AH', 'ECX')
    ->ret('RDX')
    ->end();

assertSameValue($result, 5, 'direct PASM sub');
assertChain(['MOV', 'MOV', 'SUB', 'RET', 'END'], 'direct PASM sub');

$result = PASM::start()
    ->mov('AH', 6)
    ->mov('ECX', 7)
    ->mul('RDX', 'AH', 'ECX')
    ->ret('RDX')
    ->end();

assertSameValue($result, 42, 'direct PASM mul');
assertChain(['MOV', 'MOV', 'MUL', 'RET', 'END'], 'direct PASM mul');

$result = PASM::start()
    ->mov('AH', 5)
    ->mov('ECX', 5)
    ->cmp('AH', 'ECX')
    ->ret('ZF')
    ->end();

assertSameValue($result, 1, 'direct PASM cmp equal');
assertChain(['MOV', 'MOV', 'CMP', 'RET', 'END'], 'direct PASM cmp equal');

$result = PASM::start()
    ->mov('AH', 4)
    ->mov('ECX', 5)
    ->cmp('AH', 'ECX')
    ->ret('CF')
    ->end();

assertSameValue($result, 1, 'direct PASM cmp less-than flag');
assertChain(['MOV', 'MOV', 'CMP', 'RET', 'END'], 'direct PASM cmp less-than flag');

// .jinx fixtures.
runJinxFixture('simple-add.jinx', 5, '.jinx add');
assertChain(array_merge($binaryOpChain, ['ADD', 'RET', 'END']), '.jinx add chain');

runJinxFixture('simple-sub.jinx', 5, '.jinx sub');
assertChain(array_merge($binaryOpChain, ['SUB', 'RET', 'END']), '.jinx sub chain');

runJinxFixture('simple-mul.jinx', 42, '.jinx mul');
assertChain(array_merge($binaryOpChain, ['MUL', 'RET', 'END']), '.jinx mul chain');

runJinxFixture('negative-add.jinx', 7, '.jinx negative add');
assertChain(array_merge($binaryOpChain, ['ADD', 'RET', 'END']), '.jinx negative add chain');

runJinxFixture('strlen.jinx', 6, '.jinx strlen');
assertChain(['MOV', 'MOV', 'MOV', 'JINX_BUILTIN', 'RET', 'END'], '.jinx strlen chain');

// PHP source fixtures.
runPhpFixture(
    'simple-add.php',
    "assign x int 2\nassign y int 3\nreturn add local x local y\n",
    5,
    'PHP add'
);

runPhpFixture(
    'simple-sub.php',
    "assign x int 9\nassign y int 4\nreturn sub local x local y\n",
    5,
    'PHP sub'
);

runPhpFixture(
    'simple-mul.php',
    "assign x int 6\nassign y int 7\nreturn mul local x local y\n",
    42,
    'PHP mul'
);

runPhpFixture(
    'negative-add.php',
    "assign x int -2\nassign y int 9\nreturn add local x local y\n",
    7,
    'PHP negative add'
);

runPhpFixture(
    'strlen.php',
    "assign s string oracle\nreturn builtin strlen local s\n",
    6,
    'PHP strlen'
);

// CLI tests.
$cliCases = [
    ['fixtures/simple-add.php', "5\n"],
    ['fixtures/simple-sub.php', "5\n"],
    ['fixtures/simple-mul.php', "42\n"],
    ['fixtures/negative-add.php', "7\n"],
    ['fixtures/strlen.php', "6\n"],
];

foreach ($cliCases as [$file, $expected]) {
    $output = [];
    $code = 0;

    exec(
        sprintf(
            'php %s %s 2>&1',
            escapeshellarg($root . '/scripts/jinx-run.php'),
            escapeshellarg($root . '/' . $file)
        ),
        $output,
        $code
    );

    $text = implode(PHP_EOL, $output) . PHP_EOL;

    if ($code !== 0) {
        fail("CLI {$file} exited {$code}: {$text}");
    }

    assertSameValue($text, $expected, "CLI {$file}");
}

echo "PASS: JINX state sweep covers PASM, .jinx, PHP source, builtins, and CLI\n";
