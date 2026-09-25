<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes the first proven loop PHP/Zend subset from Oracle records.
 *
 * This family extends conditional execution with narrow while loops plus
 * break/continue control flow. Unsupported records throw instead of silently
 * falling back to PHP or pretending broader Zend behavior is executable.
 */
final class OracleLoopExecutor
{
    private const MAX_LOOP_ITERATIONS = 10000;

    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function execute(array $program): array
    {
        $locals = [];
        $output = '';
        $executed = 0;
        $index = 0;
        $result = self::executeStatements($program['statements'] ?? [], $index, $locals, $output, $executed);

        if ($result['signal'] === 'break' || $result['signal'] === 'continue') {
            throw new \RuntimeException('Oracle loop execution reached stray ' . $result['signal']);
        }

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'loops',
            'output' => $output,
            'return' => $result['signal'] === 'return' ? $result['value'] : null,
            'executed_ops' => $executed,
        ];
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed}
     */
    private static function executeStatements(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $count = count($statements);

        while ($index < $count) {
            $statement = $statements[$index];
            if (!is_array($statement)) {
                $index++;
                continue;
            }

            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $source = (string) ($statement['source'] ?? '');

            switch ($op) {
                case 'O_DECLARE':
                case 'O_BLOCK_CLOSE':
                    $index++;
                    break;

                case 'O_IF':
                    $result = self::executeIfStatement($statements, $index, $locals, $output, $executed);
                    if ($result['signal'] !== 'normal') {
                        return $result;
                    }
                    break;

                case 'O_ELSE':
                    throw new \RuntimeException("Oracle loop execution reached stray else: {$source}");

                case 'O_WHILE':
                    $result = self::executeWhileStatement($statements, $index, $locals, $output, $executed);
                    if ($result['signal'] !== 'normal') {
                        return $result;
                    }
                    break;

                case 'O_BREAK':
                    $executed++;
                    $index++;
                    return ['signal' => 'break', 'value' => null];

                case 'O_CONTINUE':
                    $executed++;
                    $index++;
                    return ['signal' => 'continue', 'value' => null];

                case 'O_ASSIGN':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_DIM_ASSIGN':
                    self::executeDimAssignStatement($source, $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_DIM_FETCH':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_COALESCE':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_COMPOUND_ASSIGN':
                    self::executeCompoundAssignStatement($source, $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_INC':
                case 'O_DEC':
                    self::executeIncDecStatement($source, $locals, $op === 'O_INC');
                    $executed++;
                    $index++;
                    break;

                case 'O_ECHO':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'echo'), $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_PRINT':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'print'), $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_RETURN':
                    $value = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals);
                    $executed++;
                    $index++;
                    return ['signal' => 'return', 'value' => $value];

                default:
                    throw new \RuntimeException("Oracle loop execution does not support {$op}: {$source}");
            }
        }

        return ['signal' => 'normal', 'value' => null];
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed}
     */
    private static function executeIfStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $source = (string) ($statements[$index]['source'] ?? '');
        $condition = self::extractCondition($source, 'if');
        $index++;

        $thenStatements = self::collectBlock($statements, $index);
        $elseStatements = [];

        if (isset($statements[$index]) && is_array($statements[$index]) && ($statements[$index]['op'] ?? null) === 'O_ELSE') {
            $index++;
            $elseStatements = self::collectBlock($statements, $index);
        }

        $branch = self::toPhpBool(self::evaluateExpression($condition, $locals)) ? $thenStatements : $elseStatements;
        $branchIndex = 0;

        return self::executeStatements($branch, $branchIndex, $locals, $output, $executed);
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed}
     */
    private static function executeWhileStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $source = (string) ($statements[$index]['source'] ?? '');
        $condition = self::extractCondition($source, 'while');
        $index++;
        $body = self::collectBlock($statements, $index);
        $iterations = 0;

        while (self::toPhpBool(self::evaluateExpression($condition, $locals))) {
            $iterations++;
            if ($iterations > self::MAX_LOOP_ITERATIONS) {
                throw new \RuntimeException('Oracle loop execution exceeded iteration guard');
            }

            $bodyIndex = 0;
            $result = self::executeStatements($body, $bodyIndex, $locals, $output, $executed);

            if ($result['signal'] === 'return') {
                return $result;
            }

            if ($result['signal'] === 'break') {
                return ['signal' => 'normal', 'value' => null];
            }

            if ($result['signal'] === 'continue') {
                continue;
            }
        }

        return ['signal' => 'normal', 'value' => null];
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @return list<array<string,mixed>>
     */
    private static function collectBlock(array $statements, int &$index): array
    {
        $block = [];
        $depth = 1;
        $count = count($statements);

        while ($index < $count) {
            $statement = $statements[$index];
            $op = is_array($statement) ? (string) ($statement['op'] ?? '') : '';

            if ($op === 'O_BLOCK_CLOSE') {
                $depth--;
                $index++;
                if ($depth === 0) {
                    return $block;
                }
                $block[] = $statement;
                continue;
            }

            if ($op === 'O_IF' || $op === 'O_ELSE' || $op === 'O_WHILE') {
                $depth++;
            }

            $block[] = $statement;
            $index++;
        }

        throw new \RuntimeException('Unclosed Oracle loop/conditional block');
    }

    private static function extractCondition(string $source, string $keyword): string
    {
        if (!preg_match('/^' . preg_quote($keyword, '/') . '\s*\((.*)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle {$keyword} source: {$source}");
        }

        return trim($m[1]);
    }

    /** @param array<string,mixed> $locals */
    private static function executeAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
    }

    /** @param array<string,mixed> $locals */
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

    /** @param array<string,mixed> $locals */
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

    /** @param array<string,mixed> $locals */
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

    /** @param array<string,mixed> $locals */
    private static function evaluateExpression(string $expression, array &$locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if ($expr === '[]') {
            return [];
        }

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluateExpression(substr($expr, 1, -1), $locals);
        }

        foreach ([['||'], ['&&']] as $operators) {
            $parts = self::splitTopLevelByOperators($expr, $operators);
            if ($parts !== null) {
                [$leftExpr, $operator, $rightExpr] = $parts;
                if ($operator === '||') {
                    return self::toPhpBool(self::evaluateExpression($leftExpr, $locals)) || self::toPhpBool(self::evaluateExpression($rightExpr, $locals));
                }

                return self::toPhpBool(self::evaluateExpression($leftExpr, $locals)) && self::toPhpBool(self::evaluateExpression($rightExpr, $locals));
            }
        }

        if (str_starts_with($expr, '!')) {
            return !self::toPhpBool(self::evaluateExpression(substr($expr, 1), $locals));
        }

        $comparison = self::splitTopLevelByOperators($expr, ['===', '!==', '>=', '<=', '==', '!=', '>', '<']);
        if ($comparison !== null) {
            [$leftExpr, $operator, $rightExpr] = $comparison;
            $left = self::evaluateExpression($leftExpr, $locals);
            $right = self::evaluateExpression($rightExpr, $locals);

            return match ($operator) {
                '===' => $left === $right,
                '!==' => $left !== $right,
                '==' => $left == $right,
                '!=' => $left != $right,
                '>' => $left > $right,
                '<' => $left < $right,
                '>=' => $left >= $right,
                '<=' => $left <= $right,
            };
        }

        if (preg_match('/^(.+)\s*\?\?\s*(.+)$/', $expr, $m)) {
            try {
                $left = self::evaluateExpression($m[1], $locals);
                return $left !== null ? $left : self::evaluateExpression($m[2], $locals);
            } catch (\RuntimeException) {
                return self::evaluateExpression($m[2], $locals);
            }
        }

        foreach (['+', '-', '*', '.',] as $operator) {
            $parts = self::splitTopLevelByOperators($expr, [$operator]);
            if ($parts !== null) {
                [$leftExpr, , $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return match ($operator) {
                    '+' => $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '.' => (string) $left . (string) $right,
                };
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

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
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
