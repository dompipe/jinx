<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes a larger arbitrary Zend-shaped function subset from Oracle input.
 *
 * Covered shape: namespace/use metadata, globals, static locals, arrays,
 * isset/empty, if, switch/break/default, do/while/continue, match, unset,
 * throw guard, function call, echo, and return.
 */
final class OracleZendArbitraryExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $sourcePath = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('Oracle arbitrary Zend execution requires a source file');
        }

        $source = (string) file_get_contents($sourcePath);
        [$functions, $main] = self::extractFunctions($source);
        $locals = [];
        $globals =& $locals;
        $staticLocals = [];
        $output = '';
        $executed = 0;

        $return = self::executeStatements(
            self::splitStatements($main),
            $locals,
            $globals,
            $staticLocals,
            $functions,
            $output,
            $executed,
            null
        );

        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'zend-arbitrary',
            'output' => $output,
            'return' => $return['returned'] ? $return['value'] : null,
            'executed_ops' => $executed,
        ];
    }

    /** @return array{0:array<string,array{params:list<string>,body:string}>,1:string} */
    private static function extractFunctions(string $source): array
    {
        $functions = [];
        $main = $source;
        $offset = 0;

        while (preg_match('/function\s+(\w+)\s*\(([^)]*)\)\s*(?::\s*[^{]+)?\s*\{/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $name = $m[1][0];
            $params = [];
            foreach (array_filter(array_map('trim', explode(',', $m[2][0]))) as $param) {
                if (preg_match('/\$(\w+)/', $param, $pm)) {
                    $params[] = $pm[1];
                }
            }
            $open = strpos($source, '{', $m[0][1]);
            if ($open === false) {
                throw new \RuntimeException("Oracle arbitrary function missing body: {$name}");
            }
            $close = self::findMatchingBrace($source, $open);
            $full = substr($source, $m[0][1], $close - $m[0][1] + 1);
            $functions[strtolower($name)] = [
                'params' => $params,
                'body' => substr($source, $open + 1, $close - $open - 1),
            ];
            $main = str_replace($full, '', $main);
            $offset = $close + 1;
        }

        return [$functions, $main];
    }

    /**
     * @param list<string> $statements
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $globals
     * @param array<string,array<string,mixed>> $staticLocals
     * @param array<string,array{params:list<string>,body:string}> $functions
     * @return array{returned:bool,value:mixed}
     */
    private static function executeStatements(array $statements, array &$locals, array &$globals, array &$staticLocals, array $functions, string &$output, int &$executed, ?string $functionName): array
    {
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^(?:<\?php\s*)?declare\s*\(/i', $statement)) {
                continue;
            }
            if (preg_match('/^namespace\s+/i', $statement) || preg_match('/^use\s+/i', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(.+)$/s', $statement, $m)) {
                $locals[$m[1]] = self::evaluate($m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                $executed++;
                continue;
            }

            if (preg_match('/^global\s+\$(\w+)$/i', $statement, $m)) {
                $globals[$m[1]] ??= null;
                $locals[$m[1]] =& $globals[$m[1]];
                $executed++;
                continue;
            }

            if (preg_match('/^static\s+\$(\w+)\s*=\s*(.+)$/i', $statement, $m)) {
                if ($functionName === null) {
                    throw new \RuntimeException('Oracle static local outside function');
                }
                $bucket = strtolower($functionName);
                $staticLocals[$bucket] ??= [];
                if (!array_key_exists($m[1], $staticLocals[$bucket])) {
                    $staticLocals[$bucket][$m[1]] = self::evaluate($m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                }
                $locals[$m[1]] =& $staticLocals[$bucket][$m[1]];
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\+\+$/', $statement, $m)) {
                $locals[$m[1]] = ($locals[$m[1]] ?? 0) + 1;
                $executed++;
                continue;
            }

            if (preg_match('/^if\s*\((.+?)\)\s*\{(.*)\}$/is', $statement, $m)) {
                $executed++;
                if (self::evaluate($m[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName)) {
                    $nested = self::executeStatements(self::splitStatements($m[2]), $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                    if ($nested['returned']) {
                        return $nested;
                    }
                }
                continue;
            }

            if (preg_match('/^switch\s*\((.+?)\)\s*\{(.*)\}$/is', $statement, $m)) {
                self::executeSwitch($m[1], $m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                $executed++;
                continue;
            }

            if (preg_match('/^do\s*\{(.*)\}\s*while\s*\((.+)\)$/is', $statement, $m)) {
                do {
                    foreach (self::splitStatements($m[1]) as $inner) {
                        $inner = trim($inner);
                        if ($inner === 'continue') {
                            $executed++;
                            continue 2;
                        }
                        self::executeStatements([$inner], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                    }
                } while (self::evaluate($m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName));
                $executed++;
                continue;
            }

            if (preg_match('/^unset\s*\((.+)\)$/i', $statement, $m)) {
                self::executeUnset($m[1], $locals);
                $executed++;
                continue;
            }

            if (preg_match('/^throw\s+new\s+([A-Za-z_\\\\]\w*(?:\\\\\w+)*)\s*\((.+)\)$/i', $statement, $m)) {
                $executed++;
                throw new \RuntimeException((string) self::evaluate($m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName));
            }

            if (preg_match('/^echo\s+(.+)$/i', $statement, $m)) {
                $output .= self::phpString(self::evaluate($m[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName));
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                $executed++;
                return [
                    'returned' => true,
                    'value' => self::evaluate($m[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName),
                ];
            }

            throw new \RuntimeException("Unsupported Oracle arbitrary statement: {$statement}");
        }

        return ['returned' => false, 'value' => null];
    }

    /**
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $globals
     * @param array<string,array<string,mixed>> $staticLocals
     * @param array<string,array{params:list<string>,body:string}> $functions
     */
    private static function executeSwitch(string $condition, string $body, array &$locals, array &$globals, array &$staticLocals, array $functions, string &$output, int &$executed, ?string $functionName): void
    {
        $value = self::evaluate($condition, $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
        $default = null;
        $matched = null;

        preg_match_all('/case\s+([^:]+):(.+?)(?=case\s+|default\s*:|$)/is', $body, $cases, PREG_SET_ORDER);
        foreach ($cases as $case) {
            if (self::evaluate($case[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName) === $value) {
                $matched = $case[2];
                break;
            }
        }
        if ($matched === null && preg_match('/default\s*:(.+)$/is', $body, $m)) {
            $default = $m[1];
        }

        $selected = $matched ?? $default;
        if ($selected === null) {
            return;
        }
        foreach (self::splitStatements($selected) as $statement) {
            if (trim($statement) === 'break') {
                $executed++;
                break;
            }
            self::executeStatements([$statement], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
        }
    }

    /** @param array<string,mixed> $locals */
    private static function executeUnset(string $target, array &$locals): void
    {
        if (preg_match('/^\$(\w+)\[([^\]]+)\]$/', trim($target), $m)) {
            $key = self::evaluateSimpleKey($m[2]);
            unset($locals[$m[1]][$key]);
            return;
        }

        throw new \RuntimeException("Unsupported Oracle unset target: {$target}");
    }

    /**
     * @param array<string,mixed> $locals
     * @param array<string,mixed> $globals
     * @param array<string,array<string,mixed>> $staticLocals
     * @param array<string,array{params:list<string>,body:string}> $functions
     */
    private static function evaluate(string $expression, array &$locals, array &$globals, array &$staticLocals, array $functions, string &$output, int &$executed, ?string $functionName): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (preg_match('/^\((string)\)\s*(.+)$/i', $expr, $m)) {
            return (string) self::evaluate($m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
        }
        if (preg_match('/^isset\s*\((.+)\)\s*&&\s*!\s*empty\s*\((.+)\)$/i', $expr, $m)) {
            return self::isSetExpression($m[1], $locals) && !self::isEmptyExpression($m[2], $locals);
        }
        if (preg_match('/^match\s*\((.+)\)\s*\{(.*)\}$/is', $expr, $m)) {
            return self::evaluateMatch($m[1], $m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
        }

        foreach (['===', '<', '??', '+', '.'] as $operator) {
            $parts = self::splitTopLevelBinary($expr, $operator);
            if ($parts !== null) {
                $left = self::evaluate($parts[0], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                if ($operator === '??') {
                    return $left ?? self::evaluate($parts[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                }
                $right = self::evaluate($parts[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                return match ($operator) {
                    '===' => $left === $right,
                    '<' => $left < $right,
                    '+' => $left + $right,
                    '.' => self::phpString($left) . self::phpString($right),
                };
            }
        }

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            $name = strtolower($m[1]);
            if (!isset($functions[$name])) {
                throw new \RuntimeException("Unknown Oracle arbitrary function: {$m[1]}");
            }
            $args = self::evaluateArguments($m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
            $callLocals = [];
            foreach ($functions[$name]['params'] as $i => $param) {
                $callLocals[$param] = $args[$i] ?? null;
            }
            $result = self::executeStatements(self::splitStatements($functions[$name]['body']), $callLocals, $globals, $staticLocals, $functions, $output, $executed, $name);
            return $result['value'];
        }

        if (preg_match('/^\[(.*)\]$/s', $expr, $m)) {
            return self::evaluateArrayLiteral($m[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
        }
        if (preg_match('/^\$(\w+)\[([^\]]+)\]$/', $expr, $m)) {
            $key = self::evaluateSimpleKey($m[2]);
            return $locals[$m[1]][$key] ?? null;
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
        if ($expr === 'null') {
            return null;
        }

        throw new \RuntimeException("Unsupported Oracle arbitrary expression: {$expr}");
    }

    private static function evaluateMatch(string $condition, string $body, array &$locals, array &$globals, array &$staticLocals, array $functions, string &$output, int &$executed, ?string $functionName): mixed
    {
        $value = self::evaluate($condition, $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
        foreach (self::splitTopLevel($body, ',') as $arm) {
            $arm = trim($arm);
            if ($arm === '') {
                continue;
            }
            if (!preg_match('/^(.+?)\s*=>\s*(.+)$/s', $arm, $m)) {
                throw new \RuntimeException("Unsupported Oracle match arm: {$arm}");
            }
            if (trim($m[1]) === 'default' || self::evaluate($m[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName) === $value) {
                return self::evaluate($m[2], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
            }
        }

        throw new \RuntimeException('Oracle match did not select an arm');
    }

    private static function evaluateArrayLiteral(string $body, array &$locals, array &$globals, array &$staticLocals, array $functions, string &$output, int &$executed, ?string $functionName): array
    {
        $out = [];
        foreach (self::splitTopLevel($body, ',') as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $pair = self::splitTopLevelBinary($entry, '=>');
            if ($pair === null) {
                $out[] = self::evaluate($entry, $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
                continue;
            }
            $out[self::evaluate($pair[0], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName)] =
                self::evaluate($pair[1], $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
        }
        return $out;
    }

    private static function isSetExpression(string $expr, array $locals): bool
    {
        if (preg_match('/^\$(\w+)\[([^\]]+)\]$/', trim($expr), $m)) {
            return isset($locals[$m[1]][self::evaluateSimpleKey($m[2])]);
        }
        return false;
    }

    private static function isEmptyExpression(string $expr, array $locals): bool
    {
        if (preg_match('/^\$(\w+)\[([^\]]+)\]$/', trim($expr), $m)) {
            return empty($locals[$m[1]][self::evaluateSimpleKey($m[2])]);
        }
        return true;
    }

    private static function evaluateSimpleKey(string $expr): int|string
    {
        $expr = trim($expr);
        if (preg_match('/^([\'"])(.*)\1$/s', $expr, $m)) {
            return stripcslashes($m[2]);
        }
        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }
        throw new \RuntimeException("Unsupported Oracle array key: {$expr}");
    }

    /** @return list<mixed> */
    private static function evaluateArguments(string $body, array &$locals, array &$globals, array &$staticLocals, array $functions, string &$output, int &$executed, ?string $functionName): array
    {
        $args = [];
        foreach (self::splitTopLevel($body, ',') as $arg) {
            if (trim($arg) !== '') {
                $args[] = self::evaluate($arg, $locals, $globals, $staticLocals, $functions, $output, $executed, $functionName);
            }
        }
        return $args;
    }

    /** @return list<string> */
    private static function splitStatements(string $source): array
    {
        $clean = preg_replace('/^\s*<\?php\s*/', '', $source) ?? $source;
        $clean = preg_replace('/}\s*while\s*\(([^)]+)\)\s*;/', '} while ($1);', $clean) ?? $clean;
        $clean = preg_replace('/}\s*(?=(?:switch|do|if|unset|echo|return|\$[A-Za-z_]\w*\s*=)\b)/i', "};\n", $clean) ?? $clean;
        $parts = [];
        foreach (self::splitTopLevel($clean, ';') as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = $part;
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
        $delimiterLength = strlen($delimiter);
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
            if ($depth === 0 && substr($expr, $i, $delimiterLength) === $delimiter) {
                $parts[] = substr($expr, $start, $i - $start);
                $start = $i + $delimiterLength;
                $i += $delimiterLength - 1;
            }
        }
        $parts[] = substr($expr, $start);
        return $parts;
    }

    /** @return array{0:string,1:string}|null */
    private static function splitTopLevelBinary(string $expr, string $operator): ?array
    {
        $parts = self::splitTopLevel($expr, $operator);
        if (count($parts) < 2) {
            return null;
        }
        return [trim(array_shift($parts)), trim(implode($operator, $parts))];
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
        throw new \RuntimeException('Unclosed Oracle arbitrary brace');
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
