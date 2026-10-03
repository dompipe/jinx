<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes a narrow PHP try/throw/catch/finally family from Oracle records.
 *
 * This is deliberately scoped to local variables, simple thrown Exception
 * class names, caught class/union signatures, output, and returns. Unsupported
 * records fail closed instead of quietly falling back to PHP.
 */
final class OracleExceptionExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string,error:?string}
     */
    public static function execute(array $program): array
    {
        $locals = [];
        $output = '';
        $executed = 0;
        $index = 0;
        $result = self::executeStatements($program['statements'] ?? [], $index, $locals, $output, $executed);

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'exceptions',
            'output' => $output,
            'return' => $result['signal'] === 'return' ? $result['value'] : null,
            'executed_ops' => $executed,
            'error' => $result['signal'] === 'throw' ? self::throwableDescription($result['throwable']) : null,
        ];
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed,throwable:?array<string,mixed>}
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

                case 'O_TRY':
                    $result = self::executeTryStatement($statements, $index, $locals, $output, $executed);
                    if ($result['signal'] !== 'normal') {
                        return $result;
                    }
                    break;

                case 'O_CATCH':
                case 'O_FINALLY':
                    throw new \RuntimeException("Oracle exception execution reached stray {$op}: {$source}");

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

                case 'O_THROW':
                    $throwable = self::evaluateThrowable(self::stripKeywordStatement($source, 'throw'), $locals);
                    $executed++;
                    $index++;
                    return ['signal' => 'throw', 'value' => null, 'throwable' => $throwable];

                case 'O_RETURN':
                    $value = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals);
                    $executed++;
                    $index++;
                    return ['signal' => 'return', 'value' => $value, 'throwable' => null];

                default:
                    throw new \RuntimeException("Oracle exception execution does not support {$op}: {$source}");
            }
        }

        return ['signal' => 'normal', 'value' => null, 'throwable' => null];
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed,throwable:?array<string,mixed>}
     */
    private static function executeTryStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $index++;
        $tryStatements = self::collectBlock($statements, $index);
        $catches = [];
        $finallyStatements = [];

        while (isset($statements[$index]) && is_array($statements[$index])) {
            $op = (string) ($statements[$index]['op'] ?? '');
            if ($op === 'O_CATCH') {
                $catch = $statements[$index];
                $index++;
                $catches[] = [$catch, self::collectBlock($statements, $index)];
                continue;
            }
            if ($op === 'O_FINALLY') {
                $index++;
                $finallyStatements = self::collectBlock($statements, $index);
            }
            break;
        }

        $activeIndex = 0;
        $result = self::executeStatements($tryStatements, $activeIndex, $locals, $output, $executed);

        if ($result['signal'] === 'throw') {
            $result = self::executeCatch($catches, $result['throwable'], $locals, $output, $executed);
        }

        if ($finallyStatements !== []) {
            $finallyIndex = 0;
            $finally = self::executeStatements($finallyStatements, $finallyIndex, $locals, $output, $executed);
            if ($finally['signal'] !== 'normal') {
                return $finally;
            }
        }

        return $result;
    }

    /**
     * @param list<array{0:array<string,mixed>,1:list<array<string,mixed>>}> $catches
     * @param array<string,mixed>|null $throwable
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed,throwable:?array<string,mixed>}
     */
    private static function executeCatch(array $catches, ?array $throwable, array &$locals, string &$output, int &$executed): array
    {
        if ($throwable === null) {
            return ['signal' => 'throw', 'value' => null, 'throwable' => null];
        }

        foreach ($catches as [$catchStatement, $catchStatements]) {
            $features = (array) ($catchStatement['features'] ?? []);
            $type = (string) ($features['catch_type'] ?? '');
            $variable = (string) ($features['catch_variable'] ?? '');

            if (!self::catchMatches($type, (string) $throwable['class'])) {
                continue;
            }

            if ($variable !== '') {
                $locals[$variable] = $throwable;
            }

            $catchIndex = 0;
            return self::executeStatements($catchStatements, $catchIndex, $locals, $output, $executed);
        }

        return ['signal' => 'throw', 'value' => null, 'throwable' => $throwable];
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

            if (in_array($op, ['O_TRY', 'O_CATCH', 'O_FINALLY'], true)) {
                $depth++;
            }

            $block[] = $statement;
            $index++;
        }

        throw new \RuntimeException('Unclosed Oracle exception block');
    }

    /** @param array<string,mixed> $locals */
    private static function executeAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle exception assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals);
    }

    /** @param array<string,mixed> $locals */
    private static function executeDimAssignStatement(string $source, array &$locals): void
    {
        if (!preg_match('/^\$(\w+)\[\]\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle exception dimension assignment: {$source}");
        }

        if (!isset($locals[$m[1]]) || !is_array($locals[$m[1]])) {
            $locals[$m[1]] = [];
        }
        $locals[$m[1]][] = self::evaluateExpression($m[2], $locals);
    }

    private static function stripKeywordStatement(string $source, string $keyword): string
    {
        $body = preg_replace('/^' . preg_quote($keyword, '/') . '\b/i', '', $source, 1);

        return rtrim(trim((string) $body), ';');
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateThrowable(string $expression, array &$locals): array
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (preg_match('/^new\s+([\\\\A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*)\s*\((.*)\)$/', $expr, $m)) {
            $message = trim($m[2]) === '' ? '' : (string) self::evaluateExpression($m[2], $locals);
            return ['class' => self::shortClassName($m[1]), 'message' => $message];
        }

        throw new \RuntimeException("Unsupported Oracle throwable expression: {$expression}");
    }

    /** @param array<string,mixed> $throwable */
    private static function throwableDescription(?array $throwable): ?string
    {
        if ($throwable === null) {
            return null;
        }

        return (string) ($throwable['class'] ?? 'Throwable') . ': ' . (string) ($throwable['message'] ?? '');
    }

    private static function catchMatches(string $catchType, string $throwClass): bool
    {
        foreach (explode('|', $catchType) as $candidate) {
            $candidate = self::shortClassName(trim($candidate));
            if ($candidate === '' || $candidate === 'Throwable' || $candidate === 'Exception' || $candidate === $throwClass) {
                return true;
            }
        }

        return false;
    }

    private static function shortClassName(string $class): string
    {
        $class = trim($class, '\\');
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateExpression(string $expression, array &$locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluateExpression(substr($expr, 1, -1), $locals);
        }

        if ($expr === '[]') {
            return [];
        }

        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            $values = [];
            foreach (self::splitTopLevelList(substr($expr, 1, -1)) as $part) {
                if (trim($part) !== '') {
                    $values[] = self::evaluateExpression($part, $locals);
                }
            }
            return $values;
        }

        if (preg_match('/^json_encode\s*\((.+)\)$/is', $expr, $m)) {
            $encoded = json_encode(self::evaluateExpression($m[1], $locals));
            if ($encoded === false) {
                throw new \RuntimeException('Oracle exception json_encode() failed');
            }
            return $encoded;
        }

        if (preg_match('/^\$(\w+)::class$/', $expr, $m)) {
            $value = $locals[$m[1]] ?? null;
            if (is_array($value) && isset($value['class'])) {
                return (string) $value['class'];
            }
            throw new \RuntimeException("Unsupported Oracle exception class fetch: {$expr}");
        }

        if (preg_match('/^\(string\)\s*(.+)$/i', $expr, $m)) {
            return (string) self::evaluateExpression($m[1], $locals);
        }

        foreach (['+', '.',] as $operator) {
            $parts = self::splitTopLevelByOperators($expr, [$operator]);
            if ($parts !== null) {
                [$leftExpr, , $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return $operator === '+' ? $left + $right : (string) $left . (string) $right;
            }
        }

        if ($expr === 'null') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^([\'"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle local: {$expr}");
            }

            $value = $locals[$m[1]];
            if (is_array($value) && isset($value['message'], $value['class'])) {
                return (string) $value['message'];
            }

            return $value;
        }

        throw new \RuntimeException("Unsupported Oracle exception expression: {$expr}");
    }

    /** @return list<string> */
    private static function splitTopLevelList(string $source): array
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $depth = 0;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
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
            if ($char === ',' && $depth === 0) {
                $parts[] = substr($source, $start, $i - $start);
                $start = $i + 1;
            }
        }

        $parts[] = substr($source, $start);
        return $parts;
    }

    private static function isWrappedInOuterParens(string $expr): bool
    {
        if (!str_starts_with($expr, '(') || !str_ends_with($expr, ')')) {
            return false;
        }

        $depth = 0;
        $quote = null;
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
