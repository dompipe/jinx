<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven for-loop PHP/Zend subset from Oracle records. */
final class OracleForExecutor
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
            throw new \RuntimeException('Oracle for-loop execution reached stray ' . $result['signal']);
        }

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'for-loops',
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

                case 'O_FOR':
                    $result = self::executeForStatement($statements, $index, $locals, $output, $executed);
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
                case 'O_DIM_FETCH':
                case 'O_COALESCE':
                    self::executeAssignStatement($source, $locals);
                    $executed++;
                    $index++;
                    break;

                case 'O_DIM_ASSIGN':
                    self::executeDimAssignStatement($source, $locals);
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

                case 'O_RETURN':
                    $value = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals);
                    $executed++;
                    $index++;
                    return ['signal' => 'return', 'value' => $value];

                default:
                    throw new \RuntimeException("Oracle for-loop execution does not support {$op}: {$source}");
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
    private static function executeForStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $source = (string) ($statements[$index]['source'] ?? '');
        [$init, $condition, $update, $bodyStart] = self::extractForParts($statements, $index, $source);
        $bodyIndex = $bodyStart;
        $body = self::collectBlock($statements, $bodyIndex);

        if ($init !== '') {
            self::executeInlineStatement($init, $locals);
            $executed++;
        }

        $iterations = 0;
        while ($condition === '' || self::toPhpBool(self::evaluateExpression($condition, $locals))) {
            $iterations++;
            if ($iterations > self::MAX_LOOP_ITERATIONS) {
                throw new \RuntimeException('Oracle for-loop execution exceeded iteration guard');
            }

            $innerIndex = 0;
            $result = self::executeStatements($body, $innerIndex, $locals, $output, $executed);

            if ($result['signal'] === 'return') {
                return $result;
            }

            if ($result['signal'] === 'break') {
                $index = $bodyIndex;
                return ['signal' => 'normal', 'value' => null];
            }

            if ($update !== '') {
                self::executeInlineStatement($update, $locals);
                $executed++;
            }

            if ($result['signal'] === 'continue') {
                continue;
            }
        }

        $index = $bodyIndex;
        return ['signal' => 'normal', 'value' => null];
    }

    /**
     * @param list<array<string,mixed>>|array<int,array<string,mixed>> $statements
     * @return array{0:string,1:string,2:string,3:int}
     */
    private static function extractForParts(array $statements, int $index, string $source): array
    {
        if (preg_match('/^for\s*\((.*?);(.*?);(.*?)\)\s*\{?$/i', trim($source), $m)) {
            return [trim($m[1]), trim($m[2]), trim($m[3]), $index + 1];
        }

        if (!preg_match('/^for\s*\((.*);\s*$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle for loop: {$source}");
        }

        $conditionSource = (string) ($statements[$index + 1]['source'] ?? '');
        $updateSource = (string) ($statements[$index + 2]['source'] ?? '');
        if ($conditionSource === '' || $updateSource === '') {
            throw new \RuntimeException("Unsupported split Oracle for loop: {$source}");
        }

        return [
            trim($m[1]),
            trim(rtrim($conditionSource, ';')),
            trim(rtrim(rtrim($updateSource, '{'), ';')),
            $index + 3,
        ];
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

            if ($op === 'O_IF' || $op === 'O_FOR') {
                $depth++;
            }

            $block[] = $statement;
            $index++;
        }

        throw new \RuntimeException('Unclosed Oracle for/conditional block');
    }

    private static function extractCondition(string $source, string $keyword): string
    {
        if (!preg_match('/^' . preg_quote($keyword, '/') . '\s*\((.*)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle {$keyword} source: {$source}");
        }

        return trim($m[1]);
    }

    /** @param array<string,mixed> $locals */
    private static function executeInlineStatement(string $source, array &$locals): void
    {
        $stmt = trim(rtrim(trim($source), ';'));
        if ($stmt === '') {
            return;
        }

        if (preg_match('/(?:\+\+|--)\s*\$\w+|\$\w+\s*(?:\+\+|--)/', $stmt)) {
            self::executeIncDecStatement($stmt, $locals, str_contains($stmt, '++'));
            return;
        }

        if (preg_match('/^\$\w+\s*(?:\+=|-=|\*=|\/=|%=|\.=)/', $stmt)) {
            self::executeCompoundAssignStatement($stmt, $locals);
            return;
        }

        self::executeAssignStatement($stmt, $locals);
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
        if (preg_match('/^\$(\w+)\[\]\s*=\s*(.+);?$/', $source, $m)) {
            $name = $m[1];
            if (!array_key_exists($name, $locals) || !is_array($locals[$name])) {
                $locals[$name] = [];
            }
            $locals[$name][] = self::evaluateExpression($m[2], $locals);
            return;
        }

        if (preg_match('/^\$(\w+)\[([^\]]+)\]\s*=\s*(.+);?$/', $source, $m)) {
            $name = $m[1];
            $key = self::evaluateExpression($m[2], $locals);
            if (!array_key_exists($name, $locals) || !is_array($locals[$name])) {
                $locals[$name] = [];
            }
            $locals[$name][$key] = self::evaluateExpression($m[3], $locals);
            return;
        }

        throw new \RuntimeException("Unsupported Oracle for-loop dimension assignment: {$source}");
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

        $ternary = self::splitTopLevelTernary($expr);
        if ($ternary !== null) {
            [$conditionExpr, $trueExpr, $falseExpr] = $ternary;
            return self::toPhpBool(self::evaluateExpression($conditionExpr, $locals))
                ? self::evaluateExpression($trueExpr, $locals)
                : self::evaluateExpression($falseExpr, $locals);
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

        foreach (['+', '-', '*', '%', '.'] as $operator) {
            $parts = self::splitTopLevelByOperators($expr, [$operator]);
            if ($parts !== null) {
                [$leftExpr, , $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return match ($operator) {
                    '+' => $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '%' => $left % $right,
                    '.' => (string) $left . (string) $right,
                };
            }
        }

        if ($expr === '[]') {
            return [];
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^"((?:\\\\.|[^"])*)"$/s', $expr, $m)) {
            return preg_replace_callback(
                '/\$([A-Za-z_]\w*)/',
                static function (array $match) use (&$locals): string {
                    $localName = $match[1];
                    if (!array_key_exists($localName, $locals)) {
                        throw new \RuntimeException('Missing Oracle interpolated local: $' . $localName);
                    }
                    return (string) $locals[$localName];
                },
                stripcslashes($m[1])
            );
        }

        if (preg_match("/^'((?:\\\\.|[^'])*)'$/s", $expr, $m)) {
            return stripcslashes($m[1]);
        }

        if (preg_match('/^implode\s*\((.+),\s*(.+)\)$/is', $expr, $m)) {
            $glue = self::evaluateExpression($m[1], $locals);
            $values = self::evaluateExpression($m[2], $locals);
            if (!is_array($values)) {
                throw new \RuntimeException("Oracle implode() expects array: {$expr}");
            }
            return implode((string) $glue, $values);
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

        throw new \RuntimeException("Unsupported Oracle for-loop expression: {$expr}");
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

    /** @return array{0:string,1:string,2:string}|null */
    private static function splitTopLevelTernary(string $expr): ?array
    {
        $quote = null;
        $depth = 0;
        $question = null;
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

            if ($char === '?' && $question === null) {
                $question = $i;
                continue;
            }

            if ($char === ':' && $question !== null) {
                return [
                    substr($expr, 0, $question),
                    substr($expr, $question + 1, $i - $question - 1),
                    substr($expr, $i + 1),
                ];
            }
        }

        return null;
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
