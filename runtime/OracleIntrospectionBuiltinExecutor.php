<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Straight-line executor for deterministic type/introspection PHP builtins.
 *
 * This executor avoids filesystem/process/global mutation and covers pure PHP
 * checks whose result is stable for literal scalar/array/class/function inputs.
 */
final class OracleIntrospectionBuiltinExecutor
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

            throw new \RuntimeException("Oracle introspection builtin execution does not support {$op}: {$source}");
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
            throw new \RuntimeException("Unsupported Oracle introspection assignment: {$source}");
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

        throw new \RuntimeException("Unsupported Oracle introspection expression: {$expr}");
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
            'is_float' => is_float($args[0] ?? null),
            'is_double' => is_double($args[0] ?? null),
            'is_real' => is_float($args[0] ?? null),
            'is_long' => is_long($args[0] ?? null),
            'is_integer' => is_integer($args[0] ?? null),
            'is_iterable' => is_iterable($args[0] ?? null),
            'is_resource' => is_resource($args[0] ?? null),
            'is_callable' => is_callable($args[0] ?? null),
            'function_exists' => function_exists((string) ($args[0] ?? '')),
            'class_exists' => class_exists((string) ($args[0] ?? '')),
            'interface_exists' => interface_exists((string) ($args[0] ?? '')),
            'trait_exists' => trait_exists((string) ($args[0] ?? '')),
            'enum_exists' => function_exists('enum_exists') ? enum_exists((string) ($args[0] ?? '')) : false,
            'get_debug_type' => get_debug_type($args[0] ?? null),
            'constant' => constant((string) ($args[0] ?? '')),
            'method_exists' => method_exists((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'property_exists' => property_exists((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'is_subclass_of' => is_subclass_of((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'is_a' => is_a((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (bool) ($args[2] ?? false)),
            'defined' => defined((string) ($args[0] ?? '')),
            'json_encode' => json_encode($args[0] ?? null),
            default => throw new \RuntimeException("Unsupported Oracle introspection builtin: {$name}"),
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
