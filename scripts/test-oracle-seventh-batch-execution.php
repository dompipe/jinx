<?php

declare(strict_types=1);

// Proof facets: seventh_batch_numeric_fold seventh_batch_keyed_foreach_increment seventh_batch_function_ternary_fold

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForeachExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';

use jinx\oracle\OracleForeachExecutor;
use jinx\oracle\OracleFunctionExecutor;
use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleStraightLineExecutor;

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
    return ['output'=>$output,'return'=>$ret,'error_class'=>$errorClass,'error_message'=>$errorMessage];
}

function has_top_level_return(array $program): bool
{
    $depth = 0;

    foreach ($program['statements'] ?? [] as $statement) {
        if (!is_array($statement)) {
            continue;
        }

        $op = (string) ($statement['op'] ?? '');

        if ($op === 'O_BLOCK_CLOSE') {
            $depth = max(0, $depth - 1);
            continue;
        }

        if ($depth === 0 && $op === 'O_RETURN') {
            return true;
        }

        if (in_array($op, [
            'O_FUNCTION_DECL',
            'O_METHOD_DECL',
            'O_IF',
            'O_ELSE',
            'O_FOR',
            'O_FOREACH',
            'O_WHILE',
            'O_DO',
            'O_SWITCH',
            'O_TRY',
            'O_CATCH',
            'O_FINALLY',
        ], true)) {
            $depth++;
        }
    }

    return false;
}

function run_oracle(string $fixture, string $executor): array
{
    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = match ($executor) {
            'straight' => OracleStraightLineExecutor::execute($program),
            'foreach' => OracleForeachExecutor::execute($program),
            'functions' => OracleFunctionExecutor::execute($program),
            default => throw new RuntimeException('unknown executor'),
        };
        return [
            'output'=>(string)($oracle['output']??''),
            'return'=>$oracle['return']??null,
            'error_class'=>null,
            'error_message'=>null,
            'program'=>$program,
        ];
    } catch (Throwable $e) {
        return ['output'=>'','return'=>null,'error_class'=>$e::class,'error_message'=>$e->getMessage(),'program'=>null];
    }
}

$cases = [
    'numeric' => ['fixtures/oracle-executable-seventh-batch-numeric.php', 'straight'],
    'foreach' => ['fixtures/oracle-executable-seventh-batch-foreach.php', 'foreach'],
    'function' => ['fixtures/oracle-executable-seventh-batch-function.php', 'functions'],
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
    $ops = array_column($oracle['program']['statements'] ?? [], 'op');
    $phpProgramReturn = has_top_level_return($oracle['program'] ?? []) ? $php['return'] : null;

    if ($oracle['return'] !== $phpProgramReturn) {
        fail(
            $label . ' return mismatch: PHP program=' . var_export($phpProgramReturn, true) .
            ' PHP require=' . var_export($php['return'], true) .
            ' Oracle=' . var_export($oracle['return'], true)
        );
    }

    if (in_array('O_RAW_PHP_STMT', $ops, true)) {
        fail($label . ' still records raw PHP statement');
    }
}

echo "PASS: Oracle executes seventh-batch numeric foreach and function families and matches PHP output/return/error behavior" . PHP_EOL;
