<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executable Oracle family ledger. */
final class OracleExecutionFamilies
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $families = [
            'straight-line' => self::family(OracleStraightLineExecutor::class, 'scripts/test-oracle-straightline-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_COMPOUND_ASSIGN', 'O_INC', 'O_DEC', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['builtins' => ['strlen', 'strtoupper']]),
            'string-interpolation' => self::family(OracleStraightLineExecutor::class, 'scripts/test-oracle-string-interpolation-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['string_ops' => ['double_quoted_variable_interpolation', 'braced_variable_interpolation', 'array_offset_interpolation', 'escaped_dollar']]),
            'conditionals' => self::family(OracleConditionalExecutor::class, 'scripts/test-oracle-conditional-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_IF', 'O_ELSE', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_PRINT', 'O_RETURN'], ['comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<='], 'boolean_operators' => ['&&', '||', '!']]),
            'loops' => self::family(OracleLoopExecutor::class, 'scripts/test-oracle-loop-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_IF', 'O_WHILE', 'O_BREAK', 'O_CONTINUE', 'O_BLOCK_CLOSE', 'O_ECHO', 'O_RETURN'], ['control_flow' => ['while', 'break', 'continue']]),
            'arrays' => self::family(OracleArrayExecutor::class, 'scripts/test-oracle-array-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_UNSET', 'O_ECHO', 'O_RETURN'], ['array_ops' => ['literal_empty_array', 'append', 'nested_dimension_assign', 'nested_dimension_fetch', 'isset', 'empty', 'unset'], 'builtins' => ['count']]),
            'functions' => self::family(OracleFunctionExecutor::class, 'scripts/test-oracle-function-execution.php', ['O_DECLARE', 'O_FUNCTION_DECL', 'O_ASSIGN', 'O_ECHO', 'O_RETURN', 'O_BLOCK_CLOSE'], ['function_ops' => ['named_user_function', 'local_parameter_scope', 'return_value', 'nested_user_call', 'builtin_dispatch']]),
            'request-globals' => self::family(OracleRequestExecutor::class, 'scripts/test-oracle-request-globals-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_DIM_FETCH', 'O_COALESCE', 'O_ECHO', 'O_RETURN'], ['superglobals' => ['$_SERVER', '$_GET', '$_POST', '$_REQUEST'], 'request_ops' => ['request_context', 'query_params', 'post_params', 'request_params', 'server_params', 'isset', 'empty']]),
            'include-require' => self::family(OracleIncludeExecutor::class, 'scripts/test-oracle-include-require-execution.php', ['O_DECLARE', 'O_INCLUDE', 'O_REQUIRE', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['loader_ops' => ['literal_include', 'literal_require', 'resolved_oracle_edge', 'included_local_scope', 'included_output']]),
            'exit-die' => self::family(OracleExitExecutor::class, 'scripts/test-oracle-exit-die-execution.php', ['O_DECLARE', 'O_ASSIGN', 'O_ECHO', 'O_EXIT', 'O_RETURN'], ['termination_ops' => ['exit_string_output', 'die_alias', 'termination_flag', 'exit_code', 'unreachable_code_stops']]),
            'object-basics' => self::family(OracleObjectExecutor::class, 'scripts/test-oracle-object-basics-execution.php', ['O_DECLARE', 'O_CLASS_DECL', 'O_METHOD_DECL', 'O_NEW', 'O_METHOD_CALL', 'O_PROPERTY_FETCH', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['object_ops' => ['class_declaration', 'constructor_call', 'method_call', 'this_property_write', 'this_property_fetch', 'method_return'], 'builtins' => ['strtoupper']]),
            'object-inheritance' => self::family(OracleObjectInheritanceExecutor::class, 'scripts/test-oracle-object-inheritance-execution.php', ['O_DECLARE', 'O_CLASS_DECL', 'O_METHOD_DECL', 'O_NEW', 'O_METHOD_CALL', 'O_ASSIGN', 'O_ECHO', 'O_RETURN'], ['object_ops' => ['extends', 'parent_constructor_call', 'method_override', 'inherited_property_state', 'method_dispatch'], 'builtins' => ['strtoupper']]),
        ];

        foreach ([
            'ternary-expressions' => ['expression_ops' => ['ternary_true_branch', 'ternary_false_branch']],
            'type-casts' => ['casts' => ['int', 'string', 'bool', 'float', 'array']],
            'string-builtins' => ['builtins' => ['strlen', 'strtoupper', 'strtolower', 'trim', 'substr']],
            'math-builtins' => ['builtins' => ['abs', 'max', 'min', 'round']],
            'comparison-expressions' => ['comparisons' => ['===', '!==', '==', '!=', '>', '<', '>=', '<=', '<=>']],
            'boolean-expressions' => ['boolean_operators' => ['&&', '||', '!']],
            'magic-constants' => ['magic_constants' => ['__FILE__', '__DIR__', 'PHP_VERSION']],
            'array-literals' => ['array_ops' => ['list_literal', 'assoc_literal'], 'builtins' => ['count', 'implode', 'array_sum']],
            'foreach-loops' => ['control_flow' => ['foreach_key_value', 'foreach_value_scope']],
            'for-loops' => ['control_flow' => ['for_init', 'for_condition', 'for_iteration']],
        ] as $family => $extra) {
            $families[$family] = self::family(OracleExpressionBatchExecutor::class, 'scripts/test-oracle-next-ten-execution.php', ['O_ASSIGN', 'O_ECHO', 'O_RETURN'], $extra);
        }

        foreach ([
            'str-replace-builtins' => ['str_replace'], 'strpos-builtins' => ['strpos'], 'explode-builtins' => ['explode', 'implode', 'count'],
            'in-array-builtins' => ['in_array'], 'array-key-exists-builtins' => ['array_key_exists'], 'array-merge-builtins' => ['array_merge', 'implode'],
            'array-reverse-builtins' => ['array_reverse', 'implode'], 'array-unique-builtins' => ['array_unique', 'implode', 'count'],
            'json-encode-builtins' => ['json_encode'], 'hash-builtins' => ['md5'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleBuiltinBatchExecutor::class, 'scripts/test-oracle-builtin-batch-execution.php', $builtins);
        }

        foreach ([
            'ltrim-builtins' => ['ltrim'], 'rtrim-builtins' => ['rtrim'], 'ucfirst-builtins' => ['ucfirst'], 'lcfirst-builtins' => ['lcfirst'],
            'strrev-builtins' => ['strrev'], 'str-repeat-builtins' => ['str_repeat'], 'str-pad-builtins' => ['str_pad'],
            'array-keys-builtins' => ['array_keys', 'implode'], 'array-values-builtins' => ['array_values', 'implode'], 'array-slice-builtins' => ['array_slice', 'implode'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleBuiltinBatchExecutor::class, 'scripts/test-oracle-builtin-batch-two-execution.php', $builtins);
        }

        foreach ([
            'is-string-builtins' => ['is_string'], 'is-int-builtins' => ['is_int'], 'is-array-builtins' => ['is_array'], 'is-bool-builtins' => ['is_bool'], 'is-null-builtins' => ['is_null'],
            'intval-builtins' => ['intval'], 'strval-builtins' => ['strval'], 'boolval-builtins' => ['boolval'], 'floatval-builtins' => ['floatval'], 'is-numeric-builtins' => ['is_numeric'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleScalarBuiltinExecutor::class, 'scripts/test-oracle-scalar-builtin-execution.php', $builtins);
        }

        foreach ([
            'str-contains-builtins' => ['str_contains'], 'str-starts-with-builtins' => ['str_starts_with'], 'str-ends-with-builtins' => ['str_ends_with'],
            'stripos-builtins' => ['stripos'], 'strrpos-builtins' => ['strrpos'], 'strstr-builtins' => ['strstr'], 'substr-count-builtins' => ['substr_count'],
            'wordwrap-builtins' => ['wordwrap'], 'sprintf-builtins' => ['sprintf'], 'number-format-builtins' => ['number_format'],
            'array-combine-builtins' => ['array_combine', 'json_encode'], 'array-flip-builtins' => ['array_flip', 'json_encode'], 'array-diff-builtins' => ['array_diff', 'json_encode'],
            'array-intersect-builtins' => ['array_intersect', 'json_encode'], 'array-search-builtins' => ['array_search'], 'array-column-builtins' => ['array_column', 'json_encode'],
            'array-chunk-builtins' => ['array_chunk', 'json_encode'], 'range-builtins' => ['range', 'implode'], 'array-change-key-case-builtins' => ['array_change_key_case', 'json_encode'],
            'array-fill-builtins' => ['array_fill', 'json_encode'], 'urlencode-builtins' => ['urlencode'], 'urldecode-builtins' => ['urldecode'],
            'rawurlencode-builtins' => ['rawurlencode'], 'rawurldecode-builtins' => ['rawurldecode'], 'http-build-query-builtins' => ['http_build_query'],
            'parse-url-builtins' => ['parse_url'], 'htmlspecialchars-builtins' => ['htmlspecialchars'], 'html-entity-decode-builtins' => ['html_entity_decode'],
            'strip-tags-builtins' => ['strip_tags'], 'nl2br-builtins' => ['nl2br'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleAppBuiltinExecutor::class, 'scripts/test-oracle-app-builtin-execution.php', $builtins);
        }

        foreach ([
            'floor-builtins' => ['floor'], 'ceil-builtins' => ['ceil'], 'sqrt-builtins' => ['sqrt'], 'pow-builtins' => ['pow'], 'fmod-builtins' => ['fmod'],
            'intdiv-builtins' => ['intdiv'], 'deg2rad-builtins' => ['deg2rad'], 'rad2deg-builtins' => ['rad2deg'], 'sin-builtins' => ['sin'], 'cos-builtins' => ['cos'],
            'tan-builtins' => ['tan'], 'asin-builtins' => ['asin'], 'acos-builtins' => ['acos'], 'atan-builtins' => ['atan'], 'log-builtins' => ['log'],
            'exp-builtins' => ['exp'], 'pi-builtins' => ['pi'], 'hypot-builtins' => ['hypot'], 'is-finite-builtins' => ['is_finite'],
            'is-infinite-builtins' => ['is_infinite'], 'is-nan-builtins' => ['is_nan'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleMathBuiltinExecutor::class, 'scripts/test-oracle-math-builtin-execution.php', $builtins);
        }

        foreach ([
            'base64-encode-builtins' => ['base64_encode'], 'base64-decode-builtins' => ['base64_decode'],
            'bin2hex-builtins' => ['bin2hex'], 'hex2bin-builtins' => ['hex2bin'], 'sha1-builtins' => ['sha1'], 'crc32-builtins' => ['crc32'],
            'hash-generic-builtins' => ['hash'], 'hash-hmac-builtins' => ['hash_hmac'], 'serialize-builtins' => ['serialize'], 'unserialize-builtins' => ['unserialize', 'json_encode'],
            'var-export-builtins' => ['var_export'], 'print-r-builtins' => ['print_r'], 'gettype-builtins' => ['gettype'],
            'is-scalar-builtins' => ['is_scalar'], 'is-countable-builtins' => ['is_countable'], 'sizeof-builtins' => ['sizeof'],
            'array-sum-builtins' => ['array_sum'], 'array-product-builtins' => ['array_product'], 'str-split-builtins' => ['str_split', 'implode'],
            'chunk-split-builtins' => ['chunk_split'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleDataBuiltinExecutor::class, 'scripts/test-oracle-data-builtin-execution.php', $builtins);
        }

        foreach ([
            'join-builtins' => ['join'], 'count-builtins' => ['count'], 'array-count-values-builtins' => ['array_count_values', 'json_encode'],
            'array-pad-builtins' => ['array_pad', 'json_encode'], 'array-replace-builtins' => ['array_replace', 'json_encode'],
            'array-replace-recursive-builtins' => ['array_replace_recursive', 'json_encode'], 'array-is-list-builtins' => ['array_is_list'],
            'array-filter-builtins' => ['array_filter', 'json_encode'], 'quoted-printable-encode-builtins' => ['quoted_printable_encode'],
            'quoted-printable-decode-builtins' => ['quoted_printable_decode'], 'convert-uuencode-builtins' => ['convert_uuencode'],
            'convert-uudecode-builtins' => ['convert_uudecode'], 'pack-builtins' => ['pack', 'bin2hex'], 'unpack-builtins' => ['unpack', 'json_encode'],
            'decbin-builtins' => ['decbin'], 'dechex-builtins' => ['dechex'], 'decoct-builtins' => ['decoct'],
            'bindec-builtins' => ['bindec'], 'hexdec-builtins' => ['hexdec'], 'base-convert-builtins' => ['base_convert'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleDataBuiltinExecutor::class, 'scripts/test-oracle-data-builtin-two-execution.php', $builtins);
        }

        foreach ([
            'chr-builtins' => ['chr'], 'ord-builtins' => ['ord'], 'strcmp-builtins' => ['strcmp'], 'strcasecmp-builtins' => ['strcasecmp'],
            'strncmp-builtins' => ['strncmp'], 'strncasecmp-builtins' => ['strncasecmp'], 'substr-compare-builtins' => ['substr_compare'],
            'similar-text-builtins' => ['similar_text'], 'levenshtein-builtins' => ['levenshtein'], 'soundex-builtins' => ['soundex'],
            'metaphone-builtins' => ['metaphone'], 'str-rot13-builtins' => ['str_rot13'], 'addslashes-builtins' => ['addslashes'],
            'stripslashes-builtins' => ['stripslashes'], 'quotemeta-builtins' => ['quotemeta'], 'addcslashes-builtins' => ['addcslashes'],
            'substr-replace-builtins' => ['substr_replace'], 'strtr-builtins' => ['strtr'], 'str-getcsv-builtins' => ['str_getcsv', 'json_encode'],
            'str-word-count-builtins' => ['str_word_count'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleTextBuiltinExecutor::class, 'scripts/test-oracle-text-builtin-execution.php', $builtins);
        }

        foreach ([
            'strlen-builtins' => ['strlen'], 'strtolower-builtins' => ['strtolower'], 'strtoupper-builtins' => ['strtoupper'],
            'trim-builtins' => ['trim'], 'substr-builtins' => ['substr'], 'basename-builtins' => ['basename'], 'dirname-builtins' => ['dirname'],
            'ucwords-builtins' => ['ucwords'], 'stripcslashes-builtins' => ['stripcslashes'], 'ctype-alnum-builtins' => ['ctype_alnum'],
            'ctype-alpha-builtins' => ['ctype_alpha'], 'ctype-cntrl-builtins' => ['ctype_cntrl'], 'ctype-digit-builtins' => ['ctype_digit'],
            'ctype-graph-builtins' => ['ctype_graph'], 'ctype-lower-builtins' => ['ctype_lower'], 'ctype-print-builtins' => ['ctype_print'],
            'ctype-punct-builtins' => ['ctype_punct'], 'ctype-space-builtins' => ['ctype_space'], 'ctype-upper-builtins' => ['ctype_upper'],
            'ctype-xdigit-builtins' => ['ctype_xdigit'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleTextBuiltinExecutor::class, 'scripts/test-oracle-text-builtin-two-execution.php', $builtins);
        }

        foreach ([
            'date-builtins' => ['date'], 'gmdate-builtins' => ['gmdate'], 'strtotime-builtins' => ['strtotime'],
            'mktime-builtins' => ['mktime'], 'gmmktime-builtins' => ['gmmktime'], 'checkdate-builtins' => ['checkdate'],
            'idate-builtins' => ['idate'], 'getdate-builtins' => ['getdate', 'json_encode'], 'localtime-builtins' => ['localtime', 'json_encode'],
            'date-parse-builtins' => ['date_parse', 'json_encode'], 'date-parse-from-format-builtins' => ['date_parse_from_format', 'json_encode'],
            'timezone-name-from-abbr-builtins' => ['timezone_name_from_abbr'], 'timezone-version-get-builtins' => ['timezone_version_get'],
            'timezone-open-builtins' => ['timezone_open', 'timezone_name_get'], 'timezone-name-get-builtins' => ['timezone_name_get', 'timezone_open'],
            'date-create-builtins' => ['date_create', 'date_format'], 'date-format-builtins' => ['date_format', 'date_create'],
            'date-timestamp-get-builtins' => ['date_timestamp_get', 'date_create'],
            'date-timezone-get-builtins' => ['date_timezone_get', 'timezone_name_get', 'date_create'],
            'timezone-offset-get-builtins' => ['timezone_offset_get', 'timezone_open', 'date_create'],
        ] as $family => $builtins) {
            $families[$family] = self::builtin(OracleDateTimeBuiltinExecutor::class, 'scripts/test-oracle-date-time-builtin-execution.php', $builtins);
        }

        return $families;
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
