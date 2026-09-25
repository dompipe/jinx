<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebProgramCompiler.php';

use jinx\web\WebProgramCompiler;

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

$simple = WebProgramCompiler::compileAnyPhpFileToWebProgram($root . '/fixtures/simple-add.php');

same($simple['kind'], 'JINX_WEB_PROGRAM', 'simple kind');
same($simple['executable'], true, 'simple executable');
same($simple['coalesced_ops'], [[
    'op' => 'WEB_RET_CONST',
    'value' => 5,
]], 'simple web coalesced ops');

$realFile = $root . '/fixtures/oracle-post-curl-dynamic.php';

if (is_file($realFile)) {
    $real = WebProgramCompiler::compileAnyPhpFileToWebProgram($realFile);

    same($real['kind'], 'JINX_WEB_PROGRAM', 'real kind');

    $seenWebJson = false;
    $seenWebPost = false;
    $seenWebCurl = false;
    $seenWebFunction = false;

    foreach ($real['statements'] as $stmt) {
        if (in_array(($stmt['op'] ?? null), ['WEB_JSON_RELATED', 'WEB_JSON_ENCODE', 'WEB_JSON_DECODE'], true)) {
            $seenWebJson = true;
        }

        if (in_array(($stmt['op'] ?? null), ['WEB_POST_INPUT_RELATED', 'WEB_READ_BODY', 'WEB_POST_SUPERGLOBAL'], true)) {
            $seenWebPost = true;
        }

        if (in_array(($stmt['op'] ?? null), ['WEB_CURL_RELATED', 'WEB_CURL_CALL'], true)) {
            $seenWebCurl = true;
        }

        if (($stmt['op'] ?? null) === 'WEB_FUNCTION_DECL') {
            $seenWebFunction = true;
        }
    }

    same($seenWebFunction, true, 'real PHP has web function declarations');
    same($seenWebJson, true, 'real PHP has web JSON statements');
    same($seenWebPost, true, 'real PHP has web POST statements');
    same($seenWebCurl, true, 'real PHP has web cURL statements');
}

echo "PASS: WebProgramCompiler replaces Oracle naming with canonical web statements" . PHP_EOL;
