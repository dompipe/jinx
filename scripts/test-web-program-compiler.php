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

$required = WebProgramCompiler::compileAnyPhpFileToWebProgram($root . '/fixtures/oracle-require-entry.php');
same($required['kind'], 'JINX_WEB_PROGRAM', 'require entry web kind');
same($required['statement_count'], 1, 'require entry web statement count');

$requireStmt = $required['statements'][0] ?? null;
if (!is_array($requireStmt)) {
    fail('require entry did not produce a web statement');
}

same($requireStmt['op'] ?? null, 'WEB_REQUIRE_ORACLE', 'require web op');
same($requireStmt['target'] ?? null, 'oracle-required-library.php', 'require web target');
same($requireStmt['loader'] ?? null, 'require', 'require web loader');
same($requireStmt['enters_oracle_program'] ?? null, true, 'require web enters Oracle program');
same($requireStmt['included_statement_count'] ?? null, 3, 'require web included statement count');

$included = WebProgramCompiler::compileAnyPhpFileToWebProgram($root . '/fixtures/oracle-include-entry.php');
$includeStmt = $included['statements'][0] ?? null;
if (!is_array($includeStmt)) {
    fail('include entry did not produce a web statement');
}

same($includeStmt['op'] ?? null, 'WEB_INCLUDE_ORACLE', 'include web op');
same($includeStmt['target'] ?? null, 'oracle-required-library.php', 'include web target');
same($includeStmt['loader'] ?? null, 'include', 'include web loader');
same($includeStmt['once'] ?? null, false, 'include web once flag');
same($includeStmt['literal_target'] ?? null, true, 'include web literal target flag');
same($includeStmt['enters_oracle_program'] ?? null, true, 'include web enters Oracle program');

$includeOnce = WebProgramCompiler::compileAnyPhpFileToWebProgram($root . '/fixtures/oracle-include-once-entry.php');
$includeOnceStmt = $includeOnce['statements'][0] ?? null;
if (!is_array($includeOnceStmt)) {
    fail('include_once entry did not produce a web statement');
}

same($includeOnceStmt['op'] ?? null, 'WEB_INCLUDE_ORACLE', 'include_once web op');
same($includeOnceStmt['loader'] ?? null, 'include_once', 'include_once web loader');
same($includeOnceStmt['once'] ?? null, true, 'include_once web once flag');

$dynamic = WebProgramCompiler::compileAnyPhpFileToWebProgram($root . '/fixtures/oracle-dynamic-loader-entry.php');
$dynamicStmt = $dynamic['statements'][1] ?? null;
if (!is_array($dynamicStmt)) {
    fail('dynamic include did not produce a web statement');
}

same($dynamicStmt['op'] ?? null, 'WEB_INCLUDE_ORACLE', 'dynamic include web op');
same($dynamicStmt['enters_oracle_program'] ?? null, false, 'dynamic include does not enter Oracle program');
same($dynamicStmt['php_fallback_required'] ?? null, true, 'dynamic include web fallback flag');

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
