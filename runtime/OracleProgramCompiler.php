<?php

declare(strict_types=1);

namespace jinx\oracle;

require_once __DIR__ . '/PhpToJinxLowerer.php';
require_once __DIR__ . '/CoalescedOracleCompiler.php';

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
     * @return list<array<string,mixed>>
     */
    private static function sourceToOracleStatements(string $source, string $baseDir, array $seen): array
    {
        $tokens = token_get_all($source);
        $chunks = [];
        $current = '';
        $depth = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }

            $current .= $text;

            if ($text === '{') {
                $depth++;
                self::pushChunk($chunks, $current);
                $current = '';
                continue;
            }

            if ($text === '}') {
                $depth = max(0, $depth - 1);
                self::pushChunk($chunks, $current);
                $current = '';
                continue;
            }

            if ($text === ';') {
                self::pushChunk($chunks, $current);
                $current = '';
                continue;
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

        if (preg_match('/^function\s+(\w+)\s*\(/i', $normalized, $m)) {
            $kind = 'O_FUNCTION_DECL';
            $features['name'] = $m[1];
        } elseif (preg_match('/^(require_once|require|include_once|include)\s*(?:\(\s*)?([\'"])([^\'"]+)\2\s*\)?\s*;?$/i', $normalized, $m)) {
            $loader = strtolower($m[1]);
            [$kind, $features, $extra] = self::classifyLoaderStatement($loader, $m[3], $baseDir, $seen, true);
        } elseif (preg_match('/^(require_once|require|include_once|include)\b/i', $normalized, $m)) {
            [$kind, $features, $extra] = self::classifyLoaderStatement(strtolower($m[1]), null, $baseDir, $seen, false);
        } elseif (preg_match('/^if\s*\(/i', $normalized)) {
            $kind = 'O_IF';
        } elseif (preg_match('/^else\b/i', $normalized)) {
            $kind = 'O_ELSE';
        } elseif (preg_match('/^for\s*\(/i', $normalized)) {
            $kind = 'O_FOR';
        } elseif (preg_match('/^foreach\s*\(/i', $normalized)) {
            $kind = 'O_FOREACH';
        } elseif (preg_match('/^while\s*\(/i', $normalized)) {
            $kind = 'O_WHILE';
        } elseif (preg_match('/^return\b/i', $normalized)) {
            $kind = 'O_RETURN';
        } elseif (preg_match('/^\$\w+\s*=/i', $normalized)) {
            $kind = 'O_ASSIGN';
        } elseif (preg_match('/^throw\b/i', $normalized)) {
            $kind = 'O_THROW';
        } elseif (preg_match('/^try\b/i', $normalized)) {
            $kind = 'O_TRY';
        } elseif ($normalized === '{') {
            $kind = 'O_BLOCK_OPEN';
        } elseif ($normalized === '}') {
            $kind = 'O_BLOCK_CLOSE';
        } elseif (preg_match('/^\w+\s*\(/', $normalized)) {
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

        return array_merge([
            'op' => $kind,
            'index' => $index,
            'source' => $normalized,
            'sha1' => sha1($normalized),
            'features' => (object) $features,
        ], $extra);
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
