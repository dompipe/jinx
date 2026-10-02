<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven scalar match-expression PHP/Zend subset from Oracle records. */
final class OracleMatchExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $locals = [];
        $output = '';
        $executed = 0;

        $statements = array_values(array_filter(
            $program['statements'] ?? [],
            static fn (mixed $statement): bool => is_array($statement)
        ));

        $count = count($statements);
        for ($index = 0; $index < $count; $index++) {
            $statement = $statements[$index];
            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $source = (string) ($statement['source'] ?? '');

            switch ($op) {
                case 'O_DECLARE':
                case 'O_BLOCK_CLOSE':
                    break;

                case 'O_ASSIGN':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    break;

                case 'O_MATCH':
                    self::executeMatchStatement($statements, $index, $locals);
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
                    throw new \RuntimeException("Oracle match execution does not support {$op}: {$source}");
            }
        }

        return self::result($output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'match-expressions',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /** @param array<string,mixed> $locals */
    private static function executeAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
    }

    /** @param list<array<string,mixed>> $statements @param array<string,mixed> $locals */
    private static function executeMatchStatement(array $statements, int &$index, array &$locals): void
    {
        $source = (string) ($statements[$index]['source'] ?? '');
        if (!preg_match('/^\$(\w+)\s*=\s*match\s*\((.+)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle match assignment: {$source}");
        }

        $target = $m[1];
        $subject = self::evaluateExpression($m[2], $locals);
        $arms = [];
        $index++;

        while ($index < count($statements)) {
            $statement = $statements[$index];
            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $armSource = trim((string) ($statement['source'] ?? ''));

            if ($op === 'O_BLOCK_CLOSE' || $armSource === '};' || $armSource === '}') {
                break;
            }

            if ($op !== 'O_RAW_PHP_STMT' && $op !== 'O_DEFAULT') {
                $index--;
                break;
            }

            $arm = trim(rtrim($armSource, ',;'));
            if ($arm === '') {
                $index++;
                continue;
            }

            foreach (self::splitTopLevel($arm, ',') as $splitArm) {
                if ($splitArm !== '' && $splitArm !== '}') {
                    $arms[] = rtrim($splitArm, '} ');
                }
            }
            $index++;
        }

        $hasDefault = false;
        $defaultValue = null;

        foreach ($arms as $arm) {
            if (!preg_match('/^(.+?)\s*=>\s*(.+)$/', $arm, $parts)) {
                throw new \RuntimeException("Unsupported Oracle match arm: {$arm}");
            }

            $condition = trim($parts[1]);
            $valueExpression = trim($parts[2]);

            if (strcasecmp($condition, 'default') === 0) {
                $hasDefault = true;
                $defaultValue = self::evaluateExpression($valueExpression, $locals);
                continue;
            }

            foreach (self::splitTopLevel($condition, ',') as $candidate) {
                if ($subject === self::evaluateExpression($candidate, $locals)) {
                    $locals[$target] = self::evaluateExpression($valueExpression, $locals);
                    return;
                }
            }
        }

        if ($hasDefault) {
            $locals[$target] = $defaultValue;
            return;
        }

        throw new \UnhandledMatchError('Unhandled match case');
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

        if (preg_match('/^strlen\s*\((.+)\)$/i', $expr, $m)) {
            return strlen((string) self::evaluateExpression($m[1], $locals));
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

        throw new \RuntimeException("Unsupported Oracle match expression: {$expr}");
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

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
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

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
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

    /** @return list<string> */
    private static function splitTopLevel(string $expr, string $separator): array
    {
        $parts = [];
        $quote = null;
        $depth = 0;
        $start = 0;
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

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }

            if ($depth === 0 && substr($expr, $i, strlen($separator)) === $separator) {
                $parts[] = trim(substr($expr, $start, $i - $start));
                $start = $i + strlen($separator);
                $i += strlen($separator) - 1;
            }
        }

        $parts[] = trim(substr($expr, $start));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
