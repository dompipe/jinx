<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Shared narrow executor for the next ten PHP/Zend expression and loop families.
 *
 * This is deliberately small and strict. It executes only the fixtures proven by
 * the PHP comparison test and throws for anything outside that surface.
 */
final class OracleNextTenExecutor
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
        $index = 0;
        $statements = $program['statements'] ?? [];

        while ($index < count($statements)) {
            $result = self::executeStatement($statements, $index, $locals, $output, $executed, $program);
            if ($result['returned']) {
                return self::result($family, $output, $result['value'], $executed);
            }
        }

        return self::result($family, $output, null, $executed);
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $program
     * @return array{returned:bool,value:mixed}
     */
    private static function executeStatement(array $statements, int &$index, array &$locals, string &$output, int &$executed, array $program): array
    {
        $statement = $statements[$index] ?? null;
        if (!is_array($statement)) {
            $index++;
            return ['returned' => false, 'value' => null];
        }

        $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
        $source = (string) ($statement['source'] ?? '');

        switch ($op) {
            case 'O_DECLARE':
            case 'O_BLOCK_CLOSE':
                $index++;
                return ['returned' => false, 'value' => null];

            case 'O_ASSIGN':
            case 'O_COALESCE':
            case 'O_TERNARY':
            case 'O_DIM_FETCH':
                self::executeAssignment($source, $locals, $program);
                $executed++;
                $index++;
                return ['returned' => false, 'value' => null];

            case 'O_COMPOUND_ASSIGN':
                self::executeCompoundAssignment($source, $locals, $program);
                $executed++;
                $index++;
                return ['returned' => false, 'value' => null];

            case 'O_INC':
            case 'O_DEC':
                self::executeIncrement($source, $locals, $op === 'O_DEC' ? -1 : 1);
                $executed++;
                $index++;
                return ['returned' => false, 'value' => null];

            case 'O_ECHO':
                $output .= self::phpString(self::evaluate(self::stripKeyword($source, 'echo'), $locals, $program));
                $executed++;
                $index++;
                return ['returned' => false, 'value' => null];

            case 'O_PRINT':
                $output .= self::phpString(self::evaluate(self::stripKeyword($source, 'print'), $locals, $program));
                $executed++;
                $index++;
                return ['returned' => false, 'value' => null];

            case 'O_RETURN':
                $value = self::evaluate(self::stripKeyword($source, 'return'), $locals, $program);
                $executed++;
                $index++;
                return ['returned' => true, 'value' => $value];

            case 'O_FOR':
                return self::executeFor($statements, $index, $locals, $output, $executed, $program, $source);

            case 'O_FOREACH':
                return self::executeForeach($statements, $index, $locals, $output, $executed, $program, $source);
        }

        throw new \RuntimeException("Oracle next-ten execution does not support {$op}: {$source}");
    }

    /**
     * @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int}
     */
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

    /**
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $program
     */
    private static function executeAssignment(string $source, array &$locals, array $program): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluate($m[2], $locals, $program);
    }

    /**
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $program
     */
    private static function executeCompoundAssignment(string $source, array &$locals, array $program): void
    {
        if (!preg_match('/^\$(\w+)\s*(\+=|\-=|\*=|\/=|\.=)\s*(.+);?$/', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle compound assignment: {$source}");
        }

        $name = $m[1];
        $operator = $m[2];
        $right = self::evaluate($m[3], $locals, $program);
        $left = $locals[$name] ?? null;

        $locals[$name] = match ($operator) {
            '+=' => $left + $right,
            '-=' => $left - $right,
            '*=' => $left * $right,
            '/=' => $left / $right,
            '.=' => (string) $left . (string) $right,
            default => throw new \RuntimeException("Unsupported compound operator: {$operator}"),
        };
    }

    /** @param array<string,mixed> $locals */
    private static function executeIncrement(string $source, array &$locals, int $delta): void
    {
        if (!preg_match('/\$(\w+)/', $source, $m)) {
            throw new \RuntimeException("Unsupported increment/decrement: {$source}");
        }

        $locals[$m[1]] = ($locals[$m[1]] ?? 0) + $delta;
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $program
     * @return array{returned:bool,value:mixed}
     */
    private static function executeFor(array $statements, int &$index, array &$locals, string &$output, int &$executed, array $program, string $source): array
    {
        if (!preg_match('/^for\s*\((.*?);(.*?);(.*?)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle for loop: {$source}");
        }

        $bodyStart = $index + 1;
        [$body, $after] = self::collectBlock($statements, $bodyStart);
        self::executeInlineStatement(trim($m[1]) . ';', $locals, $program);
        $executed++;

        while (self::toPhpBool(self::evaluate($m[2], $locals, $program))) {
            $bodyIndex = 0;
            while ($bodyIndex < count($body)) {
                $result = self::executeStatement($body, $bodyIndex, $locals, $output, $executed, $program);
                if ($result['returned']) {
                    $index = $after;
                    return $result;
                }
            }
            self::executeInlineStatement(trim($m[3]) . ';', $locals, $program);
            $executed++;
        }

        $index = $after;
        return ['returned' => false, 'value' => null];
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $program
     * @return array{returned:bool,value:mixed}
     */
    private static function executeForeach(array $statements, int &$index, array &$locals, string &$output, int &$executed, array $program, string $source): array
    {
        if (!preg_match('/^foreach\s*\((.+)\s+as\s+\$(\w+)(?:\s*=>\s*\$(\w+))?\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle foreach loop: {$source}");
        }

        $items = self::evaluate($m[1], $locals, $program);
        if (!is_array($items)) {
            throw new \RuntimeException("Oracle foreach expression did not evaluate to array: {$source}");
        }

        $valueName = $m[3] ?? $m[2];
        $keyName = isset($m[3]) ? $m[2] : null;
        $bodyStart = $index + 1;
        [$body, $after] = self::collectBlock($statements, $bodyStart);

        foreach ($items as $key => $value) {
            if ($keyName !== null) {
                $locals[$keyName] = $key;
            }
            $locals[$valueName] = $value;
            $executed++;

            $bodyIndex = 0;
            while ($bodyIndex < count($body)) {
                $result = self::executeStatement($body, $bodyIndex, $locals, $output, $executed, $program);
                if ($result['returned']) {
                    $index = $after;
                    return $result;
                }
            }
        }

        $index = $after;
        return ['returned' => false, 'value' => null];
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private static function collectBlock(array $statements, int $start): array
    {
        $body = [];
        $depth = 0;

        for ($i = $start; $i < count($statements); $i++) {
            $op = (string) ($statements[$i]['op'] ?? '');
            if ($op === 'O_BLOCK_CLOSE' && $depth === 0) {
                return [$body, $i + 1];
            }
            if (in_array($op, ['O_FOR', 'O_FOREACH'], true)) {
                $depth++;
            } elseif ($op === 'O_BLOCK_CLOSE') {
                $depth--;
            }
            $body[] = $statements[$i];
        }

        throw new \RuntimeException('Unclosed Oracle loop block');
    }

    /** @param array<string,mixed> $locals */
    private static function executeInlineStatement(string $source, array &$locals, array $program): void
    {
        if (preg_match('/^\$\w+\s*=/', $source)) {
            self::executeAssignment($source, $locals, $program);
            return;
        }
        if (preg_match('/^\$\w+\s*(?:\+=|\-=|\*=|\/=|\.=)/', $source)) {
            self::executeCompoundAssignment($source, $locals, $program);
            return;
        }
        if (preg_match('/(?:\+\+|--)\s*\$\w+|\$\w+\s*(?:\+\+|--)/', $source)) {
            self::executeIncrement($source, $locals, str_contains($source, '--') ? -1 : 1);
            return;
        }

        throw new \RuntimeException("Unsupported inline Oracle statement: {$source}");
    }

    /** @param array<string,mixed> $locals */
    private static function evaluate(string $expression, array &$locals, array $program): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if ($expr === '') {
            return null;
        }

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals, $program);
        }

        $ternary = self::splitTopLevelTernary($expr);
        if ($ternary !== null) {
            [$condition, $ifTrue, $ifFalse] = $ternary;
            return self::toPhpBool(self::evaluate($condition, $locals, $program))
                ? self::evaluate($ifTrue, $locals, $program)
                : self::evaluate($ifFalse, $locals, $program);
        }

        foreach ([['??'], ['||'], ['&&'], ['===', '!==', '==', '!=', '>=', '<=', '<=>', '>', '<'], ['+', '-'], ['*', '/'], ['.']] as $operators) {
            $parts = self::splitTopLevelByOperators($expr, $operators);
            if ($parts !== null) {
                [$leftExpr, $operator, $rightExpr] = $parts;
                $left = self::evaluate($leftExpr, $locals, $program);
                if ($operator === '??') {
                    return $left !== null ? $left : self::evaluate($rightExpr, $locals, $program);
                }
                if ($operator === '&&') {
                    return self::toPhpBool($left) && self::toPhpBool(self::evaluate($rightExpr, $locals, $program));
                }
                if ($operator === '||') {
                    return self::toPhpBool($left) || self::toPhpBool(self::evaluate($rightExpr, $locals, $program));
                }
                $right = self::evaluate($rightExpr, $locals, $program);

                return match ($operator) {
                    '===' => $left === $right,
                    '!==' => $left !== $right,
                    '==' => $left == $right,
                    '!=' => $left != $right,
                    '>=' => $left >= $right,
                    '<=' => $left <= $right,
                    '>' => $left > $right,
                    '<' => $left < $right,
                    '<=>' => $left <=> $right,
                    '+' => $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '/' => $left / $right,
                    '.' => (string) $left . (string) $right,
                    default => throw new \RuntimeException("Unsupported Oracle operator: {$operator}"),
                };
            }
        }

        if (preg_match('/^!\s*(.+)$/', $expr, $m)) {
            return !self::toPhpBool(self::evaluate($m[1], $locals, $program));
        }

        if (preg_match('/^\(\s*(int|string|bool|float|array)\s*\)\s*(.+)$/i', $expr, $m)) {
            $value = self::evaluate($m[2], $locals, $program);
            return match (strtolower($m[1])) {
                'int' => (int) $value,
                'string' => (string) $value,
                'bool' => (bool) $value,
                'float' => (float) $value,
                'array' => (array) $value,
            };
        }

        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return self::evaluateArrayLiteral(substr($expr, 1, -1), $locals, $program);
        }

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            return self::callBuiltin(strtolower($m[1]), self::evaluateArguments($m[2], $locals, $program));
        }

        if ($expr === '__FILE__') {
            return (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        }
        if ($expr === '__DIR__') {
            return dirname((string) ($program['source_realpath'] ?? $program['source_file'] ?? ''));
        }
        if ($expr === 'PHP_VERSION') {
            return PHP_VERSION;
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }
        if (preg_match('/^-?\d+\.\d+$/', $expr)) {
            return (float) $expr;
        }
        if (preg_match('/^([\'\"])(.*)\1$/', $expr, $m)) {
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
        if (preg_match('/^\$(\w+)((?:\[[^\]]+\])+)$/' , $expr, $m)) {
            $value = $locals[$m[1]] ?? null;
            preg_match_all('/\[([^\]]+)\]/', $m[2], $matches);
            foreach ($matches[1] as $dim) {
                $key = self::evaluate($dim, $locals, $program);
                $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
            }
            return $value;
        }

        throw new \RuntimeException("Unsupported Oracle expression: {$expr}");
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateArrayLiteral(string $body, array &$locals, array $program): array
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
                $result[self::evaluate($keyExpr, $locals, $program)] = self::evaluate($valueExpr, $locals, $program);
            } else {
                $result[] = self::evaluate($part, $locals, $program);
            }
        }
        return $result;
    }

    /** @param list<mixed> $args */
    private static function callBuiltin(string $name, array $args): mixed
    {
        return match ($name) {
            'strlen' => strlen((string) ($args[0] ?? '')),
            'strtoupper' => strtoupper((string) ($args[0] ?? '')),
            'strtolower' => strtolower((string) ($args[0] ?? '')),
            'trim' => trim((string) ($args[0] ?? '')),
            'substr' => substr((string) ($args[0] ?? ''), (int) ($args[1] ?? 0), isset($args[2]) ? (int) $args[2] : null),
            'abs' => abs($args[0] ?? 0),
            'max' => max(...$args),
            'min' => min(...$args),
            'round' => round((float) ($args[0] ?? 0), (int) ($args[1] ?? 0)),
            'count' => count((array) ($args[0] ?? [])),
            'implode' => implode((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'array_sum' => array_sum((array) ($args[0] ?? [])),
            default => throw new \RuntimeException("Unsupported Oracle builtin: {$name}"),
        };
    }

    /** @return list<mixed> */
    private static function evaluateArguments(string $body, array &$locals, array $program): array
    {
        $args = [];
        foreach (self::splitTopLevel($body, ',') as $arg) {
            if (trim($arg) !== '') {
                $args[] = self::evaluate($arg, $locals, $program);
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

    private static function toPhpBool(mixed $value): bool
    {
        return !($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === '0' || $value === []);
    }

    /** @return array{0:string,1:string,2:string}|null */
    private static function splitTopLevelTernary(string $expr): ?array
    {
        $question = self::findTopLevelToken($expr, '?');
        if ($question === null) {
            return null;
        }
        $colon = self::findTopLevelToken(substr($expr, $question + 1), ':');
        if ($colon === null) {
            return null;
        }
        $colon += $question + 1;
        return [substr($expr, 0, $question), substr($expr, $question + 1, $colon - $question - 1), substr($expr, $colon + 1)];
    }

    /** @param list<string> $operators @return array{0:string,1:string,2:string}|null */
    private static function splitTopLevelByOperators(string $expr, array $operators): ?array
    {
        usort($operators, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($operators as $operator) {
            $pos = self::findTopLevelToken($expr, $operator);
            if ($pos !== null && $pos > 0) {
                if (($operator === '-' || $operator === '+') && trim(substr($expr, 0, $pos)) === '') {
                    continue;
                }
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
        $inner = substr($expr, 1, -1);
        return self::findTopLevelToken($inner, '(') === null || true;
    }
}
