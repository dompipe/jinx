<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven do-while PHP/Zend subset from Oracle records. */
final class OracleDoWhileExecutor
{
    private const MAX_LOOP_ITERATIONS = 10000;

    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $locals = [];
        $output = '';
        $executed = 0;
        $index = 0;
        $result = self::executeStatements($program['statements'] ?? [], $index, $locals, $output, $executed);

        if ($result['signal'] === 'break' || $result['signal'] === 'continue') {
            throw new \RuntimeException('Oracle do-while execution reached stray ' . $result['signal']);
        }

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'do-while-loops',
            'output' => $output,
            'return' => $result['signal'] === 'return' ? $result['value'] : null,
            'executed_ops' => $executed,
        ];
    }

    /** @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements @param array<string,mixed> $locals */
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

                case 'O_DO':
                    $result = self::executeDoWhileStatement($statements, $index, $locals, $output, $executed);
                    if ($result['signal'] !== 'normal') {
                        return $result;
                    }
                    break;

                case 'O_WHILE':
                    throw new \RuntimeException("Oracle do-while execution reached stray while: {$source}");

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
                    throw new \RuntimeException("Oracle do-while execution does not support {$op}: {$source}");
            }
        }

        return ['signal' => 'normal', 'value' => null];
    }

    /** @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements @param array<string,mixed> $locals */
    private static function executeIfStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $source = (string) ($statements[$index]['source'] ?? '');
        $condition = self::extractCondition($source, 'if');
        $index++;
        $thenStatements = self::collectBlock($statements, $index);
        $branchIndex = 0;

        if (self::toPhpBool(self::evaluateExpression($condition, $locals))) {
            return self::executeStatements($thenStatements, $branchIndex, $locals, $output, $executed);
        }

        return ['signal' => 'normal', 'value' => null];
    }

    /** @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements @param array<string,mixed> $locals */
    private static function executeDoWhileStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $index++;
        $body = self::collectBlock($statements, $index);
        $conditionStatement = $statements[$index] ?? null;
        if (!is_array($conditionStatement) || ($conditionStatement['op'] ?? null) !== 'O_WHILE') {
            throw new \RuntimeException('Oracle do-while execution missing trailing while condition');
        }

        $condition = self::extractDoWhileCondition((string) ($conditionStatement['source'] ?? ''));
        $index++;
        $iterations = 0;

        do {
            $iterations++;
            if ($iterations > self::MAX_LOOP_ITERATIONS) {
                throw new \RuntimeException('Oracle do-while execution exceeded iteration guard');
            }

            $bodyIndex = 0;
            $result = self::executeStatements($body, $bodyIndex, $locals, $output, $executed);

            if ($result['signal'] === 'return') {
                return $result;
            }

            if ($result['signal'] === 'break') {
                return ['signal' => 'normal', 'value' => null];
            }
        } while (self::toPhpBool(self::evaluateExpression($condition, $locals)));

        return ['signal' => 'normal', 'value' => null];
    }

    /** @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements @return list<array<string,mixed>> */
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

            if ($op === 'O_IF' || $op === 'O_DO') {
                $depth++;
            }

            $block[] = $statement;
            $index++;
        }

        throw new \RuntimeException('Unclosed Oracle do-while/conditional block');
    }

    private static function extractCondition(string $source, string $keyword): string
    {
        if (!preg_match('/^' . preg_quote($keyword, '/') . '\s*\((.*)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle {$keyword} source: {$source}");
        }

        return trim($m[1]);
    }

    private static function extractDoWhileCondition(string $source): string
    {
        if (!preg_match('/^while\s*\((.*)\)\s*;?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle do-while condition: {$source}");
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
    private static function executeCompoundAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*(\.=|\+=|-=|\*=|\/=|%=)\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle compound assignment: {$source}");
        }

        $name = $m[1];
        $right = self::evaluateExpression($m[3], $locals);
        $left = $locals[$name] ?? null;

        $locals[$name] = match ($m[2]) {
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
        if (!preg_match('/\$(\w+)/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle increment/decrement: {$source}");
        }

        $locals[$m[1]] = ($locals[$m[1]] ?? 0) + ($increment ? 1 : -1);
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

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluateExpression(substr($expr, 1, -1), $locals);
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

        foreach (['+', '-', '*', '.'] as $operator) {
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

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle local: {$expr}");
            }

            return $locals[$m[1]];
        }

        throw new \RuntimeException("Unsupported Oracle do-while expression: {$expr}");
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

    /** @param list<string> $operators @return array{0:string,1:string,2:string}|null */
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
