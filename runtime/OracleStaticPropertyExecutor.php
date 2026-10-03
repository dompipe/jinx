<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven PHP static-property subset from Oracle records. */
final class OracleStaticPropertyExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $sourcePath = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('Oracle static-property execution requires a source file');
        }

        $source = (string) file_get_contents($sourcePath);
        [$classes, $mainSource] = self::extractClasses($source);

        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements($mainSource) as $statement) {
            $statement = trim($statement);
            if (
                $statement === '' ||
                preg_match('/^declare\s*\(/i', $statement) ||
                preg_match('/^error_reporting\s*\(\s*E_ALL\s*\)$/i', $statement)
            ) {
                continue;
            }

            if (preg_match('/^(\w+)::\$(\w+)\s*(\+=|-=|\*=|\/=|%=|\.=)\s*(.+)$/', $statement, $m)) {
                $current = self::getStaticProperty($classes, $m[1], $m[2]);
                $rhs = self::evaluate($m[4], $locals, $classes);
                $value = match ($m[3]) {
                    '+=' => $current + $rhs,
                    '-=' => $current - $rhs,
                    '*=' => $current * $rhs,
                    '/=' => $current / $rhs,
                    '%=' => $current % $rhs,
                    '.=' => self::phpString($current) . self::phpString($rhs),
                    default => throw new \RuntimeException("Unsupported Oracle static-property compound operator: {$m[3]}"),
                };
                self::setStaticProperty($classes, $m[1], $m[2], $value);
                $executed++;
                continue;
            }

            if (preg_match('/^(\w+)::\$(\w+)\s*=\s*(.+)$/', $statement, $m)) {
                self::setStaticProperty($classes, $m[1], $m[2], self::evaluate($m[3], $locals, $classes));
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(.+)$/', $statement, $m)) {
                $locals[$m[1]] = self::evaluate($m[2], $locals, $classes);
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/i', $statement, $m)) {
                $output .= self::phpString(self::evaluate($m[1], $locals, $classes));
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                $return = self::evaluate($m[1], $locals, $classes);
                $executed++;

                return self::result($output, $return, $executed);
            }

            throw new \RuntimeException("Unsupported Oracle static-property statement: {$statement}");
        }

        return self::result($output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'static-properties',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /** @return array{0:array<string,array{name:string,static_props:array<string,mixed>}>,1:string} */
    private static function extractClasses(string $source): array
    {
        $classes = [];
        $main = $source;
        $offset = 0;

        while (preg_match('/class\s+(\w+)\s*\{/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $name = $m[1][0];
            $open = strpos($source, '{', $m[0][1]);
            if ($open === false) {
                throw new \RuntimeException("Oracle static-property class body missing opening brace: {$name}");
            }
            $close = self::findMatchingBrace($source, $open);
            $body = substr($source, $open + 1, $close - $open - 1);
            $classes[$name] = [
                'name' => $name,
                'static_props' => self::extractStaticProperties($body),
            ];
            $main = str_replace(substr($source, $m[0][1], $close - $m[0][1] + 1), '', $main);
            $offset = $close + 1;
        }

        if ($classes === []) {
            throw new \RuntimeException('Oracle static-property fixture must contain classes');
        }

        return [$classes, $main];
    }

    /** @return array<string,mixed> */
    private static function extractStaticProperties(string $classBody): array
    {
        $props = [];
        foreach (self::splitStatements($classBody) as $statement) {
            if (preg_match('/^(?:public|protected|private)?\s*static\s+\$(\w+)(?:\s*=\s*(.+))?$/i', trim($statement), $m)) {
                $props[$m[1]] = array_key_exists(2, $m) ? self::literalValue($m[2]) : null;
            }
        }

        return $props;
    }

    /** @param array<string,mixed> $locals @param array<string,array{name:string,static_props:array<string,mixed>}> $classes */
    private static function evaluate(string $expression, array $locals, array $classes): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals, $classes);
        }

        $concat = self::splitTopLevel($expr, '.');
        if (count($concat) > 1) {
            return implode('', array_map(static fn (string $part): string => self::phpString(self::evaluate($part, $locals, $classes)), $concat));
        }

        $sum = self::splitTopLevel($expr, '+');
        if (count($sum) > 1) {
            return array_reduce($sum, static fn (mixed $carry, string $part): mixed => $carry + self::evaluate($part, $locals, $classes), 0);
        }

        if (preg_match('/^(\w+)::\$(\w+)$/', $expr, $m)) {
            return self::getStaticProperty($classes, $m[1], $m[2]);
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            return $locals[$m[1]] ?? null;
        }

        return self::literalValue($expr);
    }

    private static function literalValue(string $expr): mixed
    {
        $expr = trim(rtrim(trim($expr), ';'));
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

        throw new \RuntimeException("Unsupported Oracle static-property literal: {$expr}");
    }

    /** @param array<string,array{name:string,static_props:array<string,mixed>}> $classes */
    private static function getStaticProperty(array $classes, string $className, string $property): mixed
    {
        if (!array_key_exists($className, $classes) || !array_key_exists($property, $classes[$className]['static_props'])) {
            throw new \RuntimeException("Missing Oracle static property: {$className}::\${$property}");
        }

        return $classes[$className]['static_props'][$property];
    }

    /** @param array<string,array{name:string,static_props:array<string,mixed>}> $classes */
    private static function setStaticProperty(array &$classes, string $className, string $property, mixed $value): void
    {
        if (!array_key_exists($className, $classes)) {
            throw new \RuntimeException("Missing Oracle static property class: {$className}");
        }

        $classes[$className]['static_props'][$property] = $value;
    }

    /** @return list<string> */
    private static function splitStatements(string $source): array
    {
        $parts = [];
        foreach (self::splitTopLevel($source, ';') as $part) {
            $part = trim($part);
            if ($part !== '' && !str_starts_with($part, '<?php')) {
                $parts[] = preg_replace('/^<\?php\s*/', '', $part) ?: $part;
            }
        }

        return $parts;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $expr, string $delimiter): array
    {
        $parts = [];
        $start = 0;
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
            if ($depth === 0 && substr($expr, $i, strlen($delimiter)) === $delimiter) {
                $parts[] = substr($expr, $start, $i - $start);
                $start = $i + strlen($delimiter);
                $i += strlen($delimiter) - 1;
            }
        }
        $parts[] = substr($expr, $start);

        return $parts;
    }

    private static function findMatchingBrace(string $source, int $open): int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
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
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        throw new \RuntimeException('Unclosed Oracle static-property brace');
    }

    private static function isWrappedInOuterParens(string $expr): bool
    {
        return str_starts_with($expr, '(') && str_ends_with($expr, ')');
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
}
