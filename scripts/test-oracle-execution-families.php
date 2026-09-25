<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleArrayExecutor;
use jinx\oracle\OracleConditionalExecutor;
use jinx\oracle\OracleExecutionFamilies;
use jinx\oracle\OracleLoopExecutor;
use jinx\oracle\OracleStraightLineExecutor;

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

$families = OracleExecutionFamilies::all();

foreach (['straight-line', 'conditionals', 'loops', 'arrays'] as $family) {
    if (!isset($families[$family])) {
        fail("missing {$family} execution family");
    }
}

$straightLine = OracleExecutionFamilies::get('straight-line');

same($straightLine['state'] ?? null, 'executable', 'straight-line state');
same($straightLine['owner'] ?? null, OracleStraightLineExecutor::class, 'straight-line owner');
same($straightLine['test'] ?? null, 'scripts/test-oracle-straightline-execution.php', 'straight-line test');

foreach (['O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_ECHO', 'O_PRINT', 'O_RETURN'] as $op) {
    if (!in_array($op, $straightLine['ops'] ?? [], true)) {
        fail("straight-line family missing {$op}");
    }
}

foreach (['strlen', 'strtoupper'] as $builtin) {
    if (!in_array($builtin, $straightLine['builtins'] ?? [], true)) {
        fail("straight-line family missing builtin {$builtin}");
    }
}

$conditionals = OracleExecutionFamilies::get('conditionals');

same($conditionals['state'] ?? null, 'executable', 'conditionals state');
same($conditionals['owner'] ?? null, OracleConditionalExecutor::class, 'conditionals owner');
same($conditionals['test'] ?? null, 'scripts/test-oracle-conditional-execution.php', 'conditionals test');

foreach (['O_IF', 'O_ELSE', 'O_BLOCK_CLOSE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN'] as $op) {
    if (!in_array($op, $conditionals['ops'] ?? [], true)) {
        fail("conditionals family missing {$op}");
    }
}

foreach (['===', '!==', '==', '!=', '>', '<', '>=', '<='] as $comparison) {
    if (!in_array($comparison, $conditionals['comparisons'] ?? [], true)) {
        fail("conditionals family missing comparison {$comparison}");
    }
}

foreach (['&&', '||', '!'] as $operator) {
    if (!in_array($operator, $conditionals['boolean_operators'] ?? [], true)) {
        fail("conditionals family missing boolean operator {$operator}");
    }
}

$loops = OracleExecutionFamilies::get('loops');

same($loops['state'] ?? null, 'executable', 'loops state');
same($loops['owner'] ?? null, OracleLoopExecutor::class, 'loops owner');
same($loops['test'] ?? null, 'scripts/test-oracle-loop-execution.php', 'loops test');

foreach (['O_WHILE', 'O_BREAK', 'O_CONTINUE', 'O_IF', 'O_BLOCK_CLOSE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $loops['ops'] ?? [], true)) {
        fail("loops family missing {$op}");
    }
}

foreach (['while', 'break', 'continue'] as $flow) {
    if (!in_array($flow, $loops['control_flow'] ?? [], true)) {
        fail("loops family missing control flow {$flow}");
    }
}

$arrays = OracleExecutionFamilies::get('arrays');

same($arrays['state'] ?? null, 'executable', 'arrays state');
same($arrays['owner'] ?? null, OracleArrayExecutor::class, 'arrays owner');
same($arrays['test'] ?? null, 'scripts/test-oracle-array-execution.php', 'arrays test');

foreach (['O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_UNSET', 'O_ECHO', 'O_RETURN'] as $op) {
    if (!in_array($op, $arrays['ops'] ?? [], true)) {
        fail("arrays family missing {$op}");
    }
}

foreach (['literal_empty_array', 'append', 'nested_dimension_assign', 'nested_dimension_fetch', 'isset', 'empty', 'unset'] as $arrayOp) {
    if (!in_array($arrayOp, $arrays['array_ops'] ?? [], true)) {
        fail("arrays family missing array op {$arrayOp}");
    }
}

foreach (['count'] as $builtin) {
    if (!in_array($builtin, $arrays['builtins'] ?? [], true)) {
        fail("arrays family missing builtin {$builtin}");
    }
}

echo "PASS: Oracle execution families expose the straight-line, conditionals, loops, and arrays executable families" . PHP_EOL;
