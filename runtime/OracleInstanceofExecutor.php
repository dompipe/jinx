<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven PHP instanceof/class-identity subset from Oracle records. */
final class OracleInstanceofExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $sourcePath = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('Oracle instanceof execution requires a source file');
        }

        $source = (string) file_get_contents($sourcePath);
        [$classes, $mainSource] = self::extractClasses($source);

        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements($mainSource) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^declare\s*\(/i', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*new\s+(\w+)\s*\((.*)\)$/i', $statement, $m)) {
                if (!isset($classes[$m[2]])) {
                    throw new \RuntimeException("Unsupported Oracle instanceof class instantiation: {$statement}");
                }
                $locals[$m[1]] = [
                    '__class' => $m[2],
                    'props' => [],
                ];
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\s+instanceof\s+(\w+)$/i', $statement, $m)) {
                $object = $locals[$m[2]] ?? null;
                $locals[$m[1]] = is_array($object) && self::objectIsA($classes, (string) ($object['__class'] ?? ''), $m[3]);
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(.+)$/', $statement, $m)) {
                $locals[$m[1]] = self::evaluate($m[2], $locals);
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/i', $statement, $m)) {
                $output .= self::phpString(self::evaluate($m[1], $locals));
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                $return = self::evaluate($m[1], $locals);
                $executed++;

                return self::result($output, $return, $executed);
            }

            throw new \RuntimeException("Unsupported Oracle instanceof statement: {$statement}");
        }

        return self::result($output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'instanceof-checks',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /** @return array{0:array<string,array{name:string,parent:string|null}>,1:string} */
    private static function extractClasses(string $source): array
    {
        $classes = [];
        $main = $source;
        $offset = 0;

        while (preg_match('/class\s+(\w+)(?:\s+extends\s+(\w+))?\s*\{/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $name = $m[1][0];
            $parent = isset($m[2][0]) && $m[2][0] !== '' ? $m[2][0] : null;
            $open = strpos($source, '{', $m[0][1]);
            if ($open === false) {
                throw new \RuntimeException("Oracle instanceof class body missing opening brace: {$name}");
            }
            $close = self::findMatchingBrace($source, $open);
            $classes[$name] = [
                'name' => $name,
                'parent' => $parent,
            ];
            $main = str_replace(substr($source, $m[0][1], $close - $m[0][1] + 1), '', $main);
            $offset = $close + 1;
        }

        if ($classes === []) {
            throw new \RuntimeException('Oracle instanceof fixture must contain classes');
        }

        return [$classes, $main];
    }

    /** @param array<string,array{name:string,parent:string|null}> $classes */
    private static function objectIsA(array $classes, string $className, string $target): bool
    {
        while ($className !== '') {
            if ($className === $target) {
                return true;
            }
            $className = (string) ($classes[$className]['parent'] ?? '');
        }

        return false;
    }

    /** @param array<string,mixed> $locals */
    private static function evaluate(string $expression, array $locals): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals);
        }

        $concat = self::splitTopLevel($expr, '.');
        if (count($concat) > 1) {
            return implode('', array_map(static fn (string $part): string => self::phpString(self::evaluate($part, $locals)), $concat));
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            return $locals[$m[1]] ?? null;
        }

        if (preg_match('/^([\'"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
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

        throw new \RuntimeException("Unsupported Oracle instanceof expression: {$expr}");
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

        throw new \RuntimeException('Unclosed Oracle instanceof brace');
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
