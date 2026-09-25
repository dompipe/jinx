<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes the first proven straight-line PHP/Zend subset from Oracle records.
 *
 * This is the family entry point for the executable statement path. It is
 * intentionally strict: unsupported records throw instead of silently falling
 * back to PHP or pretending the broader Zend surface is executable.
 */
final class OracleStraightLineExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function execute(array $program): array
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
                    break;

                case 'O_ASSIGN':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_DIM_ASSIGN':
                    self::executeDimAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_DIM_FETCH':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_COALESCE':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_COMPOUND_ASSIGN':
                    self::executeCompoundAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_INC':
                case 'O_DEC':
                    self::executeIncDecStatement($source, $locals, $op === 'O_INC');
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
                    throw new \RuntimeException("Oracle straight-line execution does not support {$op}: {$source}");
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
            'family' => 'straight-line',
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
            throw new \RuntimeException("Unsupported Oracle assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function executeDimAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\[([^\]]+)\]\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle dimension assignment: {$source}");
        }

        $name = $m[1];
        $key = self::evaluateExpression($m[2], $locals);

        if (!array_key_exists($name, $locals) || !is_array($locals[$name])) {
            $locals[$name] = [];
        }

        $locals[$name][$key] = self::evaluateExpression($m[3], $locals);
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function executeCompoundAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*(\.=|\+=|-=|\*=|\/=|%=)\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle compound assignment: {$source}");
        }

        $name = $m[1];
        $operator = $m[2];
        $right = self::evaluateExpression($m[3], $locals);
        $left = $locals[$name] ?? null;

        $locals[$name] = match ($operator) {
            '.=' => (string) $left . (string) $right,
            '+=' => $left + $right,
            '-=' => $left - $right,
            '*=' => $left * $right,
            '/=' => $left / $right,
            '%=' => $left % $right,
        };
    }

    /**
     * @param array<string,mixed> $locals
     */
    private static function executeIncDecStatement(string $source, array &$locals, bool $increment): void
    {
        if (!preg_match('/^(?:\+\+|--)?\s*\$(\w+)\s*(?:\+\+|--)?\s*;?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle increment/decrement: {$source}");
        }

        $name = $m[1];
        $locals[$name] = ($locals[$name] ?? 0) + ($increment ? 1 : -1);
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

        if ($expr === '[]') {
            return [];
        }

        if (preg_match('/^\((.+)\)$/', $expr, $m)) {
            return self::evaluateExpression($m[1], $locals);
        }

        if (preg_match('/^(.+)\s*\?\?\s*(.+)$/', $expr, $m)) {
            try {
                $left = self::evaluateExpression($m[1], $locals);

                return $left !== null ? $left : self::evaluateExpression($m[2], $locals);
            } catch (\RuntimeException) {
                return self::evaluateExpression($m[2], $locals);
            }
        }

        foreach (['+', '.'] as $operator) {
            $parts = self::splitTopLevelBinary($expr, $operator);
            if ($parts !== null) {
                [$leftExpr, $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return $operator === '+'
                    ? $left + $right
                    : (string) $left . (string) $right;
            }
        }

        if (preg_match('/^strlen\s*\((.+)\)$/i', $expr, $m)) {
            return strlen((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^strtoupper\s*\((.+)\)$/i', $expr, $m)) {
            return strtoupper((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^([\'"])(.*)\1$/', $expr, $m)) {
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

        if (preg_match('/^\$(\w+)\[([^\]]+)\]$/', $expr, $m)) {
            $array = $locals[$m[1]] ?? null;
            $key = self::evaluateExpression($m[2], $locals);

            if (!is_array($array) || !array_key_exists($key, $array)) {
                throw new \RuntimeException("Missing Oracle array dimension: {$expr}");
            }

            return $array[$key];
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle local: {$expr}");
            }

            return $locals[$m[1]];
        }

        throw new \RuntimeException("Unsupported Oracle expression: {$expr}");
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private static function splitTopLevelBinary(string $expr, string $operator): ?array
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

            if ($depth === 0 && $char === $operator) {
                return [substr($expr, 0, $i), substr($expr, $i + 1)];
            }
        }

        return null;
    }
}
