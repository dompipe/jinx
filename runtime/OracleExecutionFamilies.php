<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executable Oracle family ledger. */
final class OracleExecutionFamilies
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $expressionTest = 'scripts/test-oracle-next-ten-execution.php';
        $expressionOwner = OracleExpressionBatchExecutor::class;
        $builtinTest = 'scripts/test-oracle-builtin-batch-execution.php';
        $builtinTwoTest = 'scripts/test-oracle-builtin-batch-two-execution.php';
        $builtinOwner = OracleBuiltinBatchExecutor::class;
        $scalarTest = 'scripts/test-oracle-scalar-builtin-execution.php';
        $scalarOwner = OracleScalarBuiltinExecutor::class;
        $appTest = 'scripts/test-oracle-app-builtin-execution.php';
        $appOwner = OracleAppBuiltinExecutor::class;

        return [
            'straight-line' => self::family(OracleStraightLineExecutor::class, 'scripts/test-oracle-straightline-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['builtins' => ['strlen', 'strtoupper']]),
            'conditionals' => self::family(OracleConditionalExecutor::class, 'scripts/test-oracle-conditional-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_IF', 'O_ELSE', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<='], 'boolean_operators' => ['&&', '||', '!'], 'builtins' => ['strlen', 'strtoupper']]),
            'loops' => self::family(OracleLoopExecutor::class, 'scripts/test-oracle-loop-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_IF', 'O_ELSE', 'O_WHILE', 'O_BREAK', 'O_CONTINUE', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['control_flow' => ['while', 'break', 'continue'], 'comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<='], 'boolean_operators' => ['&&', '||', '!'], 'builtins' => ['strlen', 'strtoupper']]),
            'arrays' => self::family(OracleArrayExecutor::class, 'scripts/test-oracle-array-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_UNSET', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['array_ops' => ['literal_empty_array', 'append', 'nested_dimension_assign', 'nested_dimension_fetch', 'isset', 'empty', 'unset'], 'builtins' => ['count']]),
            'functions' => self::family(OracleFunctionExecutor::class, 'scripts/test-oracle-function-execution.php', ['O_DECLARE', 'O_FUNCTION_DECL', 'O_ASSIGN', 'O_COMPOUND_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN', 'O_BLOCK_CLOSE'], ['function_ops' => ['named_user_function', 'local_parameter_scope', 'return_value', 'nested_user_call', 'builtin_dispatch'], 'builtins' => ['strlen', 'strtoupper']]),
            'request-globals' => self::family(OracleRequestExecutor::class, 'scripts/test-oracle-request-globals-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['superglobals' => ['$_SERVER', '$_GET', '$_POST', '$_REQUEST'], 'request_ops' => ['request_context', 'query_params', 'post_params', 'request_params', 'server_params', 'isset', 'empty'], 'builtins' => ['count']]),
            'include-require' => self::family(OracleIncludeExecutor::class, 'scripts/test-oracle-include-require-execution.php', ['O_DECLARE', 'O_INCLUDE', 'O_REQUIRE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['loader_ops' => ['literal_include', 'literal_require', 'resolved_oracle_edge', 'included_local_scope', 'included_output']]),
            'exit-die' => self::family(OracleExitExecutor::class, 'scripts/test-oracle-exit-die-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_EXIT', 'O_RETURN'], ['termination_ops' => ['exit_string_output', 'die_alias', 'termination_flag', 'exit_code', 'unreachable_code_stops']]),
            'object-basics' => self::family(OracleObjectExecutor::class, 'scripts/test-oracle-object-basics-execution.php', ['O_DECLARE', 'O_CLASS_DECL', 'O_METHOD_DECL', 'O_NEW', 'O_METHOD_CALL', 'O_PROPERTY_FETCH', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['object_ops' => ['class_declaration', 'constructor_call', 'method_call', 'this_property_write', 'this_property_fetch', 'method_return'], 'builtins' => ['strtoupper']]),
            'object-inheritance' => self::family(OracleObjectInheritanceExecutor::class, 'scripts/test-oracle-object-inheritance-execution.php', ['O_DECLARE', 'O_CLASS_DECL', 'O_METHOD_DECL', 'O_NEW', 'O_METHOD_CALL', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['object_ops' => ['extends', 'parent_constructor_call', 'method_override', 'inherited_property_state', 'method_dispatch'], 'builtins' => ['strtoupper']]),

            'ternary-expressions' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_TERNARY', 'O_ECHO', 'O_RETURN'], ['expression_ops' => ['ternary_true_branch', 'ternary_false_branch']]),
            'type-casts' => self::family($expressionOwner, $expressionTest, ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['casts' => ['int', 'string', 'bool', 'float', 'array']]),
            'string-builtins' => self::builtin($expressionOwner, $expressionTest, ['strlen', 'strtoupper', 'strtolower', 'trim', 'substr']),
            'math-builtins' => self::builtin($expressionOwner, $expressionTest, ['abs', 'max', 'min', 'round']),
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

            'str-contains-builtins' => self::builtin($appOwner, $appTest, ['str_contains']),
            'str-starts-with-builtins' => self::builtin($appOwner, $appTest, ['str_starts_with']),
            'str-ends-with-builtins' => self::builtin($appOwner, $appTest, ['str_ends_with']),
            'stripos-builtins' => self::builtin($appOwner, $appTest, ['stripos']),
            'strrpos-builtins' => self::builtin($appOwner, $appTest, ['strrpos']),
            'strstr-builtins' => self::builtin($appOwner, $appTest, ['strstr']),
            'substr-count-builtins' => self::builtin($appOwner, $appTest, ['substr_count']),
            'wordwrap-builtins' => self::builtin($appOwner, $appTest, ['wordwrap']),
            'sprintf-builtins' => self::builtin($appOwner, $appTest, ['sprintf']),
            'number-format-builtins' => self::builtin($appOwner, $appTest, ['number_format']),
            'urlencode-builtins' => self::builtin($appOwner, $appTest, ['urlencode']),
            'urldecode-builtins' => self::builtin($appOwner, $appTest, ['urldecode']),
            'rawurlencode-builtins' => self::builtin($appOwner, $appTest, ['rawurlencode']),
            'rawurldecode-builtins' => self::builtin($appOwner, $appTest, ['rawurldecode']),
            'http-build-query-builtins' => self::builtin($appOwner, $appTest, ['http_build_query']),
            'parse-url-builtins' => self::builtin($appOwner, $appTest, ['parse_url']),
            'htmlspecialchars-builtins' => self::builtin($appOwner, $appTest, ['htmlspecialchars']),
            'html-entity-decode-builtins' => self::builtin($appOwner, $appTest, ['html_entity_decode']),
            'strip-tags-builtins' => self::builtin($appOwner, $appTest, ['strip_tags']),
            'nl2br-builtins' => self::builtin($appOwner, $appTest, ['nl2br']),
            'array-combine-builtins' => self::builtin($appOwner, $appTest, ['array_combine', 'json_encode']),
            'array-flip-builtins' => self::builtin($appOwner, $appTest, ['array_flip', 'json_encode']),
            'array-diff-builtins' => self::builtin($appOwner, $appTest, ['array_diff', 'json_encode']),
            'array-intersect-builtins' => self::builtin($appOwner, $appTest, ['array_intersect', 'json_encode']),
            'array-search-builtins' => self::builtin($appOwner, $appTest, ['array_search']),
            'array-column-builtins' => self::builtin($appOwner, $appTest, ['array_column', 'json_encode']),
            'array-chunk-builtins' => self::builtin($appOwner, $appTest, ['array_chunk', 'json_encode']),
            'range-builtins' => self::builtin($appOwner, $appTest, ['range', 'implode']),
            'array-change-key-case-builtins' => self::builtin($appOwner, $appTest, ['array_change_key_case', 'json_encode']),
            'array-fill-builtins' => self::builtin($appOwner, $appTest, ['array_fill', 'json_encode']),
        ];
    }

    /** @return array<string,mixed> */
    private static function family(string $owner, string $test, array $ops, array $extra = []): array
    {
        return array_merge(['state' => 'executable', 'owner' => $owner, 'test' => $test, 'ops' => $ops], $extra);
    }

    /** @return array<string,mixed> */
    private static function builtin(string $owner, string $test, array $builtins): array
    {
        return self::family($owner, $test, ['O_DECLARE', 'O_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['builtins' => $builtins]);
    }

    /** @return array<string,mixed> */
    public static function get(string $family): array
    {
        $families = self::all();
        if (!array_key_exists($family, $families)) {
            throw new \RuntimeException("Unknown Oracle execution family: {$family}");
        }
        return $families[$family];
    }
}
