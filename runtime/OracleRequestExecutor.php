<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes the first proven request/global PHP subset from Oracle records.
 *
 * This family owns a request context for $_SERVER, $_GET, $_POST, $_REQUEST,
 * straight-line reads, coalesce, isset/empty, count(), echo, print, and return.
 * Unsupported records throw instead of falling back to PHP.
 */
final class OracleRequestExecutor
{
    /**
     * @param array<string,mixed> $program
     * @param array<string,array<string,mixed>> $request
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function execute(array $program, array $request = []): array
    {
        $locals = [
            '_SERVER' => $request['_SERVER'] ?? [],
            '_GET' => $request['_GET'] ?? [],
            '_POST' => $request['_POST'] ?? [],
            '_REQUEST' => $request['_REQUEST'] ?? array_merge($request['_GET'] ?? [], $request['_POST'] ?? []),
        ];
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
                    break;

                case 'O_ASSIGN':
                case 'O_DIM_FETCH':
                case 'O_COALESCE':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_ECHO':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'echo'), $locals);
                    $executed++;
                    break;

                case 'O_PRINT':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'print'), $locals);
                    $executed++;
                    break;

                case 'O_RETURN':
                    $value = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals);
                    $executed++;

                    return self::result($output, $value, $executed);

                default:
                    throw new \RuntimeException("Oracle request execution does not support {$op}: {$source}");
            }
        }

        return self::result($output, null, $executed);
    }

    /**
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'request-globals',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function executeAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle request assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
    }

    private static function stripKeywordStatement(string $source, string $keyword): string
    {
        $body = preg_replace('/^' . preg_quote($keyword, '/') . '\b/i', '', $source, 1);

        return rtrim(trim((string) $body), ';');
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function evaluateExpression(string $expression, array &$locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (preg_match('/^\(\s*string\s*\)\s*(.+)$/i', $expr, $m)) {
            return (string) self::evaluateExpression($m[1], $locals);
        }

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluateExpression(substr($expr, 1, -1), $locals);
        }

        $coalesce = self::splitTopLevelByOperators($expr, ['??']);
        if ($coalesce !== null) {
            [$leftExpr, , $rightExpr] = $coalesce;
            return self::targetExists(trim($leftExpr), $locals)
                ? self::evaluateExpression($leftExpr, $locals)
                : self::evaluateExpression($rightExpr, $locals);
        }

        foreach ([['+'], ['.']] as $operators) {
            $parts = self::splitTopLevelByOperators($expr, $operators);
            if ($parts !== null) {
                [$leftExpr, $operator, $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return $operator === '+' ? $left + $right : (string) $left . (string) $right;
            }
        }

        if (preg_match('/^count\s*\((.+)\)$/i', $expr, $m)) {
            return count((array) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^isset\s*\((.+)\)$/i', $expr, $m)) {
            return self::targetExists(trim($m[1]), $locals);
        }

        if (preg_match('/^empty\s*\((.+)\)$/i', $expr, $m)) {
            $target = trim($m[1]);
            return !self::targetExists($target, $locals) || !self::toPhpBool(self::evaluateExpression($target, $locals));
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^([\'\"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        if ($expr === 'true') {
            return true;
        }

        if ($expr === 'false') {
            return false;
        }

        if ($expr === 'null') {
            return null;
        }

        if (preg_match('/^\$\w+(?:\[[^\]]+\])+$/', $expr)) {
            return self::fetchTarget($expr, $locals);
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle local: {$expr}");
            }

            return $locals[$m[1]];
        }

        throw new \RuntimeException("Unsupported Oracle request expression: {$expr}");
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private static function parseTarget(string $target): array
    {
        if (!preg_match('/^\$(\w+)((?:\[[^\]]*\])*)$/', trim($target), $m)) {
            throw new \RuntimeException("Unsupported Oracle request target: {$target}");
        }

        $dims = [];
        if ($m[2] !== '') {
            preg_match_all('/\[([^\]]*)\]/', $m[2], $matches);
            $dims = $matches[1];
        }

        return [$m[1], $dims];
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function fetchTarget(string $target, array &$locals): mixed
    {
        [$name, $dims] = self::parseTarget($target);

        if (!array_key_exists($name, $locals)) {
            throw new \RuntimeException("Missing Oracle local: \${$name}");
        }

        $value = $locals[$name];
        foreach ($dims as $dimExpr) {
            if ($dimExpr === '') {
                throw new \RuntimeException("Cannot fetch append target: {$target}");
            }
            $key = self::toArrayKey(self::evaluateExpression($dimExpr, $locals));
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new \RuntimeException("Missing Oracle request dimension: {$target}");
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function targetExists(string $target, array &$locals): bool
    {
        try {
            return self::fetchTarget($target, $locals) !== null;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function toArrayKey(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    private static function toPhpBool(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === '0' || $value === []);
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
            } elseif ($char === ')' || $char === ']') {
                $depth--;
                if ($depth === 0 && $i < $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    /**
     * @param list<string> $operators
     * @return array{0:string,1:string,2:string}|null
     */
    private static function splitTopLevelByOperators(string $expr, array $operators): ?array
    {
        usort($operators, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

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

            if ($depth !== 0) {
                continue;
            }

            foreach ($operators as $operator) {
                if (substr($expr, $i, strlen($operator)) === $operator) {
                    return [substr($expr, 0, $i), $operator, substr($expr, $i + strlen($operator))];
                }
            }
        }

        return null;
    }
}
