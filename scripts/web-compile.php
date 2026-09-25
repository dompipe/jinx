<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebProgramCompiler.php';

use jinx\web\WebProgramCompiler;

$args = array_slice($argv, 1);

$input = $args[0] ?? null;
$output = $args[1] ?? null;

if ($input === null) {
    fwrite(STDERR, "Usage: php scripts/web-compile.php input.php [output.web.json]" . PHP_EOL);
    exit(1);
}

try {
    $program = WebProgramCompiler::compileAnyPhpFileToWebProgram($input);
    $json = json_encode($program, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Could not encode Web program JSON');
    }

    if ($output !== null) {
        $dir = dirname($output);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($output, $json . PHP_EOL);
        echo "compiled {$input} -> {$output}" . PHP_EOL;
    } else {
        echo $json . PHP_EOL;
    }

    if (!$program['executable']) {
        fwrite(STDERR, "NOTE: Web statement stream created, but executable coalesced ops are not available yet: {$program['executable_error']}" . PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Web compile failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
