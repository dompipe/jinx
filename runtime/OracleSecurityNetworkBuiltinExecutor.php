<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Straight-line executor for deterministic security/network/environment PHP builtins.
 *
 * This batch avoids live network IO, filesystem IO, randomness, clocks, locale
 * mutation, and process mutation. Return/output parity is compared through
 * json_encode() in the generated fixture harness.
 */
final class OracleSecurityNetworkBuiltinExecutor
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
                    self::executeAssignment($source, $locals);
                    $executed++;
                    continue 2;

                case 'O_ECHO':
                    $output .= self::phpString(self::evaluate(self::stripKeyword($source, 'echo'), $locals));
                    $executed++;
                    continue 2;

                case 'O_PRINT':
                    $output .= self::phpString(self::evaluate(self::stripKeyword($source, 'print'), $locals));
                    $executed++;
                    continue 2;

                case 'O_RETURN':
                    $return = self::evaluate(self::stripKeyword($source, 'return'), $locals);
                    $executed++;
                    return self::result($family, $output, $return, $executed);
            }

            throw new \RuntimeException("Oracle security/network builtin execution does not support {$op}: {$source}");
        }

        return self::result($family, $output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $family, string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => $family,
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /** @param array<string,mixed> $locals */
    private static function executeAssignment(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle security/network assignment: {$source}");
        }
        $locals[$m[1]] = self::evaluate($m[2], $locals);
    }

    /** @param array<string,mixed> $locals */
    private static function evaluate(string $expression, array &$locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));
        if ($expr === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }
        if (preg_match('/^-?\d+\.\d+$/', $expr)) {
            return (float) $expr;
        }

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals);
        }

        $concat = self::splitTopLevelByOperators($expr, ['.']);
        if ($concat !== null) {
            [$left, , $right] = $concat;
            return self::phpString(self::evaluate($left, $locals)) . self::phpString(self::evaluate($right, $locals));
        }

        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return self::evaluateArrayLiteral(substr($expr, 1, -1), $locals);
        }

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            return self::callBuiltin(strtolower($m[1]), self::evaluateArguments($m[2], $locals));
        }

        if (preg_match('/^([\'\"])(.*)\1$/s', $expr, $m)) {
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

        if (defined($expr)) {
            return constant($expr);
        }

        throw new \RuntimeException("Unsupported Oracle security/network expression: {$expr}");
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateArrayLiteral(string $body, array &$locals): array
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
                $result[self::evaluate($keyExpr, $locals)] = self::evaluate($valueExpr, $locals);
            } else {
                $result[] = self::evaluate($part, $locals);
            }
        }
        return $result;
    }

    /** @param list<mixed> $args */
    private static function callBuiltin(string $name, array $args): mixed
    {
        return match ($name) {
            'filter_var' => filter_var($args[0] ?? null, (int) ($args[1] ?? FILTER_DEFAULT)),
            'filter_id' => filter_id((string) ($args[0] ?? '')),
            'filter_list' => filter_list(),
            'hash_algos' => hash_algos(),
            'hash_equals' => hash_equals((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'hash_hkdf' => hash_hkdf((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (int) ($args[2] ?? 0), (string) ($args[3] ?? ''), (string) ($args[4] ?? '')),
            'hash_pbkdf2' => hash_pbkdf2((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (string) ($args[2] ?? ''), (int) ($args[3] ?? 1), (int) ($args[4] ?? 0)),
            'password_get_info' => password_get_info((string) ($args[0] ?? '')),
            'password_needs_rehash' => password_needs_rehash((string) ($args[0] ?? ''), $args[1] ?? PASSWORD_DEFAULT),
            'password_verify' => password_verify((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'inet_pton' => inet_pton((string) ($args[0] ?? '')),
            'inet_ntop' => inet_ntop((string) ($args[0] ?? '')),
            'ip2long' => ip2long((string) ($args[0] ?? '')),
            'long2ip' => long2ip((int) ($args[0] ?? 0)),
            'extension_loaded' => extension_loaded((string) ($args[0] ?? '')),
            'get_loaded_extensions' => get_loaded_extensions(),
            'get_extension_funcs' => get_extension_funcs((string) ($args[0] ?? '')),
            'phpversion' => phpversion($args[0] ?? null),
            'version_compare' => version_compare((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), isset($args[2]) ? (string) $args[2] : null),
            'bin2hex' => bin2hex((string) ($args[0] ?? '')),
            'hex2bin' => hex2bin((string) ($args[0] ?? '')),
            'in_array' => in_array($args[0] ?? null, (array) ($args[1] ?? []), (bool) ($args[2] ?? false)),
            'json_encode' => json_encode($args[0] ?? null),
            default => throw new \RuntimeException("Unsupported Oracle security/network builtin: {$name}"),
        };
    }

    /** @return list<mixed> */
    private static function evaluateArguments(string $body, array &$locals): array
    {
        $args = [];
        foreach (self::splitTopLevel($body, ',') as $arg) {
            if (trim($arg) !== '') {
                $args[] = self::evaluate($arg, $locals);
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
