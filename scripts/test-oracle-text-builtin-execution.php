<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleTextBuiltinExecutor.php';

use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleTextBuiltinExecutor;

$root = dirname(__DIR__);

$cases = [
    'chr-builtins' => 'fixtures/oracle-text-builtin-chr.php',
    'ord-builtins' => 'fixtures/oracle-text-builtin-ord.php',
    'strcmp-builtins' => 'fixtures/oracle-text-builtin-strcmp.php',
    'strcasecmp-builtins' => 'fixtures/oracle-text-builtin-strcasecmp.php',
    'strncmp-builtins' => 'fixtures/oracle-text-builtin-strncmp.php',
    'strncasecmp-builtins' => 'fixtures/oracle-text-builtin-strncasecmp.php',
    'substr-compare-builtins' => 'fixtures/oracle-text-builtin-substr-compare.php',
    'similar-text-builtins' => 'fixtures/oracle-text-builtin-similar-text.php',
    'levenshtein-builtins' => 'fixtures/oracle-text-builtin-levenshtein.php',
    'soundex-builtins' => 'fixtures/oracle-text-builtin-soundex.php',
    'metaphone-builtins' => 'fixtures/oracle-text-builtin-metaphone.php',
    'str-rot13-builtins' => 'fixtures/oracle-text-builtin-str-rot13.php',
    'addslashes-builtins' => 'fixtures/oracle-text-builtin-addslashes.php',
    'stripslashes-builtins' => 'fixtures/oracle-text-builtin-stripslashes.php',
    'quotemeta-builtins' => 'fixtures/oracle-text-builtin-quotemeta.php',
    'addcslashes-builtins' => 'fixtures/oracle-text-builtin-addcslashes.php',
    'substr-replace-builtins' => 'fixtures/oracle-text-builtin-substr-replace.php',
    'strtr-builtins' => 'fixtures/oracle-text-builtin-strtr.php',
    'str-getcsv-builtins' => 'fixtures/oracle-text-builtin-str-getcsv.php',
    'str-word-count-builtins' => 'fixtures/oracle-text-builtin-str-word-count.php',
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

function run_php_text_fixture(string $fixture): array
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

function run_oracle_text_fixture(string $fixture, string $family): array
{
    $result = [
        'output' => '',
        'return' => null,
        'error_class' => null,
        'error_message' => null,
        'oracle' => null,
    ];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleTextBuiltinExecutor::execute($program, $family);
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
    $php = run_php_text_fixture($fixture);
    $oracle = run_oracle_text_fixture($fixture, $family);

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
}

echo "PASS: Oracle executes text builtin PHP families and matches PHP output/return/error behavior" . PHP_EOL;
