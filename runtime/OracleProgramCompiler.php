<?php

declare(strict_types=1);

namespace jinx\oracle;

require_once __DIR__ . '/PhpToJinxLowerer.php';
require_once __DIR__ . '/CoalescedOracleCompiler.php';
require_once __DIR__ . '/OracleStraightLineExecutor.php';

use jinx\lowering\PhpToJinxLowerer;

/**
 * Canonical Oracle interpreter entrypoint.
 *
 * This file makes the architecture explicit:
 *
 *   PHP source
 *   -> Oracle interpreter statement stream, including literal local require/include edges
 *   -> executable coalesced Oracle ops only when supported
 *
 * The Oracle layer is an interpreter/mirroring path, not a source compiler.
 * It is allowed to represent any PHP as Oracle statements.
 * It is NOT allowed to pretend unsupported PHP is executable.
 *
 * The class name is retained for older scripts, but new code should treat
 * Oracle as interpretation, not compilation.
 */
final class OracleProgramCompiler
{
    /**
     * Compile the currently-supported executable PHP subset into coalesced Oracle ops.
     *
     * @return array{jinx:string, ops:list<array<string,mixed>>}
     */
    public static function compileExecutablePhpFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Missing PHP source file: {$path}");
        }

        $jinx = PhpToJinxLowerer::lowerFile($path);
        $ops = CoalescedOracleCompiler::compileJinx($jinx);

        return [
            'jinx' => $jinx,
            'ops' => $ops,
        ];
    }

    /**
     * Turn any PHP file into Oracle-level statement records.
     *
     * This is the broad interpreter front door.
     *
     * For unsupported PHP, it still emits Oracle statements like:
     *
     *   O_FUNCTION_DECL
     *   O_IF
     *   O_RETURN
     *   O_CALL
     *   O_REQUIRE / O_INCLUDE
     *   O_CURL_CALL
     *   O_JSON_CALL
     *   O_CLASS_DECL / O_METHOD_DECL / O_PROPERTY_DECL
     *   O_NAMESPACE / O_USE / O_TRAIT_DECL / O_INTERFACE_DECL / O_ENUM_DECL / O_ENUM_CASE
     *   O_SWITCH / O_MATCH / O_GLOBAL / O_STATIC_LOCAL
     *   O_NEW / O_METHOD_CALL / O_STATIC_CALL / O_STATIC_PROPERTY_ASSIGN / O_STATIC_PROPERTY_FETCH / O_PROPERTY_ASSIGN / O_PROPERTY_FETCH
     *   O_ECHO / O_PRINT / O_EXIT / O_CLOSURE / O_ARROW_FUNCTION
     *   O_DIM_ASSIGN / O_DIM_FETCH / O_DESTRUCTURE_ASSIGN / O_COALESCE_ASSIGN / O_COALESCE / O_TERNARY
     *   O_INC / O_DEC / O_COMPOUND_ASSIGN / O_YIELD_FROM / O_YIELD / O_GOTO / O_LABEL
     *   O_CONST_DECL / O_CLASS_CONST / O_CATCH / O_FINALLY
     *   O_RAW_PHP_STMT
     *
     * That means Oracle can see and catalog the program without
     * claiming it can execute every statement yet.
     *
     * @return array<string,mixed>
     */
    public static function compileAnyPhpFileToOracleProgram(string $path, array $seen = []): array
    {
        return self::interpretAnyPhpFileToOracleProgram($path, $seen);
    }

    /**
     * Interpret any PHP file into Oracle-level statement records.
     *
     * This is the broad interpreter front door.
     *
     * @return array<string,mixed>
     */
    public static function interpretAnyPhpFileToOracleProgram(string $path, array $seen = []): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Missing PHP source file: {$path}");
        }

        $realPath = realpath($path) ?: $path;
        $source = (string) file_get_contents($path);
        $seen[$realPath] = true;

        $oracleStatements = self::sourceToOracleStatements($source, dirname($realPath), $seen);

        $executable = null;
        $executableError = null;

        try {
            $executable = self::compileExecutablePhpFile($path);
        } catch (\Throwable $e) {
            $executableError = $e->getMessage();
        }

        return [
            'kind' => 'JINX_ORACLE_PROGRAM',
            'source_file' => $path,
            'source_realpath' => $realPath,
            'source_sha1' => sha1($source),
            'statement_count' => count($oracleStatements),
            'statements' => $oracleStatements,
            'executable' => $executable !== null,
            'executable_error' => $executableError,
            'coalesced_ops' => $executable['ops'] ?? [],
            'jinx' => $executable['jinx'] ?? null,
        ];
    }

    /**
     * Execute the currently-supported straight-line Oracle statement subset.
     *
     * This is intentionally narrow. Unsupported statements throw instead of
     * falling through to PHP or pretending the whole Zend surface is executable.
     *
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function executeSupportedPhpFileInOracle(string $path): array
    {
        $program = self::interpretAnyPhpFileToOracleProgram($path);

        return self::executeSupportedOracleProgram($program);
    }

    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function executeSupportedOracleProgram(array $program): array
    {
        return OracleStraightLineExecutor::execute($program);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function sourceToOracleStatements(string $source, string $baseDir, array $seen): array
    {
        $tokens = token_get_all($source);
        $chunks = [];
        $current = '';
        $braceDepth = 0;
        $parenDepth = 0;
        $bracketDepth = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }

            $current .= $text;

            $length = strlen($text);
            for ($i = 0; $i < $length; $i++) {
                $ch = $text[$i];

                if ($ch === '(') {
                    $parenDepth++;
                    continue;
                }
                if ($ch === ')') {
                    $parenDepth = max(0, $parenDepth - 1);
                    continue;
                }
                if ($ch === '[') {
                    $bracketDepth++;
                    continue;
                }
                if ($ch === ']') {
                    $bracketDepth = max(0, $bracketDepth - 1);
                    continue;
                }
                if ($ch === '{' && $parenDepth === 0 && $bracketDepth === 0) {
                    $braceDepth++;
                    self::pushChunk($chunks, $current);
                    $current = '';
                    continue 2;
                }
                if ($ch === '}' && $parenDepth === 0 && $bracketDepth === 0) {
                    $braceDepth = max(0, $braceDepth - 1);
                    self::pushChunk($chunks, $current);
                    $current = '';
                    continue 2;
                }
                if ($ch === ';' && $parenDepth === 0 && $bracketDepth === 0) {
                    self::pushChunk($chunks, $current);
                    $current = '';
                    continue 2;
                }
            }
        }

        self::pushChunk($chunks, $current);

        $statements = [];
        $index = 0;

        foreach ($chunks as $chunk) {
            $normalized = self::normalize($chunk);

            if ($normalized === '') {
                continue;
            }

            $statements[] = self::classifyStatement($index, $chunk, $normalized, $baseDir, $seen);
            $index++;
        }

        return $statements;
    }

    /**
     * @param list<string> $chunks
     */
    private static function pushChunk(array &$chunks, string $chunk): void
    {
        $trimmed = trim($chunk);

        if ($trimmed !== '') {
            $chunks[] = $trimmed;
        }
    }

    private static function normalize(string $source): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $source));
    }

    /**
     * @return array<string,mixed>
     */
    private static function classifyStatement(int $index, string $raw, string $normalized, string $baseDir, array $seen): array
    {
        $kind = 'O_RAW_PHP_STMT';
        $features = [];
        $extra = [];

        $identifier = '[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*';

        $attributes = self::extractAttributeNames($normalized);
        if ($attributes !== []) {
            $features['attributes'] = $attributes;
            $features['php_attribute'] = true;
        }

        if (preg_match('/^declare\s*\(/i', $normalized)) {
            $kind = 'O_DECLARE';
        } elseif (preg_match('/^(?:#\[[^\]]+\]\s*)*const\s+([A-Za-z_]\w*)\b/i', $normalized, $m)) {
            $kind = 'O_CONST_DECL';
            $features['name'] = $m[1];
        } elseif (preg_match('/^namespace\s+(' . $identifier . ')\s*(?:;|\{)?$/i', $normalized, $m)) {
            $kind = 'O_NAMESPACE';
            $features['namespace'] = $m[1];
            $features['namespace_block'] = str_ends_with($normalized, '{');
        } elseif (preg_match('/^use\s+(.+);?$/i', $normalized, $m)) {
            $kind = 'O_USE';
            $features['use_target'] = rtrim($m[1], ';');
        } elseif (preg_match('/^(?:#\[[^\]]+\]\s*)*interface\s+(' . $identifier . ')\b/i', $normalized, $m)) {
            $kind = 'O_INTERFACE_DECL';
            $features['name'] = $m[1];
            self::addDeclarationFeatures($normalized, $features);
        } elseif (preg_match('/^(?:#\[[^\]]+\]\s*)*trait\s+(' . $identifier . ')\b/i', $normalized, $m)) {
            $kind = 'O_TRAIT_DECL';
            $features['name'] = $m[1];
            self::addDeclarationFeatures($normalized, $features);
        } elseif (preg_match('/^(?:#\[[^\]]+\]\s*)*enum\s+(' . $identifier . ')(?:\s*:\s*(string|int))?\b/i', $normalized, $m)) {
            $kind = 'O_ENUM_DECL';
            $features['name'] = $m[1];
            if (isset($m[2]) && $m[2] !== '') {
                $features['backing_type'] = strtolower($m[2]);
            }
            self::addDeclarationFeatures($normalized, $features);
        } elseif (preg_match('/^(?:#\[[^\]]+\]\s*)*(?:(?:abstract|final|readonly)\s+)*class\s+(' . $identifier . ')\b/i', $normalized, $m)) {
            $kind = 'O_CLASS_DECL';
            $features['name'] = $m[1];
            self::addDeclarationFeatures($normalized, $features);
        } elseif (preg_match('/^(?:#\[[^\]]+\]\s*)*(?:(?:public|protected|private|static|abstract|final|readonly)\s+)*function\s+(\w+)\s*\(/i', $normalized, $m)) {
            $kind = preg_match('/^(?:public|protected|private|static|abstract|final|readonly)\b/i', $normalized) ? 'O_METHOD_DECL' : 'O_FUNCTION_DECL';
            $features['name'] = $m[1];
            self::addDeclarationFeatures($normalized, $features);
            self::addFunctionSignatureFeatures($normalized, $features);
        } elseif (preg_match('/^static\s+\$\w+/i', $normalized)) {
            $kind = 'O_STATIC_LOCAL';
        } elseif (preg_match('/^(?:#\[[^\]]+\]\s*)*(?:(?:public|protected|private|static|readonly)\s+)+(?:(\??' . $identifier . '(?:\s*[|&]\s*\??' . $identifier . ')*)\s+)?\$(\w+)/i', $normalized, $m)) {
            $kind = 'O_PROPERTY_DECL';
            $features['name'] = $m[2];
            if (isset($m[1]) && trim($m[1]) !== '') {
                $features['declared_type'] = preg_replace('/\s+/', '', trim($m[1]));
                $features['union_property_type'] = str_contains((string) $features['declared_type'], '|');
                $features['intersection_property_type'] = str_contains((string) $features['declared_type'], '&');
                $features['nullable_property_type'] = str_starts_with((string) $features['declared_type'], '?');
            }
            self::addDeclarationFeatures($normalized, $features);
        } elseif (preg_match('/^(?:(?:public|protected|private|final)\s+)*const\s+([A-Za-z_]\w*)\b/i', $normalized, $m)) {
            $kind = 'O_CLASS_CONST';
            $features['name'] = $m[1];
            self::addDeclarationFeatures($normalized, $features);
        } elseif (preg_match('/^function\s+(\w+)\s*\(/i', $normalized, $m)) {
            $kind = 'O_FUNCTION_DECL';
            $features['name'] = $m[1];
            self::addFunctionSignatureFeatures($normalized, $features);
        } elseif (preg_match('/^(require_once|require|include_once|include)\s*(?:\(\s*)?([\'"])([^\'"]+)\2\s*\)?\s*;?$/i', $normalized, $m)) {
            $loader = strtolower($m[1]);
            [$kind, $features, $extra] = self::classifyLoaderStatement($loader, $m[3], $baseDir, $seen, true);
        } elseif (preg_match('/^(require_once|require|include_once|include)\b/i', $normalized, $m)) {
            [$kind, $features, $extra] = self::classifyLoaderStatement(strtolower($m[1]), null, $baseDir, $seen, false);
        } elseif (preg_match('/^echo\b/i', $normalized)) {
            $kind = 'O_ECHO';
        } elseif (preg_match('/^print\b/i', $normalized)) {
            $kind = 'O_PRINT';
        } elseif (preg_match('/^(exit|die)\s*(?:\(|;)/i', $normalized, $m)) {
            $kind = 'O_EXIT';
            $features['exit_alias'] = strtolower($m[1]);
        } elseif (preg_match('/^goto\s+([A-Za-z_]\w*)\s*;?$/i', $normalized, $m)) {
            $kind = 'O_GOTO';
            $features['label'] = $m[1];
        } elseif (preg_match('/^([A-Za-z_]\w*)\s*:\s*;?$/', $normalized, $m)) {
            $kind = 'O_LABEL';
            $features['label'] = $m[1];
        } elseif (preg_match('/^if\s*\(/i', $normalized)) {
            $kind = 'O_IF';
        } elseif (preg_match('/^else\b/i', $normalized)) {
            $kind = 'O_ELSE';
        } elseif (preg_match('/^catch\s*\(([^)]+)\)/i', $normalized, $m)) {
            $kind = 'O_CATCH';
            $features['catch_signature'] = trim($m[1]);
            if (preg_match('/^(.+?)\s+\$(\w+)$/', trim($m[1]), $catch)) {
                $features['catch_type'] = trim($catch[1]);
                $features['catch_variable'] = $catch[2];
            }
        } elseif (preg_match('/^finally\b/i', $normalized)) {
            $kind = 'O_FINALLY';
        } elseif (preg_match('/^switch\s*\(/i', $normalized)) {
            $kind = 'O_SWITCH';
        } elseif (preg_match('/\bmatch\s*\(/i', $normalized)) {
            $kind = 'O_MATCH';
        } elseif (preg_match('/^case\s+([A-Za-z_]\w*)\s*(?:=\s*(.+))?;?$/i', $normalized, $m)) {
            $kind = 'O_ENUM_CASE';
            $features['name'] = $m[1];
            if (isset($m[2]) && trim($m[2]) !== '') {
                $features['backed_value_source'] = rtrim(trim($m[2]), ';');
            }
        } elseif (preg_match('/^case\b/i', $normalized)) {
            $kind = 'O_CASE';
        } elseif (preg_match('/^default\s*:/i', $normalized)) {
            $kind = 'O_DEFAULT';
        } elseif (preg_match('/^do\b/i', $normalized)) {
            $kind = 'O_DO';
        } elseif (preg_match('/^for\s*\(/i', $normalized)) {
            $kind = 'O_FOR';
        } elseif (preg_match('/^foreach\s*\(/i', $normalized)) {
            $kind = 'O_FOREACH';
        } elseif (preg_match('/^while\s*\(/i', $normalized)) {
            $kind = 'O_WHILE';
        } elseif (preg_match('/^break\b/i', $normalized)) {
            $kind = 'O_BREAK';
        } elseif (preg_match('/^continue\b/i', $normalized)) {
            $kind = 'O_CONTINUE';
        } elseif (preg_match('/^return\b/i', $normalized)) {
            $kind = 'O_RETURN';
        } elseif (preg_match('/^global\s+\$/i', $normalized)) {
            $kind = 'O_GLOBAL';
        } elseif (preg_match('/^unset\s*\(/i', $normalized)) {
            $kind = 'O_UNSET';
        } elseif (preg_match('/^isset\s*\(/i', $normalized)) {
            $kind = 'O_ISSET';
        } elseif (preg_match('/^empty\s*\(/i', $normalized)) {
            $kind = 'O_EMPTY';
        } elseif (preg_match('/^throw\b/i', $normalized)) {
            $kind = 'O_THROW';
        } elseif (preg_match('/\byield\s+from\b/i', $normalized)) {
            $kind = 'O_YIELD_FROM';
        } elseif (preg_match('/\byield\b/i', $normalized)) {
            $kind = 'O_YIELD';
        } elseif (preg_match('/\?\?=/', $normalized)) {
            $kind = 'O_COALESCE_ASSIGN';
        } elseif (preg_match('/\?\?/', $normalized)) {
            $kind = 'O_COALESCE';
        } elseif (preg_match('/^\$\w+(?:\[[^\]]*\])+\s*=/', $normalized)) {
            $kind = 'O_DIM_ASSIGN';
        } elseif (preg_match('/\?.*:/', $normalized)) {
            $kind = 'O_TERNARY';
        } elseif (preg_match('/\bfn\s*\(/i', $normalized)) {
            $kind = 'O_ARROW_FUNCTION';
        } elseif (preg_match('/\bfunction\s*\(/i', $normalized)) {
            $kind = 'O_CLOSURE';
        } elseif (preg_match('/\bnew\s+class\b/i', $normalized)) {
            $kind = 'O_ANON_CLASS';
        } elseif (preg_match('/\bclone\s+\$/i', $normalized)) {
            $kind = 'O_CLONE';
        } elseif (preg_match('/\binstanceof\s+' . $identifier . '\b/i', $normalized, $m)) {
            $kind = 'O_INSTANCEOF';
            $features['class'] = preg_replace('/^instanceof\s+/i', '', $m[0]);
        } elseif (preg_match('/\bnew\s+(' . $identifier . ')\b/i', $normalized, $m)) {
            $kind = 'O_NEW';
            $features['class'] = $m[1];
        } elseif (preg_match('/' . $identifier . '::\w+\s*\(/', $normalized)) {
            $kind = 'O_STATIC_CALL';
        } elseif (preg_match('/^(' . $identifier . ')::\$(\w+)\s*(?:=|\+=|-=|\*=|\/=|%=|\.=)/', $normalized, $m)) {
            $kind = 'O_STATIC_PROPERTY_ASSIGN';
            $features['class'] = $m[1];
            $features['property'] = $m[2];
            $features['compound_assignment'] = !preg_match('/^' . $identifier . '::\$\w+\s*=/', $normalized);
        } elseif (preg_match('/' . $identifier . '::\$\w+\b/', $normalized)) {
            $kind = 'O_STATIC_PROPERTY_FETCH';
        } elseif (preg_match('/\?->\w+\s*\(/', $normalized)) {
            $kind = 'O_NULLSAFE_CALL';
        } elseif (preg_match('/\?->\w+\b/', $normalized)) {
            $kind = 'O_NULLSAFE_PROPERTY_FETCH';
        } elseif (preg_match('/^\$\w+->(\w+)\s*(?:=|\+=|-=|\*=|\/=|%=|\.=)/', $normalized, $m)) {
            $kind = 'O_PROPERTY_ASSIGN';
            $features['property'] = $m[1];
        } elseif (preg_match('/->\w+\s*\(/', $normalized)) {
            $kind = 'O_METHOD_CALL';
        } elseif (preg_match('/->\w+\b/', $normalized)) {
            $kind = 'O_PROPERTY_FETCH';
        } elseif (preg_match('/(?:\+\+|--)\s*\$\w+|\$\w+\s*(?:\+\+|--)/', $normalized)) {
            $kind = str_contains($normalized, '--') ? 'O_DEC' : 'O_INC';
        } elseif (preg_match('/^(?:\[|list\s*\().*=/', $normalized)) {
            $kind = 'O_DESTRUCTURE_ASSIGN';
        } elseif (preg_match('/^\$\w+\s*(?:\+=|-=|\*=|\/=|%=|\.=)/', $normalized)) {
            $kind = 'O_COMPOUND_ASSIGN';
        } elseif (preg_match('/=\s*\$\w+(?:\[[^\]]+\])+/', $normalized)) {
            $kind = 'O_DIM_FETCH';
        } elseif (preg_match('/^\$\w+\s*=/i', $normalized)) {
            $kind = 'O_ASSIGN';
        } elseif (preg_match('/^try\b/i', $normalized)) {
            $kind = 'O_TRY';
        } elseif ($normalized === '{') {
            $kind = 'O_BLOCK_OPEN';
        } elseif ($normalized === '}') {
            $kind = 'O_BLOCK_CLOSE';
        } elseif (preg_match('/^\\\\?' . $identifier . '\s*\(/', $normalized)) {
            $kind = 'O_CALL';
        }

        if (str_contains($normalized, 'curl_')) {
            $features['curl'] = true;
        }

        if (str_contains($normalized, 'json_encode') || str_contains($normalized, 'json_decode')) {
            $features['json'] = true;
        }

        if (str_contains($normalized, '$_POST') || str_contains($normalized, 'php://input')) {
            $features['post_input'] = true;
        }

        if (str_contains($normalized, 'header(')) {
            $features['http_header'] = true;
        }

        if (str_contains($normalized, 'CoalescedOracleCompiler')) {
            $features['uses_coalesced_oracle'] = true;
        }

        if (preg_match_all('/\b([A-Za-z_]\w*)\s*\(/', $normalized, $calls)) {
            $features['calls'] = array_values(array_unique($calls[1]));
        }

        self::addModernPhpFeatures($normalized, $features);
        self::addZendFeatures($kind, $normalized, $features);

        return array_merge([
            'op' => $kind,
            'index' => $index,
            'source' => $normalized,
            'sha1' => sha1($normalized),
            'features' => (object) $features,
        ], $extra);
    }

    /**
     * Record modern PHP semantics that may be embedded inside otherwise generic statements.
     * These are representation facts only; they do not imply Oracle execution support.
     *
     * @param array<string,mixed> $features
     */
    private static function addModernPhpFeatures(string $normalized, array &$features): void
    {
        if (str_contains($normalized, '?->')) {
            $features['nullsafe_operator'] = true;
        }
        if (str_contains($normalized, '??=')) {
            $features['coalesce_assignment'] = true;
        }
        if (preg_match('/\byield\s+from\b/i', $normalized)) {
            $features['yield_from'] = true;
        }
        $hasArraySpread = (bool) preg_match('/\[[^\]\n]*\.\.\.\s*\$[A-Za-z_]\w*/', $normalized);
        if (!$hasArraySpread && preg_match('/\.\.\.\s*\$[A-Za-z_]/', $normalized)) {
            $features['argument_unpack'] = true;
        }
        if (preg_match('/\b[A-Za-z_]\w*\s*:\s*(?!:)/', $normalized)) {
            $features['named_argument'] = true;
        }
        if ($hasArraySpread) {
            $features['array_spread'] = true;
        }
        if (preg_match('/\bstatic::(?:class|\$[A-Za-z_]\w*)/', $normalized)) {
            $features['late_static_binding'] = true;
        }
        if (preg_match('/\breadonly\b/i', $normalized)) {
            $features['readonly_semantics'] = true;
        }
        if (preg_match('/\b(?:int|string|float|bool|array|object|callable|iterable|mixed|self|static|parent|[A-Za-z_]\\w*)\s*\|\s*(?:int|string|float|bool|array|object|callable|iterable|mixed|self|static|parent|[A-Za-z_]\\w*)\b/', $normalized)) {
            $features['union_type'] = true;
        }
        if (preg_match('/\?[A-Za-z_\\\\][A-Za-z0-9_\\\\]*/', $normalized)) {
            $features['nullable_type'] = true;
        }
        if (preg_match('/^use\s+function\b/i', $normalized)) {
            $features['use_function'] = true;
        }
        if (preg_match('/^use\s+const\b/i', $normalized)) {
            $features['use_const'] = true;
        }
    }

    /**
     * @param array<string,mixed> $features
     */
    private static function addZendFeatures(string $kind, string $normalized, array &$features): void
    {
        static $zendOps = [
            'O_NAMESPACE' => 'namespace',
            'O_USE' => 'use',
            'O_DECLARE' => 'declare',
            'O_CONST_DECL' => 'const_decl',
            'O_CLASS_DECL' => 'class_decl',
            'O_CLASS_CONST' => 'class_const',
            'O_INTERFACE_DECL' => 'interface_decl',
            'O_TRAIT_DECL' => 'trait_decl',
            'O_ENUM_DECL' => 'enum_decl',
            'O_ENUM_CASE' => 'enum_case',
            'O_FUNCTION_DECL' => 'function_decl',
            'O_METHOD_DECL' => 'method_decl',
            'O_PROPERTY_DECL' => 'property_decl',
            'O_ECHO' => 'echo',
            'O_PRINT' => 'print',
            'O_EXIT' => 'exit',
            'O_IF' => 'if',
            'O_ELSE' => 'else',
            'O_SWITCH' => 'switch',
            'O_MATCH' => 'match',
            'O_CASE' => 'case',
            'O_DEFAULT' => 'default',
            'O_DO' => 'do',
            'O_FOR' => 'for',
            'O_FOREACH' => 'foreach',
            'O_WHILE' => 'while',
            'O_BREAK' => 'break',
            'O_CONTINUE' => 'continue',
            'O_RETURN' => 'return',
            'O_GLOBAL' => 'global',
            'O_STATIC_LOCAL' => 'static_local',
            'O_UNSET' => 'unset',
            'O_ISSET' => 'isset',
            'O_EMPTY' => 'empty',
            'O_NEW' => 'new',
            'O_ANON_CLASS' => 'anonymous_class',
            'O_CLONE' => 'clone',
            'O_INSTANCEOF' => 'instanceof',
            'O_METHOD_CALL' => 'method_call',
            'O_STATIC_CALL' => 'static_call',
            'O_STATIC_PROPERTY_ASSIGN' => 'static_property_assign',
            'O_STATIC_PROPERTY_FETCH' => 'static_property_fetch',
            'O_PROPERTY_ASSIGN' => 'property_assign',
            'O_PROPERTY_FETCH' => 'property_fetch',
            'O_NULLSAFE_CALL' => 'nullsafe_call',
            'O_NULLSAFE_PROPERTY_FETCH' => 'nullsafe_property_fetch',
            'O_DIM_ASSIGN' => 'dim_assign',
            'O_DIM_FETCH' => 'dim_fetch',
            'O_COMPOUND_ASSIGN' => 'compound_assign',
            'O_INC' => 'increment',
            'O_DEC' => 'decrement',
            'O_DESTRUCTURE_ASSIGN' => 'destructure_assign',
            'O_COALESCE_ASSIGN' => 'coalesce_assign',
            'O_COALESCE' => 'coalesce',
            'O_TERNARY' => 'ternary',
            'O_CLOSURE' => 'closure',
            'O_ARROW_FUNCTION' => 'arrow_function',
            'O_YIELD_FROM' => 'yield_from',
            'O_YIELD' => 'yield',
            'O_GOTO' => 'goto',
            'O_LABEL' => 'label',
            'O_THROW' => 'throw',
            'O_TRY' => 'try',
            'O_CATCH' => 'catch',
            'O_FINALLY' => 'finally',
            'O_CALL' => 'call',
        ];

        if (!isset($zendOps[$kind])) {
            return;
        }

        $features['php_family'] = 'zend';
        $features['zend_construct'] = $zendOps[$kind];
        $features['oracle_interpreter_record'] = true;

        if ($kind !== 'O_RETURN' || !preg_match('/^return\s+(?:\d+|[\'"][^\'"]*[\'"]|true|false|null)\s*;?$/i', $normalized)) {
            $features['php_fallback_required'] = true;
        }

        if (preg_match('/\b([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*)::(\w+)\s*\(/', $normalized, $m)) {
            $features['class'] = $m[1];
            $features['method'] = $m[2];
        } elseif (preg_match('/->(\w+)\s*\(/', $normalized, $m)) {
            $features['method'] = $m[1];
        } elseif (preg_match('/->(\w+)\b/', $normalized, $m)) {
            $features['property'] = $m[1];
        }
    }

    /**
     * @return list<string>
     */
    private static function extractAttributeNames(string $normalized): array
    {
        if (!preg_match_all('/#\[\s*([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*)/', $normalized, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<string,mixed> $features
     */
    private static function addDeclarationFeatures(string $normalized, array &$features): void
    {
        $modifiers = [];

        foreach (['abstract', 'final', 'readonly', 'public', 'protected', 'private', 'static'] as $modifier) {
            if (preg_match('/\b' . $modifier . '\b/i', $normalized)) {
                $modifiers[] = $modifier;
                $features['modifier_' . $modifier] = true;
            }
        }

        if ($modifiers !== []) {
            $features['modifiers'] = $modifiers;
        }

        if (preg_match('/function\s+(__\w+)\s*\(/i', $normalized, $m)) {
            $features['magic_method'] = strtolower($m[1]);
        }
    }

    /**
     * @param array<string,mixed> $features
     */
    private static function addFunctionSignatureFeatures(string $normalized, array &$features): void
    {
        if (!preg_match('/function\s+\w+\s*\(([^)]*)\)\s*(?::\s*([^{;]+))?/i', $normalized, $m)) {
            return;
        }

        $parameters = trim($m[1]);
        $parameterParts = $parameters === ''
            ? []
            : array_values(array_filter(
                array_map('trim', explode(',', $parameters)),
                static fn (string $p): bool => $p !== ''
            ));

        $features['parameter_count'] = count($parameterParts);

        if ($parameters !== '') {
            $features['parameters'] = self::extractParameterNames($parameters);
            $features['variadic_parameter'] = str_contains($parameters, '...$');

            $nullableParameterType = false;
            $unionParameterType = false;
            $intersectionParameterType = false;

            foreach ($parameterParts as $parameter) {
                $beforeVariable = preg_replace('/\$[A-Za-z_]\w*.*/', '', $parameter) ?? '';
                $beforeVariable = trim($beforeVariable);

                if ($beforeVariable !== '') {
                    $nullableParameterType = $nullableParameterType || str_contains($beforeVariable, '?');
                    $unionParameterType = $unionParameterType || str_contains($beforeVariable, '|');
                    $intersectionParameterType = $intersectionParameterType || str_contains($beforeVariable, '&');
                }
            }

            $features['nullable_parameter_type'] = $nullableParameterType;
            $features['union_parameter_type'] = $unionParameterType;
            $features['intersection_parameter_type'] = $intersectionParameterType;
        }

        if (isset($m[2]) && trim($m[2]) !== '') {
            $returnType = trim($m[2]);
            $features['return_type'] = $returnType;
            $features['nullable_return_type'] = str_starts_with($returnType, '?');
            $features['union_return_type'] = str_contains($returnType, '|');
            $features['intersection_return_type'] = str_contains($returnType, '&');
        }
    }

    /**
     * @return list<string>
     */
    private static function extractParameterNames(string $parameters): array
    {
        $names = [];

        foreach (explode(',', $parameters) as $parameter) {
            if (preg_match('/\$([A-Za-z_]\w*)/', $parameter, $m)) {
                $names[] = $m[1];
            }
        }

        return $names;
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:array<string,mixed>}
     */
    private static function classifyLoaderStatement(string $loader, ?string $target, string $baseDir, array $seen, bool $literal): array
    {
        $kind = str_starts_with($loader, 'require') ? 'O_REQUIRE' : 'O_INCLUDE';
        $features = [
            'php_loader' => true,
            'loader' => $loader,
            'once' => str_ends_with($loader, '_once'),
            'literal_target' => $literal,
        ];
        $extra = [];

        if ($target === null) {
            $features['dynamic_target'] = true;
            $features['php_fallback_required'] = true;

            return [$kind, $features, $extra];
        }

        $features['target'] = $target;

        $resolved = self::resolveLiteralIncludeTarget($baseDir, $target);
        if ($resolved !== null) {
            $features['literal_target_resolved'] = true;
            $features['target_realpath'] = $resolved;

            if (isset($seen[$resolved])) {
                $features['oracle_include_cycle'] = true;
                $features['php_fallback_required'] = true;
            } else {
                $included = self::interpretAnyPhpFileToOracleProgram($resolved, $seen);
                $features['enters_oracle_program'] = true;
                $features['included_statement_count'] = $included['statement_count'];
                $features['included_executable'] = $included['executable'];
                $extra['included_oracle_program'] = $included;
            }
        } else {
            $features['literal_target_resolved'] = false;
            $features['php_fallback_required'] = true;
        }

        return [$kind, $features, $extra];
    }

    private static function resolveLiteralIncludeTarget(string $baseDir, string $target): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target)) {
            return null;
        }

        $candidate = self::isAbsolutePath($target) ? $target : $baseDir . DIRECTORY_SEPARATOR . $target;
        $real = realpath($candidate);

        if ($real === false || !is_file($real)) {
            return null;
        }

        return $real;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') ||
            str_starts_with($path, '\\') ||
            (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
