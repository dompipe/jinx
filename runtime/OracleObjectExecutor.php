<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Narrow Oracle executor for first object/class PHP subset.
 *
 * Supports the object fixture surface only: one class, constructor, methods,
 * $this property writes/fetches, method calls, echo, and return. Unsupported
 * syntax throws instead of falling through to PHP.
 */
final class OracleObjectExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int}
     */
    public static function execute(array $program): array
    {
        $sourcePath = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('Oracle object execution requires a source file');
        }

        $source = (string) file_get_contents($sourcePath);
        [$class, $mainSource] = self::extractSingleClass($source);

        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements($mainSource) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^declare\s*\(/i', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*new\s+(\w+)\s*\((.*)\)$/i', $statement, $m)) {
                $object = [
                    '__class' => $m[2],
                    'props' => [],
                ];
                if ($m[2] !== $class['name']) {
                    throw new \RuntimeException("Unsupported Oracle class instantiation: {$statement}");
                }
                self::callMethod($class, $object, '__construct', self::evaluateArguments($m[3], $locals, null));
                $locals[$m[1]] = $object;
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)->(\w+)\s*\((.*)\)$/', $statement, $m)) {
                $object = $locals[$m[2]] ?? null;
                if (!is_array($object)) {
                    throw new \RuntimeException("Oracle method call target is not object: {$statement}");
                }
                $locals[$m[1]] = self::callMethod($class, $object, $m[3], self::evaluateArguments($m[4], $locals, null));
                $locals[$m[2]] = $object;
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)->(\w+)\s*\((.*)\)$/', $statement, $m)) {
                $object = $locals[$m[1]] ?? null;
                if (!is_array($object)) {
                    throw new \RuntimeException("Oracle method call target is not object: {$statement}");
                }
                self::callMethod($class, $object, $m[2], self::evaluateArguments($m[3], $locals, null));
                $locals[$m[1]] = $object;
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(.+)$/', $statement, $m)) {
                $locals[$m[1]] = self::evaluate($m[2], $locals, null);
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+\$(\w+)->(\w+)\s*\((.*)\)\s*(?:\.\s*(.+))?$/i', $statement, $m)) {
                $object = $locals[$m[1]] ?? null;
                if (!is_array($object)) {
                    throw new \RuntimeException("Oracle method call target is not object: {$statement}");
                }
                $value = self::callMethod($class, $object, $m[2], self::evaluateArguments($m[3], $locals, null));
                $locals[$m[1]] = $object;
                if (isset($m[4]) && trim($m[4]) !== '') {
                    $value = self::phpString($value) . self::phpString(self::evaluate($m[4], $locals, null));
                }
                $output .= self::phpString($value);
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/i', $statement, $m)) {
                $output .= self::phpString(self::evaluate($m[1], $locals, null));
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                $return = self::evaluate($m[1], $locals, null);
                $executed++;

                return self::result($output, $return, $executed);
            }

            throw new \RuntimeException("Unsupported Oracle object statement: {$statement}");
        }

        return self::result($output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'object-basics',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /**
     * @return array{0:array{name:string,methods:array<string,array{params:list<string>,promoted:list<string>,body:string}>},1:string}
     */
    private static function extractSingleClass(string $source): array
    {
        if (!preg_match('/(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)\s*\{/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('Oracle object fixture must contain one class');
        }

        $name = $m[1][0];
        $open = strpos($source, '{', $m[0][1]);
        if ($open === false) {
            throw new \RuntimeException('Oracle object class body missing opening brace');
        }
        $close = self::findMatchingBrace($source, $open);
        $body = substr($source, $open + 1, $close - $open - 1);
        $main = substr($source, 0, $m[0][1]) . substr($source, $close + 1);

        return [[
            'name' => $name,
            'methods' => self::extractMethods($body),
        ], $main];
    }

    /** @return array<string,array{params:list<string>,promoted:list<string>,body:string}> */
    private static function extractMethods(string $classBody): array
    {
        $methods = [];
        $offset = 0;

        while (preg_match(
            '/(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(([^)]*)\)\s*(?::\s*[?A-Za-z_\\\\][A-Za-z0-9_\\\\|&?]*)?\s*\{/i',
            $classBody,
            $m,
            PREG_OFFSET_CAPTURE,
            $offset
        )) {
            $method = $m[1][0];
            $params = [];
            $promoted = [];
            foreach (array_filter(array_map('trim', explode(',', $m[2][0]))) as $param) {
                if (preg_match('/\$(\w+)/', $param, $pm)) {
                    $params[] = $pm[1];
                    if (preg_match('/\b(?:public|protected|private)\b/', $param)) {
                        $promoted[] = $pm[1];
                    }
                }
            }
            $open = strpos($classBody, '{', $m[0][1]);
            if ($open === false) {
                throw new \RuntimeException("Oracle object method missing body: {$method}");
            }
            $close = self::findMatchingBrace($classBody, $open);
            $methods[$method] = [
                'params' => $params,
                'promoted' => $promoted,
                'body' => substr($classBody, $open + 1, $close - $open - 1),
            ];
            $offset = $close + 1;
        }

        return $methods;
    }

    /** @param array{name:string,methods:array<string,array{params:list<string>,promoted:list<string>,body:string}>} $class */
    private static function callMethod(array $class, array &$object, string $method, array $args): mixed
    {
        if (!isset($class['methods'][$method])) {
            throw new \RuntimeException("Unknown Oracle object method: {$method}");
        }

        $definition = $class['methods'][$method];
        $locals = [];
        foreach ($definition['params'] as $i => $param) {
            $locals[$param] = $args[$i] ?? null;
        }

        foreach ($definition['promoted'] ?? [] as $param) {
            $object['props'][$param] = $locals[$param] ?? null;
        }

        foreach (self::splitStatements($definition['body']) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            if (preg_match('/^\$this->(\w+)\s*=\s*(.+)$/', $statement, $m)) {
                $object['props'][$m[1]] = self::evaluate($m[2], $locals, $object);
                continue;
            }

            if (preg_match('/^\$this->(\w+)\s*\+=\s*(.+)$/', $statement, $m)) {
                $object['props'][$m[1]] = ($object['props'][$m[1]] ?? 0) + self::evaluate($m[2], $locals, $object);
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                return self::evaluate($m[1], $locals, $object);
            }

            throw new \RuntimeException("Unsupported Oracle object method statement: {$statement}");
        }

        return null;
    }

    /** @param array<string,mixed> $locals */
    private static function evaluate(string $expression, array $locals, ?array $object): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluate(substr($expr, 1, -1), $locals, $object);
        }

        $concat = self::splitTopLevel($expr, '.');
        if (count($concat) > 1) {
            return implode('', array_map(static fn (string $part): string => self::phpString(self::evaluate($part, $locals, $object)), $concat));
        }

        $sum = self::splitTopLevel($expr, '+');
        if (count($sum) > 1) {
            return array_reduce($sum, static fn (mixed $carry, string $part): mixed => $carry + self::evaluate($part, $locals, $object), 0);
        }

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            $args = self::evaluateArguments($m[2], $locals, $object);
            return match (strtolower($m[1])) {
                'strtoupper' => strtoupper((string) ($args[0] ?? '')),
                default => throw new \RuntimeException("Unsupported Oracle object builtin: {$m[1]}"),
            };
        }

        if (preg_match('/^\$this->(\w+)$/', $expr, $m)) {
            return $object['props'][$m[1]] ?? null;
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            return $locals[$m[1]] ?? null;
        }

        if (preg_match('/^([\'\"])(.*)\1$/', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        throw new \RuntimeException("Unsupported Oracle object expression: {$expr}");
    }

    /** @return list<mixed> */
    private static function evaluateArguments(string $body, array $locals, ?array $object): array
    {
        $args = [];
        foreach (self::splitTopLevel($body, ',') as $arg) {
            if (trim($arg) !== '') {
                $args[] = self::evaluate($arg, $locals, $object);
            }
        }
        return $args;
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
        throw new \RuntimeException('Unclosed Oracle object brace');
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
