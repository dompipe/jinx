<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executable Oracle family ledger.
 *
 * A family is listed here only when it has a runtime owner and a test that
 * compares Oracle behavior with PHP behavior. Broad PHP constructs may be
 * catalogued elsewhere without being marked executable here.
 */
final class OracleExecutionFamilies
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $expressionTest = 'scripts/test-oracle-next-ten-execution.php';
        $expressionOwner = OracleExpressionBatchExecutor::class;
        $builtinTest = 'scripts/test-oracle-builtin-batch-execution.php';
        $builtinTwoTest = 'scripts/test-oracle-builtin-batch-two-execution.php';
        $builtinOwner = OracleBuiltinBatchExecutor::class;
        $scalarTest = 'scripts/test-oracle-scalar-builtin-execution.php';
        $scalarOwner = OracleScalarBuiltinExecutor::class;

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
            'object-basics' => [
                'state' => 'executable',
                'owner' => OracleObjectExecutor::class,
                'test' => 'scripts/test-oracle-object-basics-execution.php',
                'ops' => ['O_DECLARE', 'O_CLASS_DECL', 'O_METHOD_DECL', 'O_NEW', 'O_METHOD_CALL', 'O_PROPERTY_FETCH', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'],
                'object_ops' => ['class_declaration', 'constructor_call', 'method_call', 'this_property_write', 'this_property_fetch', 'method_return'],
                'builtins' => ['strtoupper'],
            ],

            'ternary-expressions' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_TERNARY', 'O_ECHO', 'O_RETURN'], ['expression_ops' => ['ternary_true_branch', 'ternary_false_branch']]),
            'type-casts' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['casts' => ['int', 'string', 'bool', 'float', 'array']]),
            'string-builtins' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['builtins' => ['strlen', 'strtoupper', 'strtolower', 'trim', 'substr']]),
            'math-builtins' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['builtins' => ['abs', 'max', 'min', 'round']]),
            'comparison-expressions' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<=', '<=>']]),
            'boolean-expressions' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_TERNARY', 'O_ECHO', 'O_RETURN'], ['boolean_operators' => ['&&', '||', '!']]),
            'magic-constants' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['magic_constants' => ['__FILE__', '__DIR__', 'PHP_VERSION']]),
            'array-literals' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['array_ops' => ['list_literal', 'assoc_literal'], 'builtins' => ['count', 'implode', 'array_sum']]),
            'foreach-loops' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_FOREACH', 'O_COMPOUND_ASSIGN', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_RETURN'], ['control_flow' => ['foreach_key_value', 'foreach_value_scope']]),
            'for-loops' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_FOR', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_RETURN'], ['control_flow' => ['for_init', 'for_condition', 'for_iteration']]),

            'str-replace-builtins' => self::builtin($builtinOwner, $builtinTest, ['str_replace']),
            'strpos-builtins' => self::builtin($builtinOwner, $builtinTest, ['strpos']),
            'explode-builtins' => self::builtin($builtinOwner, $builtinTest, ['explode', 'implode', 'count']),
            'in-array-builtins' => self::builtin($builtinOwner, $builtinTest, ['in_array']),
            'array-key-exists-builtins' => self::builtin($builtinOwner, $builtinTest, ['array_key_exists']),
            'array-merge-builtins' => self::builtin($builtinOwner, $builtinTest, ['array_merge', 'implode']),
            'array-reverse-builtins' => self::builtin($builtinOwner, $builtinTest, ['array_reverse', 'implode']),
            'array-unique-builtins' => self::builtin($builtinOwner, $builtinTest, ['array_unique', 'implode', 'count']),
            'json-encode-builtins' => self::builtin($builtinOwner, $builtinTest, ['json_encode']),
            'hash-builtins' => self::builtin($builtinOwner, $builtinTest, ['md5']),

            'ltrim-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['ltrim']),
            'rtrim-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['rtrim']),
            'ucfirst-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['ucfirst']),
            'lcfirst-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['lcfirst']),
            'strrev-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['strrev']),
            'str-repeat-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['str_repeat']),
            'str-pad-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['str_pad']),
            'array-keys-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['array_keys', 'implode']),
            'array-values-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['array_values', 'implode']),
            'array-slice-builtins' => self::builtin($builtinOwner, $builtinTwoTest, ['array_slice', 'implode']),

            'is-string-builtins' => self::builtin($scalarOwner, $scalarTest, ['is_string']),
            'is-int-builtins' => self::builtin($scalarOwner, $scalarTest, ['is_int']),
            'is-array-builtins' => self::builtin($scalarOwner, $scalarTest, ['is_array']),
            'is-bool-builtins' => self::builtin($scalarOwner, $scalarTest, ['is_bool']),
            'is-null-builtins' => self::builtin($scalarOwner, $scalarTest, ['is_null']),
            'intval-builtins' => self::builtin($scalarOwner, $scalarTest, ['intval']),
            'strval-builtins' => self::builtin($scalarOwner, $scalarTest, ['strval']),
            'boolval-builtins' => self::builtin($scalarOwner, $scalarTest, ['boolval']),
            'floatval-builtins' => self::builtin($scalarOwner, $scalarTest, ['floatval']),
            'is-numeric-builtins' => self::builtin($scalarOwner, $scalarTest, ['is_numeric']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function family(string $owner, string $test, array $ops, array $extra = []): array
    {
        return array_merge([
            'state' => 'executable',
            'owner' => $owner,
            'test' => $test,
            'ops' => $ops,
        ], $extra);
    }

    /**
     * @return array<string,mixed>
     */
    private static function builtin(string $owner, string $test, array $builtins): array
    {
        return self::family($owner, $test, ['O_DECLARE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN'], [
            'builtins' => $builtins,
        ]);
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
