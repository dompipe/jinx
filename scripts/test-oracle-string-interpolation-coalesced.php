<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/CoalescedOracleCompiler.php';

use jinx\oracle\CoalescedOracleCompiler;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-coalesced-string-interpolation.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$compiled = OracleProgramCompiler::compileExecutablePhpFile($fixture);
$ops = $compiled['ops'] ?? [];
$result = CoalescedOracleCompiler::execute($ops);

$phpResult = require $fixture;

if ($result !== $phpResult) {
    fail('coalesced interpolated string result mismatch: expected ' . var_export($phpResult, true) . ', got ' . var_export($result, true));
}

$jinx = (string) ($compiled['jinx'] ?? '');
if (!str_contains($jinx, 'interpolated')) {
    fail('lowered JINX did not contain interpolated assignment');
}

if (($ops[0]['op'] ?? null) !== 'ORET_CONST' || ($ops[0]['value'] ?? null) !== 10) {
    fail('coalesced compiler did not fold interpolated string strlen to ORET_CONST 10');
}

echo "PASS: Coalesced Oracle compiles PHP string interpolation" . PHP_EOL;
