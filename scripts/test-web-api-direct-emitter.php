<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$out = $root . '/build/web-compiled/direct-emitter-test.compiled.php';

WebApiCompiler::compileFileToEndpoint(
    $root . '/fixtures/simple-web-api-validated.php',
    $out
);

$php = (string) file_get_contents($out);

foreach ([
    '$data = json_decode',
    'isset($data[',
    '$name = $data',
    "json_encode(['ok' => true, 'name' => \$name])",
] as $needle) {
    if (!str_contains($php, $needle)) {
        fail("compiled endpoint missing direct emitter fragment: {$needle}\n{$php}");
    }
}

if (str_contains($php, '$locals')) {
    fail("compiled endpoint should not use generic locals array anymore:\n{$php}");
}

echo "PASS: WebApiCompiler direct emitter writes plain PHP variables instead of locals array\n";
