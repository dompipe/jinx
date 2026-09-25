<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Straight-line executor for deterministic math/numeric PHP builtins.
 */
final class OracleMathBuiltinExecutor
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

            throw new \RuntimeException("Oracle math builtin execution does not support {$op}: {$source}");
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
            throw new \RuntimeException("Unsupported Oracle math builtin assignment: {$source}");
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

        if (preg_match('/^-?\d+\.\d+$/', $expr)) {
            return (float) $expr;
        }
        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals);
        }

        $concat = self::splitTopLevelByOperators($expr, ['.']);
        if ($concat !== null) {
            [$left, , $right] = $concat;
            return self::phpString(self::evaluate($left, $locals)) . self::phpString(self::evaluate($right, $locals));
        }

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            return self::callBuiltin(strtolower($m[1]), self::evaluateArguments($m[2], $locals));
        }

        if (preg_match('/^([\'\"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        foreach (self::constants() as $constant => $value) {
            if (strcasecmp($expr, $constant) === 0) {
                return $value;
            }
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

        throw new \RuntimeException("Unsupported Oracle math builtin expression: {$expr}");
    }

    /** @return array<string,float> */
    private static function constants(): array
    {
        return [
            'INF' => INF,
            'NAN' => NAN,
        ];
    }

    /** @param list<mixed> $args */
    private static function callBuiltin(string $name, array $args): mixed
    {
        return match ($name) {
            'floor' => floor((float) ($args[0] ?? 0)),
            'ceil' => ceil((float) ($args[0] ?? 0)),
            'sqrt' => sqrt((float) ($args[0] ?? 0)),
            'pow' => pow($args[0] ?? 0, $args[1] ?? 0),
            'fmod' => fmod((float) ($args[0] ?? 0), (float) ($args[1] ?? 1)),
            'intdiv' => intdiv((int) ($args[0] ?? 0), (int) ($args[1] ?? 1)),
            'deg2rad' => deg2rad((float) ($args[0] ?? 0)),
            'rad2deg' => rad2deg((float) ($args[0] ?? 0)),
            'sin' => sin((float) ($args[0] ?? 0)),
            'cos' => cos((float) ($args[0] ?? 0)),
            'tan' => tan((float) ($args[0] ?? 0)),
            'asin' => asin((float) ($args[0] ?? 0)),
            'acos' => acos((float) ($args[0] ?? 0)),
            'atan' => atan((float) ($args[0] ?? 0)),
            'log' => isset($args[1]) ? log((float) $args[0], (float) $args[1]) : log((float) ($args[0] ?? 1)),
            'exp' => exp((float) ($args[0] ?? 0)),
            'pi' => pi(),
            'hypot' => hypot((float) ($args[0] ?? 0), (float) ($args[1] ?? 0)),
            'is_finite' => is_finite((float) ($args[0] ?? 0)),
            'is_infinite' => is_infinite((float) ($args[0] ?? 0)),
            'is_nan' => is_nan((float) ($args[0] ?? 0)),
            'number_format' => number_format((float) ($args[0] ?? 0), (int) ($args[1] ?? 0), (string) ($args[2] ?? '.'), (string) ($args[3] ?? ',')),
            default => throw new \RuntimeException("Unsupported Oracle math builtin: {$name}"),
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
