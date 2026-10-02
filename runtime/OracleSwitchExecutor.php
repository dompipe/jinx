<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes a narrow PHP switch/case/default/break family from Oracle records.
 *
 * This is intentionally separate from the conditional executor so switch
 * dispatch can grow with PHP/Zend case matching rules without turning the
 * straight-line or if/else families into a monolith.
 */
final class OracleSwitchExecutor
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
        $index = 0;
        $result = self::executeStatements($program['statements'] ?? [], $index, $locals, $output, $executed);

        if ($result['signal'] === 'break') {
            throw new \RuntimeException('Oracle switch execution reached stray break');
        }

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'switch',
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

                case 'O_SWITCH':
                    $result = self::executeSwitchStatement($statements, $index, $locals, $output, $executed);
                    if ($result['signal'] !== 'normal') {
                        return $result;
                    }
                    break;

                case 'O_CASE':
                case 'O_DEFAULT':
                    throw new \RuntimeException("Oracle switch execution reached stray case/default: {$source}");

                case 'O_BREAK':
                    $executed++;
                    $index++;
                    return ['signal' => 'break', 'value' => null];

                case 'O_ASSIGN':
                    self::executeAssignStatement($source, $locals);
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
                    throw new \RuntimeException("Oracle switch execution does not support {$op}: {$source}");
            }
        }

        return ['signal' => 'normal', 'value' => null];
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed}
     */
    private static function executeSwitchStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $source = (string) ($statements[$index]['source'] ?? '');
        $switchValue = self::evaluateExpression(self::extractSwitchExpression($source), $locals);
        $index++;
        $body = self::collectSwitchBody($statements, $index);
        $caseIndex = self::findMatchingCaseIndex($body, $switchValue, $locals);

        if ($caseIndex === null) {
            return ['signal' => 'normal', 'value' => null];
        }

        $active = array_slice($body, $caseIndex);
        $activeIndex = 0;
        $result = self::executeSwitchBody($active, $activeIndex, $locals, $output, $executed);

        return $result['signal'] === 'break' ? ['signal' => 'normal', 'value' => null] : $result;
    }

    /**
     * @param list<array<string,mixed>> $body
     * @param array<string,mixed> $locals
     */
    private static function findMatchingCaseIndex(array $body, mixed $switchValue, array &$locals): ?int
    {
        $defaultIndex = null;

        foreach ($body as $i => $statement) {
            $op = (string) ($statement['op'] ?? '');
            $source = (string) ($statement['source'] ?? '');

            if ($op === 'O_DEFAULT') {
                $defaultIndex ??= $i;
                continue;
            }

            if ($op !== 'O_CASE') {
                continue;
            }

            [$caseExpr] = self::parseCaseSource($source);
            if ($switchValue == self::evaluateExpression($caseExpr, $locals)) {
                return $i;
            }
        }

        return $defaultIndex;
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @return list<array<string,mixed>>
     */
    private static function collectSwitchBody(array $statements, int &$index): array
    {
        $body = [];
        $depth = 1;
        $count = count($statements);

        while ($index < $count) {
            $statement = $statements[$index];
            $op = is_array($statement) ? (string) ($statement['op'] ?? '') : '';

            if ($op === 'O_BLOCK_CLOSE') {
                $depth--;
                $index++;
                if ($depth === 0) {
                    return $body;
                }
                $body[] = $statement;
                continue;
            }

            if ($op === 'O_SWITCH') {
                $depth++;
            }

            $body[] = $statement;
            $index++;
        }

        throw new \RuntimeException('Unclosed Oracle switch block');
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @return array{signal:string,value:mixed}
     */
    private static function executeSwitchBody(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $count = count($statements);

        while ($index < $count) {
            $statement = $statements[$index];
            $op = (string) ($statement['op'] ?? '');
            $source = (string) ($statement['source'] ?? '');

            if ($op === 'O_CASE') {
                [, $tail] = self::parseCaseSource($source);
                $index++;
                if ($tail !== '') {
                    self::executeInlineStatement($tail, $locals, $output, $executed);
                }
                continue;
            }

            if ($op === 'O_DEFAULT') {
                $tail = self::parseDefaultSource($source);
                $index++;
                if ($tail !== '') {
                    self::executeInlineStatement($tail, $locals, $output, $executed);
                }
                continue;
            }

            $result = self::executeStatements($statements, $index, $locals, $output, $executed);
            if ($result['signal'] !== 'normal') {
                return $result;
            }
        }

        return ['signal' => 'normal', 'value' => null];
    }

    /** @param array<string,mixed> $locals */
    private static function executeInlineStatement(string $source, array &$locals, string &$output, int &$executed): void
    {
        $trimmed = trim($source);
        if ($trimmed === '') {
            return;
        }

        if (preg_match('/^\$(\w+)\s*=/', $trimmed)) {
            self::executeAssignStatement($trimmed, $locals);
            $executed++;
            return;
        }

        if (preg_match('/^echo\b/i', $trimmed)) {
            $output .= (string) self::evaluateExpression(self::stripKeywordStatement($trimmed, 'echo'), $locals);
            $executed++;
            return;
        }

        if (preg_match('/^print\b/i', $trimmed)) {
            $output .= (string) self::evaluateExpression(self::stripKeywordStatement($trimmed, 'print'), $locals);
            $executed++;
            return;
        }

        throw new \RuntimeException("Unsupported inline Oracle switch statement: {$source}");
    }

    private static function extractSwitchExpression(string $source): string
    {
        if (!preg_match('/^switch\s*\((.*)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle switch source: {$source}");
        }

        return trim($m[1]);
    }

    /** @return array{0:string,1:string} */
    private static function parseCaseSource(string $source): array
    {
        if (!preg_match('/^case\s+(.+?)\s*:\s*(.*)$/is', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle case source: {$source}");
        }

        return [trim($m[1]), trim($m[2])];
    }

    private static function parseDefaultSource(string $source): string
    {
        if (!preg_match('/^default\s*:\s*(.*)$/is', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle default source: {$source}");
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

        foreach (['+', '.',] as $operator) {
            $parts = self::splitTopLevelByOperators($expr, [$operator]);
            if ($parts !== null) {
                [$leftExpr, , $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return $operator === '+' ? $left + $right : (string) $left . (string) $right;
            }
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^([\'\"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle local: {$expr}");
            }

            return $locals[$m[1]];
        }

        throw new \RuntimeException("Unsupported Oracle switch expression: {$expr}");
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
