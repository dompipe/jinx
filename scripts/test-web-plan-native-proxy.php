<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

$input = $argv[1] ?? dirname(__DIR__) . '/fixtures/simple-web-api-validated.php';

try {
    $plan = WebApiCompiler::compileFileToPlan($input);
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'web-plan native proxy failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
