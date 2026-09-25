<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Straight-line executor for deterministic date/time PHP builtins.
 *
 * Fixtures pin the PHP timezone in the test harness and pass explicit timestamps
 * or literal date strings, avoiding current-clock behavior.
 */
final class OracleDateTimeBuiltinExecutor
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

            throw new \RuntimeException("Oracle date/time builtin execution does not support {$op}: {$source}");
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
            throw new \RuntimeException("Unsupported Oracle date/time assignment: {$source}");
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

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            return self::callBuiltin(strtolower($m[1]), self::evaluateArguments($m[2], $locals, $program));
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

        throw new \RuntimeException("Unsupported Oracle date/time expression: {$expr}");
    }

    /** @param list<mixed> $args */
    private static function callBuiltin(string $name, array $args): mixed
    {
        return match ($name) {
            'date' => date((string) ($args[0] ?? ''), (int) ($args[1] ?? 0)),
            'gmdate' => gmdate((string) ($args[0] ?? ''), (int) ($args[1] ?? 0)),
            'strtotime' => strtotime((string) ($args[0] ?? ''), isset($args[1]) ? (int) $args[1] : null),
            'mktime' => mktime((int) ($args[0] ?? 0), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0), (int) ($args[3] ?? 1), (int) ($args[4] ?? 1), (int) ($args[5] ?? 1970)),
            'gmmktime' => gmmktime((int) ($args[0] ?? 0), (int) ($args[1] ?? 0), (int) ($args[2] ?? 0), (int) ($args[3] ?? 1), (int) ($args[4] ?? 1), (int) ($args[5] ?? 1970)),
            'checkdate' => checkdate((int) ($args[0] ?? 1), (int) ($args[1] ?? 1), (int) ($args[2] ?? 1970)),
            'idate' => idate((string) ($args[0] ?? 'U'), (int) ($args[1] ?? 0)),
            'getdate' => getdate((int) ($args[0] ?? 0)),
            'localtime' => localtime((int) ($args[0] ?? 0), (bool) ($args[1] ?? false)),
            'date_parse' => date_parse((string) ($args[0] ?? '')),
            'date_parse_from_format' => date_parse_from_format((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'timezone_name_from_abbr' => timezone_name_from_abbr((string) ($args[0] ?? ''), isset($args[1]) ? (int) $args[1] : -1, (int) ($args[2] ?? -1)),
            'timezone_version_get' => timezone_version_get(),
            'timezone_open' => timezone_open((string) ($args[0] ?? 'UTC')),
            'timezone_name_get' => timezone_name_get($args[0]),
            'date_create' => date_create((string) ($args[0] ?? '')),
            'date_format' => date_format($args[0], (string) ($args[1] ?? 'c')),
            'date_timestamp_get' => date_timestamp_get($args[0]),
            'date_timezone_get' => date_timezone_get($args[0]),
            'timezone_offset_get' => timezone_offset_get($args[0], $args[1]),
            'json_encode' => json_encode($args[0] ?? null),
            default => throw new \RuntimeException("Unsupported Oracle date/time builtin: {$name}"),
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
