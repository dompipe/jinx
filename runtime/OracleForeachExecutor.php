<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven foreach PHP/Zend subset from Oracle records. */
final class OracleForeachExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $locals = [];
        $output = '';
        $executed = 0;
        $index = 0;
        $result = self::executeStatements($program['statements'] ?? [], $index, $locals, $output, $executed);

        if ($result['signal'] === 'break' || $result['signal'] === 'continue') {
            throw new \RuntimeException('Oracle foreach execution reached stray ' . $result['signal']);
        }

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'foreach-loops',
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

                case 'O_FOREACH':
                    $result = self::executeForeachStatement($statements, $index, $locals, $output, $executed);
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
                    throw new \RuntimeException("Oracle foreach execution does not support {$op}: {$source}");
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
    private static function executeForeachStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed): array
    {
        $source = (string) ($statements[$index]['source'] ?? '');
        [$iterableExpr, $keyName, $valueName] = self::extractForeachParts($source);
        $index++;
        $body = self::collectBlock($statements, $index);
        $iterable = self::evaluateExpression($iterableExpr, $locals);

        if (!is_array($iterable)) {
            throw new \RuntimeException("Oracle foreach iterable is not an array: {$iterableExpr}");
        }

        foreach ($iterable as $key => $value) {
            if ($keyName !== null) {
                $locals[$keyName] = $key;
            }
            $locals[$valueName] = $value;

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

            if ($op === 'O_IF' || $op === 'O_FOREACH') {
                $depth++;
            }

            $block[] = $statement;
            $index++;
        }

        throw new \RuntimeException('Unclosed Oracle foreach/conditional block');
    }

    private static function extractCondition(string $source, string $keyword): string
    {
        if (!preg_match('/^' . preg_quote($keyword, '/') . '\s*\((.*)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle {$keyword} source: {$source}");
        }

        return trim($m[1]);
    }

    /** @return array{0:string,1:?string,2:string} */
    private static function extractForeachParts(string $source): array
    {
        if (!preg_match('/^foreach\s*\((.+)\s+as\s+(.+)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle foreach source: {$source}");
        }

        $iterable = trim($m[1]);
        $target = trim($m[2]);

        if (preg_match('/^\$(\w+)\s*=>\s*\$(\w+)$/', $target, $kv)) {
            return [$iterable, $kv[1], $kv[2]];
        }

        if (preg_match('/^\$(\w+)$/', $target, $value)) {
            return [$iterable, null, $value[1]];
        }

        throw new \RuntimeException("Unsupported Oracle foreach target: {$target}");
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
        if (!preg_match('/^(\$\w+(?:\[[^\]]*\])+?)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle dimension assignment: {$source}");
        }

        self::assignTarget($m[1], self::evaluateExpression($m[2], $locals), $locals);
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
        if (!preg_match('/^(?:\+\+|--)?\s*\$(\w+)\s*(?:\+\+|--)?\s*;?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle foreach increment/decrement: {$source}");
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
    private static function assignTarget(string $target, mixed $value, array &$locals): void
    {
        [$name, $dims] = self::parseTarget($target);

        if (!array_key_exists($name, $locals) || !is_array($locals[$name])) {
            $locals[$name] = [];
        }

        $ref =& $locals[$name];
        $last = count($dims) - 1;

        foreach ($dims as $i => $dimExpr) {
            if ($i === $last) {
                if ($dimExpr === '') {
                    $ref[] = $value;
                    return;
                }

                $ref[self::toArrayKey(self::evaluateExpression($dimExpr, $locals))] = $value;
                return;
            }

            if ($dimExpr === '') {
                $ref[] = [];
                $key = array_key_last($ref);
            } else {
                $key = self::toArrayKey(self::evaluateExpression($dimExpr, $locals));
                if (!array_key_exists($key, $ref) || !is_array($ref[$key])) {
                    $ref[$key] = [];
                }
            }

            $ref =& $ref[$key];
        }
    }

    /** @return array{0:string,1:list<string>} */
    private static function parseTarget(string $target): array
    {
        if (!preg_match('/^\$(\w+)((?:\[[^\]]*\])*)$/', trim($target), $m)) {
            throw new \RuntimeException("Unsupported Oracle array target: {$target}");
        }

        preg_match_all('/\[([^\]]*)\]/', $m[2], $matches);

        return [$m[1], $matches[1]];
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateExpression(string $expression, array &$locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return self::evaluateArrayLiteral(substr($expr, 1, -1), $locals);
        }

        if ($expr === '[]') {
            return [];
        }

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

        if (preg_match('/^count\s*\((.+)\)$/is', $expr, $m)) {
            $value = self::evaluateExpression($m[1], $locals);
            if (!is_array($value) && !$value instanceof \Countable) {
                throw new \RuntimeException("Oracle foreach count() expects countable value: {$expr}");
            }
            return count($value);
        }

        if (preg_match('/^array_sum\s*\((.+)\)$/is', $expr, $m)) {
            $value = self::evaluateExpression($m[1], $locals);
            if (!is_array($value)) {
                throw new \RuntimeException("Oracle foreach array_sum() expects array: {$expr}");
            }
            return array_sum($value);
        }

        if (preg_match('/^implode\s*\((.+)\)$/is', $expr, $m)) {
            $args = self::splitTopLevelList($m[1], ',');
            if (count($args) !== 2) {
                throw new \RuntimeException("Oracle foreach implode() expects 2 arguments: {$expr}");
            }
            $glue = (string) self::evaluateExpression($args[0], $locals);
            $values = self::evaluateExpression($args[1], $locals);
            if (!is_array($values)) {
                throw new \RuntimeException("Oracle foreach implode() expects array: {$expr}");
            }
            return implode($glue, $values);
        }

        if (preg_match('/^json_encode\s*\((.+)\)$/is', $expr, $m)) {
            $encoded = json_encode(self::evaluateExpression($m[1], $locals));
            if ($encoded === false) {
                throw new \RuntimeException('Oracle foreach json_encode() failed');
            }
            return $encoded;
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

        throw new \RuntimeException("Unsupported Oracle foreach expression: {$expr}");
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateArrayLiteral(string $body, array &$locals): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $result = [];
        foreach (self::splitTopLevelList($body, ',') as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $pair = self::splitTopLevelToken($item, '=>');
            if ($pair !== null) {
                [$keyExpr, $valueExpr] = $pair;
                $key = self::evaluateExpression($keyExpr, $locals);
                if (!is_int($key) && !is_string($key)) {
                    throw new \RuntimeException("Unsupported Oracle foreach array key: {$keyExpr}");
                }
                $result[$key] = self::evaluateExpression($valueExpr, $locals);
                continue;
            }

            $result[] = self::evaluateExpression($item, $locals);
        }

        return $result;
    }

    /** @return list<string> */
    private static function splitTopLevelList(string $source, string $delimiter): array
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

            if ($depth === 0 && substr($source, $i, strlen($delimiter)) === $delimiter) {
                $parts[] = substr($source, $start, $i - $start);
                $start = $i + strlen($delimiter);
                $i += strlen($delimiter) - 1;
            }
        }

        $parts[] = substr($source, $start);
        return $parts;
    }

    /** @return array{0:string,1:string}|null */
    private static function splitTopLevelToken(string $source, string $token): ?array
    {
        $parts = self::splitTopLevelList($source, $token);
        if (count($parts) !== 2) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /** @param array<string,mixed> $locals */
    private static function fetchTarget(string $target, array &$locals): mixed
    {
        [$name, $dims] = self::parseTarget($target);

        if (!array_key_exists($name, $locals)) {
            throw new \RuntimeException("Missing Oracle local: \${$name}");
        }

        $value = $locals[$name];
        foreach ($dims as $dimExpr) {
            $key = self::toArrayKey(self::evaluateExpression($dimExpr, $locals));
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new \RuntimeException("Missing Oracle array dimension: {$target}");
            }
            $value = $value[$key];
        }

        return $value;
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
