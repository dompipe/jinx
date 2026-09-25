<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;
use jinx\web\WebCompileException;

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$source = $root . '/fixtures/simple-web-api-validated.php';
$plan = WebApiCompiler::compileFileToPlan($source);

foreach ($plan['ops'] as $op) {
    if (!isset($op['line']) || !is_int($op['line']) || $op['line'] < 1) {
        fail('top-level op missing valid line: ' . json_encode($op));
    }

    if (!isset($op['source']) || !is_string($op['source']) || $op['source'] === '') {
        fail('top-level op missing source: ' . json_encode($op));
    }

    foreach (($op['then'] ?? []) as $thenOp) {
        if (!isset($thenOp['line']) || !is_int($thenOp['line']) || $thenOp['line'] < 1) {
            fail('nested op missing valid line: ' . json_encode($thenOp));
        }

        if (!isset($thenOp['source']) || !is_string($thenOp['source']) || $thenOp['source'] === '') {
            fail('nested op missing source: ' . json_encode($thenOp));
        }
    }
}

try {
    WebApiCompiler::compileFileToPlan($root . '/fixtures/simple-web-api-unsupported.php');
    fail('unsupported endpoint should fail');
} catch (WebCompileException $e) {
    $msg = $e->getMessage();

    if (!str_contains($msg, 'JINX_WEB_COMPILE_ERROR')) {
        fail('diagnostic missing error code: ' . $msg);
    }

    if (!str_contains($msg, 'simple-web-api-unsupported.php')) {
        fail('diagnostic missing source file: ' . $msg);
    }

    $unsupportedSource = file($root . '/fixtures/simple-web-api-unsupported.php');

    if ($unsupportedSource === false) {
        fail('could not read unsupported fixture');
    }

    $expectedLine = null;

    foreach ($unsupportedSource as $idx => $lineText) {
        if (str_contains($lineText, 'strtoupper')) {
            $expectedLine = $idx + 1;
            break;
        }
    }

    if ($expectedLine === null) {
        fail('could not find strtoupper line in unsupported fixture');
    }

    if (!str_contains($msg, ':' . $expectedLine)) {
        fail('diagnostic missing expected line number ' . $expectedLine . ': ' . $msg);
    }

    if (!str_contains($msg, 'Unsupported Web API statement')) {
        fail('diagnostic missing unsupported-statement text: ' . $msg);
    }
}

echo "PASS: Web API compiler includes source line diagnostics and clean unsupported errors\n";
