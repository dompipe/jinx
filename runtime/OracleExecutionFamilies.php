<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executable Oracle family ledger.
 *
 * A family is listed here only when it has a runtime owner and a test that
 * compares Oracle behavior with PHP behavior.
 */
final class OracleExecutionFamilies
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $nextTenTest = 'scripts/test-oracle-next-ten-execution.php';
        $nextTenOwner = OracleNextTenExecutor::class;

        return [
            'straight-line' => [
                'state' => 'executable',
                'owner' => OracleStraightLineExecutor::class,
                'test' => 'scripts/test-oracle-straightline-execution.php',
                'ops' => ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
                'builtins' => ['strlen', 'strtoupper'],
            ],
            'conditionals' => [
                'state' => 'executable',
                'owner' => OracleConditionalExecutor::class,
                'test' => 'scripts/test-oracle-conditional-execution.php',
                'ops' => ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_IF', 'O_ELSE', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
                'comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<='],
                'boolean_operators' => ['&&', '||', '!'],
                'builtins' => ['strlen', 'strtoupper'],
            ],
            'loops' => [
                'state' => 'executable',
                'owner' => OracleLoopExecutor::class,
                'test' => 'scripts/test-oracle-loop-execution.php',
                'ops' => ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_IF', 'O_ELSE', 'O_WHILE', 'O_BREAK', 'O_CONTINUE', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
                'control_flow' => ['while', 'break', 'continue'],
                'comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<='],
                'boolean_operators' => ['&&', '||', '!'],
                'builtins' => ['strlen', 'strtoupper'],
            ],
            'arrays' => [
                'state' => 'executable',
                'owner' => OracleArrayExecutor::class,
                'test' => 'scripts/test-oracle-array-execution.php',
                'ops' => ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_UNSET', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
                'array_ops' => ['literal_empty_array', 'append', 'nested_dimension_assign', 'nested_dimension_fetch', 'isset', 'empty', 'unset'],
                'builtins' => ['count'],
            ],
            'functions' => [
                'state' => 'executable',
                'owner' => OracleFunctionExecutor::class,
                'test' => 'scripts/test-oracle-function-execution.php',
                'ops' => ['O_DECLARE', 'O_FUNCTION_DECL', 'O_ASSIGN', 'O_COMPOUND_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN', 'O_BLOCK_CLOSE'],
                'function_ops' => ['named_user_function', 'local_parameter_scope', 'return_value', 'nested_user_call', 'builtin_dispatch'],
                'builtins' => ['strlen', 'strtoupper'],
            ],
            'request-globals' => [
                'state' => 'executable',
                'owner' => OracleRequestExecutor::class,
                'test' => 'scripts/test-oracle-request-globals-execution.php',
                'ops' => ['O_DECLARE', 'O_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
                'superglobals' => ['$_SERVER', '$_GET', '$_POST', '$_REQUEST'],
                'request_ops' => ['request_context', 'query_params', 'post_params', 'request_params', 'server_params', 'isset', 'empty'],
                'builtins' => ['count'],
            ],
            'include-require' => [
                'state' => 'executable',
                'owner' => OracleIncludeExecutor::class,
                'test' => 'scripts/test-oracle-include-require-execution.php',
                'ops' => ['O_DECLARE', 'O_INCLUDE', 'O_REQUIRE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN'],
                'loader_ops' => ['literal_include', 'literal_require', 'resolved_oracle_edge', 'included_local_scope', 'included_output'],
            ],
            'exit-die' => [
                'state' => 'executable',
                'owner' => OracleExitExecutor::class,
                'test' => 'scripts/test-oracle-exit-die-execution.php',
                'ops' => ['O_DECLARE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_EXIT', 'O_RETURN'],
                'termination_ops' => ['exit_string_output', 'die_alias', 'termination_flag', 'exit_code', 'unreachable_code_stops'],
            ],
            'ternary-expressions' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_TERNARY', 'O_ECHO', 'O_RETURN'],
                'expression_ops' => ['ternary_true_branch', 'ternary_false_branch'],
            ],
            'type-casts' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'casts' => ['int', 'string', 'bool', 'float', 'array'],
            ],
            'string-builtins' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'builtins' => ['strlen', 'strtoupper', 'strtolower', 'trim', 'substr'],
            ],
            'math-builtins' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'builtins' => ['abs', 'max', 'min', 'round'],
            ],
            'comparison-expressions' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<=', '<=>'],
            ],
            'boolean-expressions' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_TERNARY', 'O_ECHO', 'O_RETURN'],
                'boolean_operators' => ['&&', '||', '!'],
            ],
            'magic-constants' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'magic_constants' => ['__FILE__', '__DIR__', 'PHP_VERSION'],
            ],
            'array-literals' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'array_ops' => ['list_literal', 'assoc_literal'],
                'builtins' => ['count', 'implode', 'array_sum'],
            ],
            'foreach-loops' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_FOREACH', 'O_COMPOUND_ASSIGN', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_RETURN'],
                'control_flow' => ['foreach_key_value', 'foreach_value_scope'],
            ],
            'for-loops' => [
                'state' => 'executable', 'owner' => $nextTenOwner, 'test' => $nextTenTest,
                'ops' => ['O_ASSIGN', 'O_FOR', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_RETURN'],
                'control_flow' => ['for_init', 'for_condition', 'for_iteration'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function get(string $family): array
    {
        $families = self::all();

        if (!array_key_exists($family, $families)) {
            throw new \RuntimeException("Unknown Oracle execution family: {$family}");
        }

        return $families[$family];
    }
}
