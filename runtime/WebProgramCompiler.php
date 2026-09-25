<?php

declare(strict_types=1);

namespace jinx\web;

require_once __DIR__ . '/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

/**
 * Canonical Web compiler entrypoint.
 *
 * This is now the product-facing compiler layer:
 *
 *   PHP source
 *   -> Web statement stream
 *   -> coalesced/executable web ops where supported
 *
 * The old Oracle compiler is still used underneath as a compatibility bridge
 * for executable coalesced ops, but this class owns the web statement names.
 */
final class WebProgramCompiler
{
    /**
     * @return array<string,mixed>
     */
    public static function compileAnyPhpFileToWebProgram(string $path): array
    {
        $program = OracleProgramCompiler::compileAnyPhpFileToOracleProgram($path);

        // JINX_WEB_SUPPRESS_OLD_BRIDGE_ERRORS_FOR_FULL_WEB_FILES
        // WebProgramCompiler's job is the WEB_* statement stream. The old coalesced
        // Oracle bridge is only relevant for tiny arithmetic/demo files and should not
        // make full web files look noisy or partially failed.
        $source = (string) file_get_contents($path);
        if (self::looksLikeFullWebPhp($source)) {
            $program['executable'] = false;
            $program['executable_error'] = null;
            $program['coalesced_ops'] = [];
        }

        $webStatements = [];

        foreach ($program['statements'] as $stmt) {
            foreach (self::oracleStatementToWebStatements($stmt) as $webStmt) {
                $webStatements[] = $webStmt;
            }
        }

        return [
            'kind' => 'JINX_WEB_PROGRAM',
            'source_file' => $program['source_file'],
            'source_sha1' => $program['source_sha1'],
            'statement_count' => count($webStatements),
            'statements' => $webStatements,
            'executable' => $program['executable'],
            'executable_error' => $program['executable_error'],
            'coalesced_ops' => self::renameOps($program['coalesced_ops'] ?? []),
            'jinx' => $program['jinx'],
        ];
    }

    /**
     * One PHP statement can carry several web meanings.
     *
     * Example:
     *   json_response([...])
     *
     * can be:
     *   WEB_CALL
     *   WEB_JSON_RELATED
     *
     * @param array<string,mixed> $stmt
     * @return list<array<string,mixed>>
     */

    private static function looksLikeFullWebPhp(string $source): bool
    {
        return (bool) preg_match(
            '/\b(function|class|interface|trait|namespace|use)\b|'.
            '\$_(POST|GET|SERVER|REQUEST)\b|'.
            'php:\/\/input|'.
            '\b(curl_|header|http_response_code|json_encode|json_decode)\b/',
            $source
        );
    }

    private static function oracleStatementToWebStatements(array $stmt): array
    {
        $source = (string) ($stmt['source'] ?? '');
        $fromOracle = (string) ($stmt['op'] ?? 'O_RAW_PHP_STMT');
        $features = (array) ($stmt['features'] ?? []);
        $index = (int) ($stmt['index'] ?? 0);
        $sha1 = (string) ($stmt['sha1'] ?? sha1($source));

        $base = [
            'index' => $index,
            'source' => $source,
            'sha1' => $sha1,
            'features' => (object) $features,
            'from_oracle_op' => $fromOracle,
        ];

        $out = [];

        $push = static function (string $op, array $extra = []) use (&$out, $base): void {
            $out[] = array_merge(['op' => $op], $base, $extra);
        };

        /**
         * Structural web statements.
         */
        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/i', $source)) {
            $push('WEB_DECLARE_IGNORE');
            return $out;
        }

        if (preg_match('/^function\s+(\w+)\s*\(/i', $source, $m)) {
            $push('WEB_FUNCTION_DECL', ['name' => $m[1]]);
            return $out;
        }

        if (preg_match('/^if\s*\(/i', $source)) {
            $push('WEB_IF');
            return $out;
        }

        if (preg_match('/^else\b/i', $source)) {
            $push('WEB_ELSE');
            return $out;
        }

        if ($source === '{') {
            $push('WEB_BLOCK_OPEN');
            return $out;
        }

        if ($source === '}') {
            $push('WEB_BLOCK_CLOSE');
            return $out;
        }

        if (preg_match('/^echo\b/i', $source)) {
            $push('WEB_ECHO');
        }

        if (preg_match('/^return\s+\[/i', $source)) {
            $push('WEB_RETURN_ARRAY');
        } elseif (preg_match('/^return\b/i', $source)) {
            $push('WEB_RETURN');
        }

        if (preg_match('/^\$\w+\s*=/i', $source)) {
            $push('WEB_ASSIGN');
        }

        if (preg_match('/^throw\b/i', $source)) {
            $push('WEB_THROW');
        }

        if (preg_match('/^try\b/i', $source)) {
            $push('WEB_TRY');
        }

        if (preg_match('/^catch\b/i', $source)) {
            $push('WEB_CATCH');
        }

        /**
         * Web-specific semantic statements.
         */
        if (str_contains($source, "file_get_contents('php://input')") ||
            str_contains($source, 'file_get_contents("php://input")') ||
            str_contains($source, 'php://input')) {
            $push('WEB_READ_BODY');
        }

        if (str_contains($source, 'json_decode')) {
            $push('WEB_JSON_DECODE');
        }

        if (str_contains($source, 'json_encode')) {
            $push('WEB_JSON_ENCODE');
        }

        if (str_contains($source, 'header(')) {
            $push('WEB_HEADER');
        }

        if (str_contains($source, 'http_response_code')) {
            $push('WEB_STATUS_CODE');
        }

        if (str_contains($source, 'curl_init') ||
            str_contains($source, 'curl_setopt') ||
            str_contains($source, 'curl_exec') ||
            str_contains($source, 'curl_getinfo') ||
            str_contains($source, 'curl_close') ||
            str_contains($source, 'curl_error')) {
            $push('WEB_CURL_CALL');
        }

        if (str_contains($source, '$_POST')) {
            $push('WEB_POST_SUPERGLOBAL');
        }

        if (str_contains($source, '$_GET')) {
            $push('WEB_GET_SUPERGLOBAL');
        }

        if (str_contains($source, '$_SERVER')) {
            $push('WEB_SERVER_SUPERGLOBAL');
        }

        /**
         * General calls.
         */
        if (preg_match_all('/\b([A-Za-z_]\w*)\s*\(/', $source, $calls)) {
            $names = array_values(array_unique($calls[1]));

            foreach ($names as $name) {
                if (in_array($name, [
                    'if',
                    'for',
                    'foreach',
                    'while',
                    'switch',
                    'catch',
                    'function',
                ], true)) {
                    continue;
                }

                $push('WEB_CALL', ['name' => $name]);
            }
        }

        if ($out === []) {
            $fallback = match ($fromOracle) {
                'O_FOR' => 'WEB_FOR',
                'O_FOREACH' => 'WEB_FOREACH',
                'O_WHILE' => 'WEB_WHILE',
                'O_RAW_PHP_STMT' => 'WEB_RAW_PHP_STMT',
                default => 'WEB_' . preg_replace('/^O_/', '', $fromOracle),
            };

            $push($fallback);
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    private static function renameOps(array $ops): array
    {
        $renamed = [];

        foreach ($ops as $op) {
            $copy = $op;

            if (isset($copy['op']) && is_string($copy['op'])) {
                $copy['op'] = str_replace(['ORET_', 'OMOV_'], ['WEB_RET_', 'WEB_MOV_'], $copy['op']);
            }

            $renamed[] = $copy;
        }

        return $renamed;
    }
}
