<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Straight-line executor for deterministic filesystem/stat PHP builtins.
 *
 * The matching parity test creates controlled fixture files/directories under
 * build/generated before comparing PHP with Oracle. This owner reads that
 * controlled state only; it does not mutate files during Oracle execution.
 */
final class OracleFilesystemBuiltinExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int}
     */
    public static function execute(array $program, string $family): array
    {
        $locals = [];
        $output = '';
        $executed = 0;

        foreach ($program['statements'] ?? [] as $statement) {
            if (!is_array($statement)) {
                continue;
            }

            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $source = (string) ($statement['source'] ?? '');

            switch ($op) {
                case 'O_DECLARE':
                    continue 2;
                case 'O_ASSIGN':
                case 'O_COALESCE':
                case 'O_DIM_FETCH':
                    self::executeAssignment($source, $locals, $program);
                    $executed++;
                    continue 2;
                case 'O_ECHO':
                    $output .= self::phpString(self::evaluate(self::stripKeyword($source, 'echo'), $locals, $program));
                    $executed++;
                    continue 2;
                case 'O_PRINT':
                    $output .= self::phpString(self::evaluate(self::stripKeyword($source, 'print'), $locals, $program));
                    $executed++;
                    continue 2;
                case 'O_RETURN':
                    $return = self::evaluate(self::stripKeyword($source, 'return'), $locals, $program);
                    $executed++;
                    return [
                        'kind' => 'JINX_ORACLE_EXECUTION',
                        'family' => $family,
                        'output' => $output,
                        'return' => $return,
                        'executed_ops' => $executed,
                    ];
            }

            throw new \RuntimeException("Oracle filesystem builtin execution does not support {$op}: {$source}");
        }

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => $family,
            'output' => $output,
            'return' => null,
            'executed_ops' => $executed,
        ];
    }

    /** @param array<string,mixed> $locals */
    private static function executeAssignment(string $source, array &$locals, array $program): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle filesystem builtin assignment: {$source}");
        }
        $locals[$m[1]] = self::evaluate($m[2], $locals, $program);
    }

    /** @param array<string,mixed> $locals */
    private static function evaluate(string $expression, array &$locals, array $program): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));
        if ($expr === '') {
            return null;
        }

        if (preg_match('/^-?\d+\.\d+$/', $expr)) {
            return (float) $expr;
        }

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals, $program);
        }

        $concat = self::splitTopLevelByOperators($expr, ['.']);
        if ($concat !== null) {
            [$leftExpr, , $rightExpr] = $concat;
            return self::phpString(self::evaluate($leftExpr, $locals, $program)) . self::phpString(self::evaluate($rightExpr, $locals, $program));
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }
        if (preg_match('/^([\'\"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }
        if (strcasecmp($expr, 'true') === 0) {
            return true;
        }
        if (strcasecmp($expr, 'false') === 0) {
            return false;
        }
        if (strcasecmp($expr, 'null') === 0) {
            return null;
        }
        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            return $locals[$m[1]] ?? null;
        }
        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return self::evaluateArrayLiteral(substr($expr, 1, -1), $locals, $program);
        }
        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            return self::callBuiltin(strtolower($m[1]), self::evaluateArguments($m[2], $locals, $program));
        }

        throw new \RuntimeException("Unsupported Oracle filesystem builtin expression: {$expr}");
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateArrayLiteral(string $body, array &$locals, array $program): array
    {
        $result = [];
        foreach (self::splitTopLevel($body, ',') as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pair = self::splitTopLevelByOperators($part, ['=>']);
            if ($pair !== null) {
                [$keyExpr, , $valueExpr] = $pair;
                $result[self::evaluate($keyExpr, $locals, $program)] = self::evaluate($valueExpr, $locals, $program);
            } else {
                $result[] = self::evaluate($part, $locals, $program);
            }
        }
        return $result;
    }

    /** @param list<mixed> $args */
    private static function callBuiltin(string $name, array $args): mixed
    {
        return match ($name) {
            'file_exists' => file_exists((string) ($args[0] ?? '')),
            'is_file' => is_file((string) ($args[0] ?? '')),
            'is_dir' => is_dir((string) ($args[0] ?? '')),
            'is_readable' => is_readable((string) ($args[0] ?? '')),
            'is_writable' => is_writable((string) ($args[0] ?? '')),
            'filesize' => filesize((string) ($args[0] ?? '')),
            'filetype' => filetype((string) ($args[0] ?? '')),
            'fileperms' => fileperms((string) ($args[0] ?? '')),
            'fileinode' => fileinode((string) ($args[0] ?? '')),
            'filemtime' => filemtime((string) ($args[0] ?? '')),
            'filectime' => filectime((string) ($args[0] ?? '')),
            'fileatime' => fileatime((string) ($args[0] ?? '')),
            'fileowner' => fileowner((string) ($args[0] ?? '')),
            'filegroup' => filegroup((string) ($args[0] ?? '')),
            'is_executable' => is_executable((string) ($args[0] ?? '')),
            'is_link' => is_link((string) ($args[0] ?? '')),
            'realpath' => realpath((string) ($args[0] ?? '')),
            'stat' => stat((string) ($args[0] ?? '')),
            'lstat' => lstat((string) ($args[0] ?? '')),
            'file_get_contents' => file_get_contents((string) ($args[0] ?? ''), false, null, (int) ($args[1] ?? 0), isset($args[2]) ? (int) $args[2] : null),
            'file' => file((string) ($args[0] ?? ''), (int) ($args[1] ?? 0)),
            'md5_file' => md5_file((string) ($args[0] ?? '')),
            'sha1_file' => sha1_file((string) ($args[0] ?? '')),
            'hash_file' => hash_file((string) ($args[0] ?? 'sha256'), (string) ($args[1] ?? '')),
            'glob' => glob((string) ($args[0] ?? ''), (int) ($args[1] ?? 0)) ?: [],
            'scandir' => scandir((string) ($args[0] ?? '')) ?: [],
            'parse_ini_file' => parse_ini_file((string) ($args[0] ?? ''), false, (int) ($args[1] ?? 0)),
            'parse_ini_string' => parse_ini_string((string) ($args[0] ?? ''), false, (int) ($args[1] ?? 0)),
            'getcwd' => getcwd(),
            'stream_resolve_include_path' => stream_resolve_include_path((string) ($args[0] ?? '')),
            'get_meta_tags' => get_meta_tags((string) ($args[0] ?? '')),
            'realpath_cache_size' => realpath_cache_size(),
            'disk_total_space' => disk_total_space((string) ($args[0] ?? '.')),
            'json_encode' => json_encode($args[0] ?? null),
            default => throw new \RuntimeException("Unsupported Oracle filesystem builtin: {$name}"),
        };
    }

    /** @return list<mixed> */
    private static function evaluateArguments(string $body, array &$locals, array $program): array
    {
        $args = [];
        foreach (self::splitTopLevel($body, ',') as $arg) {
            if (trim($arg) !== '') {
                $args[] = self::evaluate($arg, $locals, $program);
            }
        }
        return $args;
    }

    private static function stripKeyword(string $source, string $keyword): string
    {
        return rtrim(trim((string) preg_replace('/^' . preg_quote($keyword, '/') . '\b/i', '', $source, 1)), ';');
    }

    private static function phpString(mixed $value): string
    {
        if ($value === true) {
            return '1';
        }
        if ($value === false || $value === null) {
            return '';
        }
        return (string) $value;
    }

    /** @param list<string> $operators @return array{0:string,1:string,2:string}|null */
    private static function splitTopLevelByOperators(string $expr, array $operators): ?array
    {
        usort($operators, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($operators as $operator) {
            $pos = self::findTopLevelToken($expr, $operator);
            if ($pos !== null && $pos > 0) {
                return [substr($expr, 0, $pos), $operator, substr($expr, $pos + strlen($operator))];
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $expr, string $delimiter): array
    {
        $parts = [];
        $start = 0;
        $offset = 0;
        while (($pos = self::findTopLevelToken(substr($expr, $offset), $delimiter)) !== null) {
            $pos += $offset;
            $parts[] = substr($expr, $start, $pos - $start);
            $start = $pos + strlen($delimiter);
            $offset = $start;
        }
        $parts[] = substr($expr, $start);
        return $parts;
    }

    private static function findTopLevelToken(string $expr, string $token): ?int
    {
        $quote = null;
        $depth = 0;
        $length = strlen($expr);
        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;
                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0 && substr($expr, $i, strlen($token)) === $token) {
                return $i;
            }
        }
        return null;
    }

    private static function isWrappedInOuterParens(string $expr): bool
    {
        if (!str_starts_with($expr, '(') || !str_ends_with($expr, ')')) {
            return false;
        }
        $quote = null;
        $depth = 0;
        $length = strlen($expr);
        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;
                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth--;
                if ($depth === 0 && $i < $length - 1) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }
}
