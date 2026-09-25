<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PASM.php';
require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';
require_once dirname(__DIR__) . '/runtime/CoalescedOracleCompiler.php';

use jinx\pasm\PASM;
use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;
use jinx\oracle\CoalescedOracleCompiler;

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function hasMethod(string $class, string $method): void
{
    if (!method_exists($class, $method)) {
        fail("Missing method {$class}::{$method}()");
    }
}

/**
 * 1. PASM is the Oracle-style PHP class chain.
 */
hasMethod(PASM::class, 'start');
hasMethod(PASM::class, 'end');
hasMethod(PASM::class, 'mov');
hasMethod(PASM::class, 'add');
hasMethod(PASM::class, 'sub');
hasMethod(PASM::class, 'mul');
hasMethod(PASM::class, 'cmp');
hasMethod(PASM::class, 'ret');
hasMethod(PASM::class, 'jinx_builtin');

$result = PASM::start()
    ->mov('AH', 2)
    ->mov('LOCAL:x', 'AH')
    ->mov('AH', 3)
    ->mov('LOCAL:y', 'AH')
    ->mov('ECX', 'LOCAL:x')
    ->mov('AH', 'LOCAL:y')
    ->add('RDX', 'ECX', 'AH')
    ->ret('RDX')
    ->end();

same($result, 5, 'Oracle/PASM chain result');
same(PASM::$chain, [
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'ADD',
    'RET',
    'END',
], 'Oracle/PASM debug chain');

/**
 * 2. PHP source lowers into the expected .jinx text.
 */
$expectedJinx = [
    'fixtures/simple-add.php' => [
        "assign x int 2\nassign y int 3\nreturn add local x local y\n",
        5,
        'ORET_CONST',
    ],
    'fixtures/simple-sub.php' => [
        "assign x int 9\nassign y int 4\nreturn sub local x local y\n",
        5,
        'ORET_CONST',
    ],
    'fixtures/simple-mul.php' => [
        "assign x int 6\nassign y int 7\nreturn mul local x local y\n",
        42,
        'ORET_CONST',
    ],
    'fixtures/negative-add.php' => [
        "assign x int -2\nassign y int 9\nreturn add local x local y\n",
        7,
        'ORET_CONST',
    ],
    'fixtures/strlen.php' => [
        "assign s string oracle\nreturn builtin strlen local s\n",
        6,
        'ORET_CONST',
    ],
];

foreach ($expectedJinx as $file => [$expectedText, $expectedResult, $expectedFinalOp]) {
    $path = $root . '/' . $file;

    if (!is_file($path)) {
        fail("Missing fixture {$file}");
    }

    $jinx = PhpToJinxLowerer::lowerFile($path);
    same($jinx, $expectedText, "{$file} lowers to expected .jinx");

    /**
     * 3. .jinx runs through the Oracle/PASM debug lowerer.
     */
    $debugResult = JinxToPasmLowerer::run($jinx);
    same($debugResult, $expectedResult, "{$file} debug PASM execution result");

    if (PASM::$fault !== null) {
        fail("{$file} caused PASM fault: " . PASM::$fault);
    }

    /**
     * 4. .jinx also lowers into coalesced Oracle representation.
     */
    $ops = CoalescedOracleCompiler::compileJinx($jinx);

    if ($ops === []) {
        fail("{$file} produced no coalesced Oracle ops");
    }

    same($ops[array_key_last($ops)]['op'], $expectedFinalOp, "{$file} coalesced final op");

    /**
     * 5. Coalesced Oracle execution agrees with debug PASM.
     */
    $coalescedResult = CoalescedOracleCompiler::execute($ops);
    same($coalescedResult, $expectedResult, "{$file} coalesced Oracle execution result");

    /**
     * 6. Coalesced closure execution agrees too.
     */
    $closure = CoalescedOracleCompiler::compileJinxToClosure($jinx);
    same($closure(), $expectedResult, "{$file} coalesced Oracle closure result");
}

/**
 * 7. Dynamic unresolved Oracle path must stay Oracle-shaped, not fake-folded.
 */
$dynamicJinx = "return add local x local y\n";
$dynamicOps = CoalescedOracleCompiler::compileJinx($dynamicJinx);

same($dynamicOps, [[
    'op' => 'ORET_ADD_LOCAL',
    'left' => 'LOCAL:x',
    'right' => 'LOCAL:y',
]], 'dynamic unresolved locals preserve coalesced Oracle add op');

/**
 * 8. CLI runner exists and works.
 */
$runner = $root . '/scripts/jinx-run.php';

if (!is_file($runner)) {
    fail('Missing scripts/jinx-run.php');
}

$cliCases = [
    'fixtures/simple-add.php' => "5\n",
    'fixtures/simple-sub.php' => "5\n",
    'fixtures/simple-mul.php' => "42\n",
    'fixtures/negative-add.php' => "7\n",
    'fixtures/strlen.php' => "6\n",
];

foreach ($cliCases as $file => $expectedOutput) {
    $output = [];
    $code = 0;

    exec(
        sprintf(
            'php %s %s 2>&1',
            escapeshellarg($runner),
            escapeshellarg($root . '/' . $file)
        ),
        $output,
        $code
    );

    $text = implode(PHP_EOL, $output) . PHP_EOL;

    if ($code !== 0) {
        fail("CLI failed for {$file}: {$text}");
    }

    same($text, $expectedOutput, "CLI output for {$file}");
}

echo "PASS: intended architecture works: PHP → .jinx → PASM debug chain → coalesced Oracle → CLI results" . PHP_EOL;
