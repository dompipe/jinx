<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

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

$simple = OracleProgramCompiler::compileAnyPhpFileToOracleProgram($root . '/fixtures/simple-add.php');

same($simple['kind'], 'JINX_ORACLE_PROGRAM', 'simple kind');
same($simple['executable'], true, 'simple executable');
same($simple['coalesced_ops'], [[
    'op' => 'ORET_CONST',
    'value' => 5,
]], 'simple coalesced ops');

$realFile = $root . '/fixtures/oracle-post-curl-dynamic.php';

if (!is_file($realFile)) {
    $realFile = $root . '/fixtures/real-post-curl-dynamic.php';
}

if (is_file($realFile)) {
    $real = OracleProgramCompiler::compileAnyPhpFileToOracleProgram($realFile);

    same($real['kind'], 'JINX_ORACLE_PROGRAM', 'real kind');

    if (($real['statement_count'] ?? 0) < 10) {
        fail('real PHP file should produce many Oracle statements');
    }

    $seenCurl = false;
    $seenJson = false;
    $seenPost = false;
    $seenFunction = false;

    foreach ($real['statements'] as $stmt) {
        $features = (array) ($stmt['features'] ?? []);

        if (($features['curl'] ?? false) === true) {
            $seenCurl = true;
        }

        if (($features['json'] ?? false) === true) {
            $seenJson = true;
        }

        if (($features['post_input'] ?? false) === true) {
            $seenPost = true;
        }

        if (($stmt['op'] ?? null) === 'O_FUNCTION_DECL') {
            $seenFunction = true;
        }
    }

    same($seenFunction, true, 'real PHP has Oracle function declarations');
    same($seenCurl, true, 'real PHP has Oracle curl feature');
    same($seenJson, true, 'real PHP has Oracle json feature');
    same($seenPost, true, 'real PHP has Oracle post-input feature');
}

echo "PASS: OracleProgramCompiler turns PHP into canonical Oracle statements and executable ops when supported" . PHP_EOL;
