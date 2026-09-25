<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;
use jinx\pasm\PASM;

$showJinx = false;
$showPasm = false;
$path = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--show-jinx') {
        $showJinx = true;
        continue;
    }

    if ($arg === '--show-pasm') {
        $showPasm = true;
        continue;
    }

    if ($path === null) {
        $path = $arg;
        continue;
    }

    fwrite(STDERR, "Unexpected argument: {$arg}" . PHP_EOL);
    exit(1);
}

if ($path === null) {
    fwrite(STDERR, "Usage: php scripts/jinx-run.php [--show-jinx] [--show-pasm] file.php" . PHP_EOL);
    exit(1);
}

if (!is_file($path)) {
    fwrite(STDERR, "Missing input file: {$path}" . PHP_EOL);
    exit(1);
}

try {
    $jinx = PhpToJinxLowerer::lowerFile($path);

    if ($showJinx) {
        echo "=== .jinx ===" . PHP_EOL;
        echo $jinx;
    }

    $result = JinxToPasmLowerer::run($jinx);

    if ($showPasm) {
        echo "=== PASM chain ===" . PHP_EOL;
        foreach (PASM::$chain as $i => $command) {
            echo str_pad((string) $i, 4, ' ', STR_PAD_LEFT) . "  {$command}" . PHP_EOL;
        }
    }

    if (PASM::$fault !== null) {
        fwrite(STDERR, "PASM fault: " . PASM::$fault . PHP_EOL);
        exit(1);
    }

    echo $result . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "JINX error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
