<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Straight-line executor for deterministic data/encoding/introspection PHP builtins.
 *
 * This owner avoids IO, randomness, clocks, process state, and by-reference
 * mutation. Every supported builtin is fixture-backed and compared with PHP.
 */
final class OracleDataBuiltinExecutor
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

            throw new \RuntimeException("Oracle data builtin execution does not support {$op}: {$source}");
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
            throw new \RuntimeException("Unsupported Oracle data builtin assignment: {$source}");
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
        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }
        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals, $program);
        }

        $concat = self::splitTopLevelByOperators($expr, ['.']);
        if ($concat !== null) {
            [$leftExpr, , $rightExpr] = $concat;
            return self::phpString(self::evaluate($leftExpr, $locals, $program)) . self::phpString(self::evaluate($rightExpr, $locals, $program));
        }

        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return self::evaluateArrayLiteral(substr($expr, 1, -1), $locals, $program);
        }

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            return self::callBuiltin(strtolower($m[1]), self::evaluateArguments($m[2], $locals, $program));
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

        throw new \RuntimeException("Unsupported Oracle data builtin expression: {$expr}");
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
            'base64_encode' => base64_encode((string) ($args[0] ?? '')),
            'base64_decode' => base64_decode((string) ($args[0] ?? ''), (bool) ($args[1] ?? false)),
            'bin2hex' => bin2hex((string) ($args[0] ?? '')),
            'hex2bin' => hex2bin((string) ($args[0] ?? '')),
            'sha1' => sha1((string) ($args[0] ?? '')),
            'crc32' => crc32((string) ($args[0] ?? '')),
            'hash' => hash((string) ($args[0] ?? 'sha256'), (string) ($args[1] ?? '')),
            'hash_hmac' => hash_hmac((string) ($args[0] ?? 'sha256'), (string) ($args[1] ?? ''), (string) ($args[2] ?? '')),
            'serialize' => serialize($args[0] ?? null),
            'unserialize' => unserialize((string) ($args[0] ?? 'N;')),
            'var_export' => var_export($args[0] ?? null, (bool) ($args[1] ?? false)),
            'print_r' => print_r($args[0] ?? null, (bool) ($args[1] ?? false)),
            'gettype' => gettype($args[0] ?? null),
            'is_scalar' => is_scalar($args[0] ?? null),
            'is_countable' => is_countable($args[0] ?? null),
            'sizeof' => sizeof((array) ($args[0] ?? [])),
            'array_sum' => array_sum((array) ($args[0] ?? [])),
            'array_product' => array_product((array) ($args[0] ?? [])),
            'str_split' => str_split((string) ($args[0] ?? ''), (int) ($args[1] ?? 1)),
            'chunk_split' => chunk_split((string) ($args[0] ?? ''), (int) ($args[1] ?? 76), (string) ($args[2] ?? "\r\n")),
            'json_encode' => json_encode($args[0] ?? null),
            'implode' => implode((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            default => throw new \RuntimeException("Unsupported Oracle data builtin: {$name}"),
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
