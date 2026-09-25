<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/CoalescedOraclePhpEmitter.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\CoalescedOraclePhpEmitter;

$args = array_slice($argv, 1);

$showJinx = false;
$showOracle = false;
$input = null;
$output = null;

foreach ($args as $arg) {
    if ($arg === '--show-jinx') {
        $showJinx = true;
        continue;
    }

    if ($arg === '--show-oracle') {
        $showOracle = true;
        continue;
    }

    if ($input === null) {
        $input = $arg;
        continue;
    }

    if ($output === null) {
        $output = $arg;
        continue;
    }

    fwrite(STDERR, "Unexpected argument: {$arg}" . PHP_EOL);
    exit(1);
}

if ($input === null || $output === null) {
    fwrite(STDERR, "Usage: php scripts/jinx-compile.php [--show-jinx] [--show-oracle] input.php output.php" . PHP_EOL);
    exit(1);
}

if (!is_file($input)) {
    fwrite(STDERR, "Missing input file: {$input}" . PHP_EOL);
    exit(1);
}

$compiledProgram = OracleProgramCompiler::compileExecutablePhpFile($input);
$jinx = $compiledProgram['jinx'];
$ops = $compiledProgram['ops'];
$compiled = CoalescedOraclePhpEmitter::emitProgram($ops);

$dir = dirname($output);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

file_put_contents($output, $compiled);

if ($showJinx) {
    echo "=== .jinx ===" . PHP_EOL;
    echo $jinx;
}

if ($showOracle) {
    echo "=== coalesced Oracle ===" . PHP_EOL;
    foreach ($ops as $op) {
        echo json_encode($op, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}

echo "compiled {$input} -> {$output}" . PHP_EOL;
