<?php

declare(strict_types=1);

// Proof facets: late_foreach_reductions late_function_string_builtins late_for_reductions_expression_index

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleForeachExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForExecutor.php';

use jinx\oracle\OracleForeachExecutor;
use jinx\oracle\OracleForExecutor;
use jinx\oracle\OracleFunctionExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run_php(string $fixture): array
{
    $ret = null;
    $errorClass = null;
    $errorMessage = null;
    ob_start();
    try {
        $ret = require $fixture;
    } catch (Throwable $e) {
        $errorClass = $e::class;
        $errorMessage = $e->getMessage();
    } finally {
        $output = (string) ob_get_clean();
    }

    return [
        'output' => $output,
        'return' => $ret,
        'error_class' => $errorClass,
        'error_message' => $errorMessage,
    ];
}

function run_oracle(string $fixture, string $executor): array
{
    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = match ($executor) {
            'foreach' => OracleForeachExecutor::execute($program),
            'functions' => OracleFunctionExecutor::execute($program),
            'for' => OracleForExecutor::execute($program),
            default => throw new RuntimeException('unknown executor'),
        };

        return [
            'output' => (string) ($oracle['output'] ?? ''),
            'return' => $oracle['return'] ?? null,
            'error_class' => null,
            'error_message' => null,
            'program' => $program,
        ];
    } catch (Throwable $e) {
        return [
            'output' => '',
            'return' => null,
            'error_class' => $e::class,
            'error_message' => $e->getMessage(),
            'program' => null,
        ];
    }
}

$cases = [
    'foreach-reductions' => ['fixtures/oracle-executable-late-batch-foreach-reductions.php', 'foreach'],
    'function-strings' => ['fixtures/oracle-executable-late-batch-function-strings.php', 'functions'],
    'for-reductions' => ['fixtures/oracle-executable-late-batch-for-reductions.php', 'for'],
];

foreach ($cases as $label => [$relative, $executor]) {
    $fixture = dirname(__DIR__) . '/' . $relative;
    $php = run_php($fixture);
    $oracle = run_oracle($fixture, $executor);

    if ($oracle['error_class'] !== $php['error_class']) {
        fail($label . ' error class mismatch: PHP=' . var_export($php['error_class'], true) . ' Oracle=' . var_export($oracle['error_class'], true) . ' Oracle message=' . var_export($oracle['error_message'], true));
    }

    if ($oracle['error_class'] !== null) {
        if ($oracle['error_message'] !== $php['error_message']) {
            fail($label . ' error message mismatch');
        }
        continue;
    }

    if ($oracle['output'] !== $php['output']) {
        fail($label . ' output mismatch: PHP=' . json_encode($php['output']) . ' Oracle=' . json_encode($oracle['output']));
    }

    if ($oracle['return'] !== $php['return']) {
        fail($label . ' return mismatch: PHP=' . var_export($php['return'], true) . ' Oracle=' . var_export($oracle['return'], true));
    }

    $ops = array_column($oracle['program']['statements'] ?? [], 'op');
    if (in_array('O_RAW_PHP_STMT', $ops, true)) {
        fail($label . ' still records raw PHP statement');
    }
}

echo "PASS: Oracle executes late-batch foreach function and for reductions and matches PHP output/return/error behavior" . PHP_EOL;
