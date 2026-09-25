<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleAppBuiltinExecutor.php';

use jinx\oracle\OracleAppBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);

$cases = [
    'str-contains-builtins' => 'fixtures/oracle-app-builtin-str-contains.php',
    'str-starts-with-builtins' => 'fixtures/oracle-app-builtin-str-starts-with.php',
    'str-ends-with-builtins' => 'fixtures/oracle-app-builtin-str-ends-with.php',
    'stripos-builtins' => 'fixtures/oracle-app-builtin-stripos.php',
    'strrpos-builtins' => 'fixtures/oracle-app-builtin-strrpos.php',
    'strstr-builtins' => 'fixtures/oracle-app-builtin-strstr.php',
    'substr-count-builtins' => 'fixtures/oracle-app-builtin-substr-count.php',
    'wordwrap-builtins' => 'fixtures/oracle-app-builtin-wordwrap.php',
    'sprintf-builtins' => 'fixtures/oracle-app-builtin-sprintf.php',
    'number-format-builtins' => 'fixtures/oracle-app-builtin-number-format.php',
    'urlencode-builtins' => 'fixtures/oracle-app-builtin-urlencode.php',
    'urldecode-builtins' => 'fixtures/oracle-app-builtin-urldecode.php',
    'rawurlencode-builtins' => 'fixtures/oracle-app-builtin-rawurlencode.php',
    'rawurldecode-builtins' => 'fixtures/oracle-app-builtin-rawurldecode.php',
    'http-build-query-builtins' => 'fixtures/oracle-app-builtin-http-build-query.php',
    'parse-url-builtins' => 'fixtures/oracle-app-builtin-parse-url.php',
    'htmlspecialchars-builtins' => 'fixtures/oracle-app-builtin-htmlspecialchars.php',
    'html-entity-decode-builtins' => 'fixtures/oracle-app-builtin-html-entity-decode.php',
    'strip-tags-builtins' => 'fixtures/oracle-app-builtin-strip-tags.php',
    'nl2br-builtins' => 'fixtures/oracle-app-builtin-nl2br.php',
    'array-combine-builtins' => 'fixtures/oracle-app-builtin-array-combine.php',
    'array-flip-builtins' => 'fixtures/oracle-app-builtin-array-flip.php',
    'array-diff-builtins' => 'fixtures/oracle-app-builtin-array-diff.php',
    'array-intersect-builtins' => 'fixtures/oracle-app-builtin-array-intersect.php',
    'array-search-builtins' => 'fixtures/oracle-app-builtin-array-search.php',
    'array-column-builtins' => 'fixtures/oracle-app-builtin-array-column.php',
    'array-chunk-builtins' => 'fixtures/oracle-app-builtin-array-chunk.php',
    'range-builtins' => 'fixtures/oracle-app-builtin-range.php',
    'array-change-key-case-builtins' => 'fixtures/oracle-app-builtin-array-change-key-case.php',
    'array-fill-builtins' => 'fixtures/oracle-app-builtin-array-fill.php',
];

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

function run_php_app_builtin_fixture(string $fixture): array
{
    $__jinx_capture_return = null;
    $__jinx_capture_error_class = null;
    $__jinx_capture_error_message = null;

    ob_start();
    try {
        $__jinx_capture_return = require $fixture;
    } catch (Throwable $e) {
        $__jinx_capture_error_class = $e::class;
        $__jinx_capture_error_message = $e->getMessage();
    } finally {
        $__jinx_capture_output = (string) ob_get_clean();
    }

    return [
        'output' => $__jinx_capture_output,
        'return' => $__jinx_capture_return,
        'error_class' => $__jinx_capture_error_class,
        'error_message' => $__jinx_capture_error_message,
    ];
}

function run_oracle_app_builtin_fixture(string $fixture, string $family): array
{
    $result = [
        'output' => '',
        'return' => null,
        'error_class' => null,
        'error_message' => null,
        'oracle' => null,
        'program' => null,
    ];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleAppBuiltinExecutor::execute($program, $family);
        $result['program'] = $program;
        $result['oracle'] = $oracle;
        $result['output'] = $oracle['output'] ?? null;
        $result['return'] = $oracle['return'] ?? null;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    }

    return $result;
}

foreach ($cases as $family => $relativeFixture) {
    $fixture = $root . '/' . $relativeFixture;
    $php = run_php_app_builtin_fixture($fixture);
    $oracle = run_oracle_app_builtin_fixture($fixture, $family);

    same($oracle['error_class'], $php['error_class'], "{$family} Oracle error class matches PHP");

    if ($php['error_class'] !== null) {
        if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
            fail("{$family} Oracle error message does not include PHP error message");
        }
        continue;
    }

    same($oracle['output'], $php['output'], "{$family} Oracle output matches PHP");
    same($oracle['return'], $php['return'], "{$family} Oracle return matches PHP");
    same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', "{$family} Oracle execution kind");
    same($oracle['oracle']['family'] ?? null, $family, "{$family} Oracle execution family");

    if (($oracle['oracle']['executed_ops'] ?? 0) < 1) {
        fail("{$family} Oracle executed too few ops");
    }
}

echo "PASS: Oracle executes app builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
