<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

$input = $argv[1] ?? null;
$output = $argv[2] ?? null;

if ($input === null || $output === null) {
    fwrite(STDERR, "Usage: php scripts/web-api-compile.php input.php output.php" . PHP_EOL);
    exit(1);
}

try {
    WebApiCompiler::compileFileToEndpoint($input, $output);
    echo "compiled {$input} -> {$output}" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "WEB API compile failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
