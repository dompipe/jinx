<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/CoalescedOracleCompiler.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\oracle\CoalescedOracleCompiler;

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

$cases = [
    ['fixtures/simple-add.php', 5, 'add'],
    ['fixtures/simple-sub.php', 5, 'sub'],
    ['fixtures/simple-mul.php', 42, 'mul'],
    ['fixtures/negative-add.php', 7, 'negative add'],
    ['fixtures/strlen.php', 6, 'strlen'],
];

foreach ($cases as [$file, $expected, $label]) {
    $jinx = PhpToJinxLowerer::lowerFile($root . '/' . $file);
    $ops = CoalescedOracleCompiler::compileJinx($jinx);

    assertSameValue(count($ops), 1, "{$label} folds to one coalesced Oracle op");
    assertSameValue($ops[0]['op'], 'ORET_CONST', "{$label} final op");

    $result = CoalescedOracleCompiler::execute($ops);
    assertSameValue($result, $expected, "{$label} coalesced execute");

    $closure = CoalescedOracleCompiler::compileJinxToClosure($jinx);
    assertSameValue($closure(), $expected, "{$label} coalesced closure");
}

$dynamicJinx = <<<JINX
return add local x local y
JINX;

$ops = CoalescedOracleCompiler::compileJinx($dynamicJinx);
assertSameValue($ops, [[
    'op' => 'ORET_ADD_LOCAL',
    'left' => 'LOCAL:x',
    'right' => 'LOCAL:y',
]], 'dynamic unresolved locals preserve Oracle add op');

echo "PASS: coalesced Oracle compiler folds constants and preserves Oracle ops when needed" . PHP_EOL;
