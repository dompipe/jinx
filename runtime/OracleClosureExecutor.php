<?php

declare(strict_types=1);

namespace jinx\oracle;

/** Executes a proven PHP closure/arrow-function subset from Oracle records. */
final class OracleClosureExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $sourcePath = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('Oracle closure execution requires a source file');
        }

        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements((string) file_get_contents($sourcePath)) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^declare\s*\(/i', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*function\s*\(([^)]*)\)\s*(?:use\s*\(([^)]*)\)\s*)?\{\s*return\s+(.+);\s*\}$/is', $statement, $m)) {
                $locals[$m[1]] = [
                    '__closure' => true,
                    'params' => self::parseParamNames($m[2]),
                    'captures' => self::captureLocals($m[3] ?? '', $locals),
                    'body' => trim($m[4]),
                ];
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*fn\s*\(([^)]*)\)\s*=>\s*(.+)$/is', $statement, $m)) {
                $locals[$m[1]] = [
                    '__closure' => true,
                    'params' => self::parseParamNames($m[2]),
                    'captures' => $locals,
                    'body' => trim($m[3]),
                ];
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\s*\((.*)\)$/s', $statement, $m)) {
                $locals[$m[1]] = self::callClosure($locals[$m[2]] ?? null, self::evaluateArguments($m[3], $locals));
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(.+)$/s', $statement, $m)) {
                $locals[$m[1]] = self::evaluate($m[2], $locals);
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/is', $statement, $m)) {
                $output .= self::phpString(self::evaluate($m[1], $locals));
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/is', $statement, $m)) {
                $return = self::evaluate($m[1], $locals);
                $executed++;

                return self::result($output, $return, $executed);
            }

            throw new \RuntimeException("Unsupported Oracle closure statement: {$statement}");
        }

        return self::result($output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'closures',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /** @return list<string> */
    private static function parseParamNames(string $params): array
    {
        $names = [];
        foreach (array_filter(array_map('trim', explode(',', $params))) as $param) {
            if (!preg_match('/\$(\w+)/', $param, $m)) {
                throw new \RuntimeException("Unsupported Oracle closure parameter: {$param}");
            }
            $names[] = $m[1];
        }

        return $names;
    }

    /** @param array<string,mixed> $locals @return array<string,mixed> */
    private static function captureLocals(string $use, array $locals): array
    {
        $captures = [];
        foreach (array_filter(array_map('trim', explode(',', $use))) as $part) {
            if (!preg_match('/^\$(\w+)$/', $part, $m)) {
                throw new \RuntimeException("Unsupported Oracle closure use capture: {$part}");
            }
            $captures[$m[1]] = $locals[$m[1]] ?? null;
        }

        return $captures;
    }

    /** @param list<mixed> $args */
    private static function callClosure(mixed $closure, array $args): mixed
    {
        if (!is_array($closure) || ($closure['__closure'] ?? false) !== true) {
            throw new \RuntimeException('Oracle closure call target is not a closure');
        }

        $locals = (array) ($closure['captures'] ?? []);
        foreach ((array) ($closure['params'] ?? []) as $i => $param) {
            $locals[(string) $param] = $args[$i] ?? null;
        }

        return self::evaluate((string) ($closure['body'] ?? ''), $locals);
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

        if (preg_match('/^(\w+)\s*\((.*)\)$/s', $expr, $m)) {
            $args = self::evaluateArguments($m[2], $locals);
            return match (strtolower($m[1])) {
                'strtoupper' => strtoupper((string) ($args[0] ?? '')),
                'strlen' => strlen((string) ($args[0] ?? '')),
                default => throw new \RuntimeException("Unsupported Oracle closure builtin: {$m[1]}"),
            };
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            return $locals[$m[1]] ?? null;
        }

        if (preg_match('/^([\'"])(.*)\1$/s', $expr, $m)) {
            return stripcslashes($m[2]);
        }

        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }

        throw new \RuntimeException("Unsupported Oracle closure expression: {$expr}");
    }

    /** @return list<mixed> */
    private static function evaluateArguments(string $body, array $locals): array
    {
        $args = [];
        foreach (self::splitTopLevel($body, ',') as $arg) {
            if (trim($arg) !== '') {
                $args[] = self::evaluate($arg, $locals);
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
