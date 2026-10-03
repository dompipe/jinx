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

                case 'O_COALESCE_ASSIGN':
                    self::executeCoalesceAssignStatement($source, $locals);
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
    private static function executeCoalesceAssignStatement(string $source, array &$locals): void
    {
        if (preg_match('/^\$(\w+)\s*\?\?=\s*(.+);?$/', $source, $m)) {
            $name = $m[1];
            if (!array_key_exists($name, $locals) || $locals[$name] === null) {
                $locals[$name] = self::evaluateExpression($m[2], $locals);
            }
            return;
        }

        if (preg_match('/^\$(\w+)\[([^\]]+)\]\s*\?\?=\s*(.+);?$/', $source, $m)) {
            $name = $m[1];
            $key = self::evaluateExpression($m[2], $locals);
            if (!array_key_exists($name, $locals) || !is_array($locals[$name])) {
                $locals[$name] = [];
            }
            if (!array_key_exists($key, $locals[$name]) || $locals[$name][$key] === null) {
                $locals[$name][$key] = self::evaluateExpression($m[3], $locals);
            }
            return;
        }

        throw new \RuntimeException("Unsupported Oracle coalesce assignment: {$source}");
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

        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            return self::evaluateArrayLiteral(substr($expr, 1, -1), $locals);
        }

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

        $or = self::splitTopLevelOperators($expr, ['||']);
        if ($or !== null) {
            [$leftExpr, , $rightExpr] = $or;
            return self::toPhpBool(self::evaluateExpression($leftExpr, $locals))
                || self::toPhpBool(self::evaluateExpression($rightExpr, $locals));
        }

        $and = self::splitTopLevelOperators($expr, ['&&']);
        if ($and !== null) {
            [$leftExpr, , $rightExpr] = $and;
            return self::toPhpBool(self::evaluateExpression($leftExpr, $locals))
                && self::toPhpBool(self::evaluateExpression($rightExpr, $locals));
        }

        if (str_starts_with($expr, '!') && !str_starts_with($expr, '!=')) {
            return !self::toPhpBool(self::evaluateExpression(substr($expr, 1), $locals));
        }

        $comparison = self::splitTopLevelOperators($expr, ['===', '!==', '>=', '<=', '==', '!=', '>', '<']);
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

        foreach ([['.'], ['+', '-'], ['*', '/', '%']] as $operators) {
            $parts = self::splitTopLevelOperators($expr, $operators);
            if ($parts !== null) {
                [$leftExpr, $operator, $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals);
                $right = self::evaluateExpression($rightExpr, $locals);

                return match ($operator) {
                    '.' => (string) $left . (string) $right,
                    '+' => $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '/' => $left / $right,
                    '%' => $left % $right,
                };
            }
        }

        if (preg_match('/^strlen\s*\((.+)\)$/i', $expr, $m)) {
            return strlen((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^trim\s*\((.+)\)$/i', $expr, $m)) {
            return trim((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^strtoupper\s*\((.+)\)$/i', $expr, $m)) {
            return strtoupper((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^strtolower\s*\((.+)\)$/i', $expr, $m)) {
            return strtolower((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^substr\s*\((.+)\)$/is', $expr, $m)) {
            $args = self::splitTopLevelList($m[1], ',');
            if (count($args) < 2 || count($args) > 3) {
                throw new \RuntimeException("Oracle substr() expects 2 or 3 arguments: {$expr}");
            }
            $value = (string) self::evaluateExpression($args[0], $locals);
            $offset = (int) self::evaluateExpression($args[1], $locals);
            return count($args) === 3
                ? substr($value, $offset, (int) self::evaluateExpression($args[2], $locals))
                : substr($value, $offset);
        }

        if (preg_match('/^str_replace\s*\((.+)\)$/is', $expr, $m)) {
            $args = self::splitTopLevelList($m[1], ',');
            if (count($args) !== 3) {
                throw new \RuntimeException("Oracle str_replace() expects 3 arguments: {$expr}");
            }
            return str_replace(
                self::evaluateExpression($args[0], $locals),
                self::evaluateExpression($args[1], $locals),
                self::evaluateExpression($args[2], $locals)
            );
        }

        if (preg_match('/^base64_encode\s*\((.+)\)$/i', $expr, $m)) {
            return base64_encode((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^md5\s*\((.+)\)$/i', $expr, $m)) {
            return md5((string) self::evaluateExpression($m[1], $locals));
        }

        if (preg_match('/^count\s*\((.+)\)$/i', $expr, $m)) {
            $value = self::evaluateExpression($m[1], $locals);
            if (!is_array($value) && !$value instanceof \Countable) {
                throw new \RuntimeException("Oracle count() expects countable value: {$expr}");
            }
            return count($value);
        }

        if (preg_match('/^array_sum\s*\((.+)\)$/i', $expr, $m)) {
            $value = self::evaluateExpression($m[1], $locals);
            if (!is_array($value)) {
                throw new \RuntimeException("Oracle array_sum() expects array: {$expr}");
            }
            return array_sum($value);
        }

        if (preg_match('/^array_product\s*\((.+)\)$/i', $expr, $m)) {
            $value = self::evaluateExpression($m[1], $locals);
            if (!is_array($value)) {
                throw new \RuntimeException("Oracle array_product() expects array: {$expr}");
            }
            return array_product($value);
        }

        if (preg_match('/^implode\s*\((.+)\)$/is', $expr, $m)) {
            $args = self::splitTopLevelList($m[1], ',');
            if (count($args) === 1) {
                $values = self::evaluateExpression($args[0], $locals);
                if (!is_array($values)) {
                    throw new \RuntimeException("Oracle implode() expects array: {$expr}");
                }
                return implode('', $values);
            }
            if (count($args) === 2) {
                $glue = (string) self::evaluateExpression($args[0], $locals);
                $values = self::evaluateExpression($args[1], $locals);
                if (!is_array($values)) {
                    throw new \RuntimeException("Oracle implode() expects array: {$expr}");
                }
                return implode($glue, $values);
            }
            throw new \RuntimeException("Oracle implode() expects 1 or 2 arguments: {$expr}");
        }

        if (preg_match('/^max\s*\((.+)\)$/is', $expr, $m)) {
            $args = self::splitTopLevelList($m[1], ',');
            if (count($args) === 1) {
                $values = self::evaluateExpression($args[0], $locals);
                if (!is_array($values)) {
                    throw new \RuntimeException("Oracle max() single argument must be array: {$expr}");
                }
                return max($values);
            }
            return max(array_map(static fn(string $arg): mixed => self::evaluateExpression($arg, $locals), $args));
        }

        if (preg_match('/^min\s*\((.+)\)$/is', $expr, $m)) {
            $args = self::splitTopLevelList($m[1], ',');
            if (count($args) === 1) {
                $values = self::evaluateExpression($args[0], $locals);
                if (!is_array($values)) {
                    throw new \RuntimeException("Oracle min() single argument must be array: {$expr}");
                }
                return min($values);
            }
            return min(array_map(static fn(string $arg): mixed => self::evaluateExpression($arg, $locals), $args));
        }

        if (preg_match('/^json_encode\s*\((.+)\)$/is', $expr, $m)) {
            $value = self::evaluateExpression($m[1], $locals);
            $encoded = json_encode($value);
            if ($encoded === false) {
                throw new \RuntimeException('Oracle json_encode() failed');
            }
            return $encoded;
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        if (preg_match('/^\'(.*)\'$/s', $expr, $m)) {
            return stripcslashes($m[1]);
        }

        if (preg_match('/^"(.*)"$/s', $expr, $m)) {
            return self::interpolateDoubleQuotedString($m[1], $locals);
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
                    throw new \RuntimeException("Unsupported Oracle array key: {$keyExpr}");
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
    private static function interpolateDoubleQuotedString(string $body, array &$locals): string
    {
        $placeholder = "\0JINX_ESCAPED_DOLLAR\0";
        $body = str_replace('\\$', $placeholder, $body);
        $bracedValues = [];

        $body = preg_replace_callback(
            '/\{\s*(\$[A-Za-z_]\w*(?:(?:\[[^\]]+\])|(?:->\w+))*)\s*\}/',
            static function (array $m) use (&$locals, &$bracedValues): string {
                $token = "\0JINX_BRACED_INTERP_" . count($bracedValues) . "\0";
                $bracedValues[$token] = (string) self::evaluateInterpolatedVariable($m[1], $locals);

                return $token;
            },
            $body
        ) ?? $body;

        $body = preg_replace_callback(
            '/\$([A-Za-z_]\w*)(?:\[([^\]]+)\]|->(\w+))?/',
            static function (array $m) use (&$locals): string {
                $path = '$' . $m[1];
                if (($m[2] ?? '') !== '') {
                    $path .= '[' . $m[2] . ']';
                } elseif (($m[3] ?? '') !== '') {
                    $path .= '->' . $m[3];
                }

                return (string) self::evaluateInterpolatedVariable($path, $locals);
            },
            $body
        ) ?? $body;

        $body = strtr($body, $bracedValues);
        $body = str_replace($placeholder, '$', $body);

        return self::decodeDoubleQuotedEscapes($body);
    }

    /** @param array<string,mixed> $locals */
    private static function evaluateInterpolatedVariable(string $path, array &$locals): mixed
    {
        if (!preg_match('/^\$(\w+)/', $path, $m)) {
            throw new \RuntimeException("Unsupported Oracle interpolation segment: {$path}");
        }

        $value = $locals[$m[1]] ?? null;
        if (!array_key_exists($m[1], $locals)) {
            throw new \RuntimeException("Missing Oracle interpolated local: {$path}");
        }

        $rest = substr($path, strlen($m[0]));
        while ($rest !== '') {
            if (preg_match('/^\[([^\]]+)\]/', $rest, $dim)) {
                if (!is_array($value)) {
                    throw new \RuntimeException("Interpolated local is not array: {$path}");
                }
                $key = self::interpolationKey($dim[1], $locals);
                if (!array_key_exists($key, $value)) {
                    throw new \RuntimeException("Missing Oracle interpolated array dimension: {$path}");
                }
                $value = $value[$key];
                $rest = substr($rest, strlen($dim[0]));
                continue;
            }

            if (preg_match('/^->(\w+)/', $rest, $prop)) {
                if (!is_object($value) || !isset($value->{$prop[1]})) {
                    throw new \RuntimeException("Missing Oracle interpolated object property: {$path}");
                }
                $value = $value->{$prop[1]};
                $rest = substr($rest, strlen($prop[0]));
                continue;
            }

            throw new \RuntimeException("Unsupported Oracle interpolation tail: {$path}");
        }

        return $value;
    }

    /** @param array<string,mixed> $locals */
    private static function interpolationKey(string $raw, array &$locals): int|string
    {
        $key = stripcslashes(trim($raw));
        if (preg_match('/^-?\d+$/', $key)) {
            return (int) $key;
        }
        if (preg_match('/^\'(.*)\'$/s', $key, $m)) {
            return stripcslashes($m[1]);
        }
        if (preg_match('/^"(.*)"$/s', $key, $m)) {
            return self::interpolateDoubleQuotedString($m[1], $locals);
        }
        if (preg_match('/^\$(\w+)$/', $key, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle interpolation key local: {$raw}");
            }
            return (string) $locals[$m[1]];
        }

        return $key;
    }

    private static function decodeDoubleQuotedEscapes(string $body): string
    {
        return strtr($body, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\v' => "\v",
            '\\e' => "\e",
            '\\f' => "\f",
            '\\\\' => '\\',
            '\\"' => '"',
        ]);
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
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0 && $i < $length - 1) {
                    return false;
                }
                if ($depth < 0) {
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

    private static function toPhpBool(mixed $value): bool
    {
        return !(
            $value === null ||
            $value === false ||
            $value === 0 ||
            $value === 0.0 ||
            $value === '' ||
            $value === '0' ||
            $value === []
        );
    }

    /**
     * Split on the rightmost top-level operator in a precedence group.
     * Using the rightmost operator preserves left associativity when the
     * recursive evaluator processes the left-hand expression.
     *
     * @param list<string> $operators
     * @return array{0:string,1:string,2:string}|null
     */
    private static function splitTopLevelOperators(string $expr, array $operators): ?array
    {
        usort($operators, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $quote = null;
        $depth = 0;
        $match = null;
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
                if (substr($expr, $i, strlen($operator)) !== $operator) {
                    continue;
                }

                // A leading + or - is unary, not a binary split point.
                if (($operator === '+' || $operator === '-') && trim(substr($expr, 0, $i)) === '') {
                    continue;
                }

                $match = [$i, $operator];
                $i += strlen($operator) - 1;
                break;
            }
        }

        if ($match === null) {
            return null;
        }

        [$position, $operator] = $match;

        return [
            substr($expr, 0, $position),
            $operator,
            substr($expr, $position + strlen($operator)),
        ];
    }

}
