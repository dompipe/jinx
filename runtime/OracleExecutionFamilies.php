<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executable Oracle family ledger.
 *
 * This keeps the transition from "recorded in Oracle" to "executed by Oracle"
 * explicit. A family is listed here only when it has a runtime owner and a test
 * that compares Oracle behavior with PHP behavior.
 */
final class OracleExecutionFamilies
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            'straight-line' => [
                'state' => 'executable',
                'owner' => OracleStraightLineExecutor::class,
                'test' => 'scripts/test-oracle-straightline-execution.php',
                'ops' => [
                    'O_DECLARE',
                    'O_ASSIGN',
                    'O_DIM_ASSIGN',
                    'O_DIM_FETCH',
                    'O_COALESCE',
                    'O_COMPOUND_ASSIGN',
                    'O_INC',
                    'O_DEC',
                    'O_ECHO',
                    'O_PRINT',
                    'O_RETURN',
                ],
                'builtins' => [
                    'strlen',
                    'strtoupper',
                ],
            ],
            'conditionals' => [
                'state' => 'executable',
                'owner' => OracleConditionalExecutor::class,
                'test' => 'scripts/test-oracle-conditional-execution.php',
                'ops' => [
                    'O_DECLARE',
                    'O_ASSIGN',
                    'O_DIM_ASSIGN',
                    'O_DIM_FETCH',
                    'O_COALESCE',
                    'O_COMPOUND_ASSIGN',
                    'O_INC',
                    'O_DEC',
                    'O_IF',
                    'O_ELSE',
                    'O_BLOCK_CLOSE',
                    'O_ECHO',
                    'O_PRINT',
                    'O_RETURN',
                ],
                'comparisons' => [
                    '===',
                    '!==',
                    '==',
                    '!=',
                    '>',
                    '<',
                    '>=',
                    '<=',
                ],
                'boolean_operators' => [
                    '&&',
                    '||',
                    '!',
                ],
                'builtins' => [
                    'strlen',
                    'strtoupper',
                ],
            ],
            'loops' => [
                'state' => 'executable',
                'owner' => OracleLoopExecutor::class,
                'test' => 'scripts/test-oracle-loop-execution.php',
                'ops' => [
                    'O_DECLARE',
                    'O_ASSIGN',
                    'O_DIM_ASSIGN',
                    'O_DIM_FETCH',
                    'O_COALESCE',
                    'O_COMPOUND_ASSIGN',
                    'O_INC',
                    'O_DEC',
                    'O_IF',
                    'O_ELSE',
                    'O_WHILE',
                    'O_BREAK',
                    'O_CONTINUE',
                    'O_BLOCK_CLOSE',
                    'O_ECHO',
                    'O_PRINT',
                    'O_RETURN',
                ],
                'control_flow' => [
                    'while',
                    'break',
                    'continue',
                ],
                'comparisons' => [
                    '===',
                    '!==',
                    '==',
                    '!=',
                    '>',
                    '<',
                    '>=',
                    '<=',
                ],
                'boolean_operators' => [
                    '&&',
                    '||',
                    '!',
                ],
                'builtins' => [
                    'strlen',
                    'strtoupper',
                ],
            ],
            'arrays' => [
                'state' => 'executable',
                'owner' => OracleArrayExecutor::class,
                'test' => 'scripts/test-oracle-array-execution.php',
                'ops' => [
                    'O_DECLARE',
                    'O_ASSIGN',
                    'O_DIM_ASSIGN',
                    'O_DIM_FETCH',
                    'O_COALESCE',
                    'O_UNSET',
                    'O_ECHO',
                    'O_PRINT',
                    'O_RETURN',
                ],
                'array_ops' => [
                    'literal_empty_array',
                    'append',
                    'nested_dimension_assign',
                    'nested_dimension_fetch',
                    'isset',
                    'empty',
                    'unset',
                ],
                'builtins' => [
                    'count',
                ],
            ],
            'functions' => [
                'state' => 'executable',
                'owner' => OracleFunctionExecutor::class,
                'test' => 'scripts/test-oracle-function-execution.php',
                'ops' => [
                    'O_DECLARE',
                    'O_FUNCTION_DECL',
                    'O_ASSIGN',
                    'O_COMPOUND_ASSIGN',
                    'O_ECHO',
                    'O_PRINT',
                    'O_RETURN',
                    'O_BLOCK_CLOSE',
                ],
                'function_ops' => [
                    'named_user_function',
                    'local_parameter_scope',
                    'return_value',
                    'nested_user_call',
                    'builtin_dispatch',
                ],
                'builtins' => [
                    'strlen',
                    'strtoupper',
                ],
            ],
            'request-globals' => [
                'state' => 'executable',
                'owner' => OracleRequestExecutor::class,
                'test' => 'scripts/test-oracle-request-globals-execution.php',
                'ops' => [
                    'O_DECLARE',
                    'O_ASSIGN',
                    'O_DIM_FETCH',
                    'O_COALESCE',
                    'O_ECHO',
                    'O_PRINT',
                    'O_RETURN',
                ],
                'superglobals' => [
                    '$_SERVER',
                    '$_GET',
                    '$_POST',
                    '$_REQUEST',
                ],
                'request_ops' => [
                    'request_context',
                    'query_params',
                    'post_params',
                    'request_params',
                    'server_params',
                    'isset',
                    'empty',
                ],
                'builtins' => [
                    'count',
                ],
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
