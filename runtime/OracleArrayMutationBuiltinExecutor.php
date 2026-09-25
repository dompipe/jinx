<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Straight-line executor for deterministic array mutation/pointer builtins.
 *
 * The key behavior in this batch is by-reference mutation. Assignment RHS calls
 * such as `$result = sort($value);` must mutate `$value` just like PHP does.
 */
final class OracleArrayMutationBuiltinExecutor
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

            throw new \RuntimeException("Oracle array mutation builtin execution does not support {$op}: {$source}");
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
            throw new \RuntimeException("Unsupported Oracle array mutation assignment: {$source}");
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
            return self::callBuiltin(strtolower($m[1]), self::splitTopLevel($m[2], ','), $locals);
        }

        if (preg_match('/^([\'\"])(.*)\1$/s', $expr, $m)) {
            return self::decodeStringLiteral($m[2], $m[1]);
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

        throw new \RuntimeException("Unsupported Oracle array mutation expression: {$expr}");
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

    /** @param list<string> $rawArgs @param array<string,mixed> $locals */
    private static function callBuiltin(string $name, array $rawArgs, array &$locals): mixed
    {
        return match ($name) {
            'sort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => sort($value)),
            'rsort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => rsort($value)),
            'asort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => asort($value)),
            'arsort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => arsort($value)),
            'ksort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => ksort($value)),
            'krsort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => krsort($value)),
            'natsort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => natsort($value)),
            'natcasesort' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => natcasesort($value)),
            'array_push' => self::arrayPush($rawArgs, $locals),
            'array_pop' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => array_pop($value)),
            'array_shift' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => array_shift($value)),
            'array_unshift' => self::arrayUnshift($rawArgs, $locals),
            'array_splice' => self::arraySplice($rawArgs, $locals),
            'array_multisort' => self::arrayMultiSort($rawArgs, $locals),
            'reset' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => reset($value)),
            'end' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => end($value)),
            'next' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => next($value)),
            'prev' => self::mutateOne($rawArgs, $locals, static fn (&$value): mixed => prev($value)),
            'current' => current(self::argValue($rawArgs[0] ?? '[]', $locals)),
            'key' => key(self::argValue($rawArgs[0] ?? '[]', $locals)),
            'json_encode' => json_encode(self::argValue($rawArgs[0] ?? 'null', $locals)),
            default => throw new \RuntimeException("Unsupported Oracle array mutation builtin: {$name}"),
        };
    }

    /** @param list<string> $rawArgs @param array<string,mixed> $locals */
    private static function arrayPush(array $rawArgs, array &$locals): int
    {
        $name = self::variableName($rawArgs[0] ?? '');
        $values = [];
        foreach (array_slice($rawArgs, 1) as $arg) {
            $values[] = self::argValue($arg, $locals);
        }
        return array_push($locals[$name], ...$values);
    }

    /** @param list<string> $rawArgs @param array<string,mixed> $locals */
    private static function arrayUnshift(array $rawArgs, array &$locals): int
    {
        $name = self::variableName($rawArgs[0] ?? '');
        $values = [];
        foreach (array_slice($rawArgs, 1) as $arg) {
            $values[] = self::argValue($arg, $locals);
        }
        return array_unshift($locals[$name], ...$values);
    }

    /** @param list<string> $rawArgs @param array<string,mixed> $locals */
    private static function arraySplice(array $rawArgs, array &$locals): array
    {
        $name = self::variableName($rawArgs[0] ?? '');
        $offset = (int) self::argValue($rawArgs[1] ?? '0', $locals);
        $length = array_key_exists(2, $rawArgs) ? (int) self::argValue($rawArgs[2], $locals) : null;
        $replacement = array_key_exists(3, $rawArgs) ? (array) self::argValue($rawArgs[3], $locals) : [];

        if ($length === null) {
            return array_splice($locals[$name], $offset);
        }

        return array_splice($locals[$name], $offset, $length, $replacement);
    }

    /** @param list<string> $rawArgs @param array<string,mixed> $locals */
    private static function arrayMultiSort(array $rawArgs, array &$locals): bool
    {
        $first = self::variableName($rawArgs[0] ?? '');
        $second = self::variableName($rawArgs[1] ?? '');
        return array_multisort($locals[$first], $locals[$second]);
    }

    /** @param list<string> $rawArgs @param array<string,mixed> $locals */
    private static function mutateOne(array $rawArgs, array &$locals, callable $callback): mixed
    {
        $name = self::variableName($rawArgs[0] ?? '');
        return $callback($locals[$name]);
    }

    /** @param array<string,mixed> $locals */
    private static function argValue(string $raw, array &$locals): mixed
    {
        return self::evaluate(trim($raw), $locals);
    }

    private static function variableName(string $raw): string
    {
        if (!preg_match('/^\s*\$(\w+)\s*$/', $raw, $m)) {
            throw new \RuntimeException("Expected variable argument, got: {$raw}");
        }
        return $m[1];
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

    private static function decodeStringLiteral(string $body, string $quote): string
    {
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        return stripcslashes($body);
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
