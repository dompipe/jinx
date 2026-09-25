<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/CoalescedOracleCompiler.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\oracle\CoalescedOracleCompiler;

$root = dirname(__DIR__);
$source = $root . '/fixtures/hard-oracle-chain.php';
$out = $root . '/build/compiled/hard-oracle-chain.compiled.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run(string $cmd, ?int &$code = null): string
{
    $output = [];
    $status = 0;
    exec($cmd . ' 2>&1', $output, $status);
    $code = $status;

    return implode(PHP_EOL, $output) . (count($output) ? PHP_EOL : '');
}

$native = require $source;

$jinx = PhpToJinxLowerer::lowerFile($source);
$ops = CoalescedOracleCompiler::compileJinx($jinx);
$coalesced = CoalescedOracleCompiler::execute($ops);

if ($native !== $coalesced) {
    fail('coalesced Oracle result does not match native PHP');
}

if (count($ops) !== 1 || ($ops[0]['op'] ?? null) !== 'ORET_CONST') {
    fail('hard constant dependency chain should fold to one ORET_CONST op; got ' . json_encode($ops));
}

$compileOutput = run(sprintf(
    'php %s --show-oracle %s %s',
    escapeshellarg($root . '/scripts/jinx-compile.php'),
    escapeshellarg($source),
    escapeshellarg($out)
), $code);

if ($code !== 0) {
    fail("jinx-compile failed:\n{$compileOutput}");
}

$compiledOutput = run(sprintf('php %s', escapeshellarg($out)), $code);

if ($code !== 0) {
    fail("compiled output failed:\n{$compiledOutput}");
}

if (trim($compiledOutput) !== (string) $native) {
    fail("compiled output mismatch: expected {$native}, got {$compiledOutput}");
}

if (!str_contains($compileOutput, '"op":"ORET_CONST"')) {
    fail("compiler output did not show ORET_CONST:\n{$compileOutput}");
}

echo "PASS: hard Oracle PHP compiler build folds dependent arithmetic chain and emits correct compiled PHP\n";
