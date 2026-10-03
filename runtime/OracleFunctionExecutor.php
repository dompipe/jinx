<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes the first proven function/call PHP/Zend subset from Oracle records.
 *
 * This family is deliberately narrow: named user functions, local parameter
 * scope, return values, nested user calls, and a small builtin dispatch used by
 * the parity fixture. Unsupported constructs throw instead of falling back.
 */
final class OracleFunctionExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    public static function execute(array $program): array
    {
        $statements = array_values(array_filter($program['statements'] ?? [], 'is_array'));
        $functions = self::collectFunctions($statements);
        $locals = [];
        $output = '';
        $executed = 0;
        $index = 0;
        $return = self::executeStatements($statements, $index, $locals, $functions, $output, $executed, true);

        return self::result($output, $return['returned'] ? $return['value'] : null, $executed);
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @return array<string,array{params:list<string>,body:list<array<string,mixed>>}>
     */
    private static function collectFunctions(array $statements): array
    {
        $functions = [];
        $index = 0;
        $count = count($statements);

        while ($index < $count) {
            $statement = $statements[$index];
            if (($statement['op'] ?? null) !== 'O_FUNCTION_DECL') {
                $index++;
                continue;
            }

            $source = (string) ($statement['source'] ?? '');
            [$name, $params, $defaults, $variadic] = self::parseFunctionSignature($source);
            $index++;
            $functions[strtolower($name)] = [
                'params' => $params,
                'defaults' => $defaults,
                'variadic' => $variadic,
                'body' => self::collectBlock($statements, $index),
            ];
        }

        return $functions;
    }

    /**
     * @return array{0:string,1:list<string>,2:array<string,string>,3:?string}
     */
    private static function parseFunctionSignature(string $source): array
    {
        if (!preg_match('/^function\s+(\w+)\s*\(([^)]*)\)\s*\{?$/i', trim($source), $m)) {
            throw new \RuntimeException("Unsupported Oracle function declaration: {$source}");
        }

        $params = [];
        $defaults = [];
        $variadic = null;

        foreach (self::splitArguments($m[2]) as $param) {
            if (!preg_match('/\$(\w+)\b/', $param, $pm)) {
                throw new \RuntimeException("Unsupported Oracle function parameter: {$param}");
            }

            $name = $pm[1];

            if (str_contains($param, '...$')) {
                if ($variadic !== null) {
                    throw new \RuntimeException("Oracle function has multiple variadic parameters: {$source}");
                }
                $variadic = $name;
                continue;
            }

            $params[] = $name;

            if (preg_match('/=\s*(.+)$/s', $param, $defaultMatch)) {
                $defaults[$name] = trim($defaultMatch[1]);
            }
        }

        return [$m[1], $params, $defaults, $variadic];
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @param array<string,mixed> $locals
     * @param array<string,array{params:list<string>,body:list<array<string,mixed>>}> $functions
     * @return array{returned:bool,value:mixed}
     */
    private static function executeStatements(array $statements, int &$index, array &$locals, array $functions, string &$output, int &$executed, bool $topLevel): array
    {
        $count = count($statements);

        while ($index < $count) {
            $statement = $statements[$index];
            $op = (string) ($statement['op'] ?? 'O_RAW_PHP_STMT');
            $source = (string) ($statement['source'] ?? '');

            switch ($op) {
                case 'O_DECLARE':
                case 'O_BLOCK_CLOSE':
                    $index++;
                    break;

                case 'O_FUNCTION_DECL':
                    if (!$topLevel) {
                        throw new \RuntimeException("Nested Oracle function declarations are not supported: {$source}");
                    }
                    $index++;
                    self::collectBlock($statements, $index);
                    break;

                case 'O_ASSIGN':
                    self::executeAssignStatement($source, $locals, $functions, $executed);
                    $executed++;
                    $index++;
                    break;

                case 'O_COMPOUND_ASSIGN':
                    self::executeCompoundAssignStatement($source, $locals, $functions, $executed);
                    $executed++;
                    $index++;
                    break;

                case 'O_ECHO':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'echo'), $locals, $functions, $executed);
                    $executed++;
                    $index++;
                    break;

                case 'O_PRINT':
                    $output .= (string) self::evaluateExpression(self::stripKeywordStatement($source, 'print'), $locals, $functions, $executed);
                    $executed++;
                    $index++;
                    break;

                case 'O_RETURN':
                    $value = self::evaluateExpression(self::stripKeywordStatement($source, 'return'), $locals, $functions, $executed);
                    $executed++;
                    $index++;

                    return ['returned' => true, 'value' => $value];

                default:
                    throw new \RuntimeException("Oracle function execution does not support {$op}: {$source}");
            }
        }

        return ['returned' => false, 'value' => null];
    }

    /**
     * @param list<array<string,mixed>> $statements
     * @return list<array<string,mixed>>
     */
    private static function collectBlock(array $statements, int &$index): array
    {
        $block = [];
        $depth = 1;
        $count = count($statements);

        while ($index < $count) {
            $statement = $statements[$index];
            $op = (string) ($statement['op'] ?? '');

            if ($op === 'O_BLOCK_CLOSE') {
                $depth--;
                $index++;
                if ($depth === 0) {
                    return $block;
                }
                $block[] = $statement;
                continue;
            }

            if (in_array($op, ['O_FUNCTION_DECL', 'O_IF', 'O_ELSE', 'O_WHILE'], true)) {
                $depth++;
            }

            $block[] = $statement;
            $index++;
        }

        throw new \RuntimeException('Unclosed Oracle function block');
    }

    /**
     * @return array{kind:string,output:string,return:mixed,executed_ops:int,family:string}
     */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'functions',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /**
     * @param array<string,mixed> $locals
     * @param array<string,array{params:list<string>,body:list<array<string,mixed>>}> $functions
     */
    private static function executeAssignStatement(string $source, array &$locals, array $functions, int &$executed): void
    {
        if (!preg_match('/^\$(\w+)\s*=\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle assignment: {$source}");
        }

        $locals[$m[1]] = self::evaluateExpression($m[2], $locals, $functions, $executed);
    }

    /**
     * @param array<string,mixed> $locals
     * @param array<string,array{params:list<string>,body:list<array<string,mixed>>}> $functions
     */
    private static function executeCompoundAssignStatement(string $source, array &$locals, array $functions, int &$executed): void
    {
        if (!preg_match('/^\$(\w+)\s*(\.=|\+=|-=|\*=|\/=|%=)\s*(.+);?$/', $source, $m)) {
            throw new \RuntimeException("Unsupported Oracle compound assignment: {$source}");
        }

        $name = $m[1];
        $operator = $m[2];
        $right = self::evaluateExpression($m[3], $locals, $functions, $executed);
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

    private static function stripKeywordStatement(string $source, string $keyword): string
    {
        $body = preg_replace('/^' . preg_quote($keyword, '/') . '\b/i', '', $source, 1);

        return rtrim(trim((string) $body), ';');
    }

    /**
     * @param array<string,mixed> $locals
     * @param array<string,array{params:list<string>,body:list<array<string,mixed>>}> $functions
     */
    private static function evaluateExpression(string $expression, array &$locals, array $functions, int &$executed): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));

        if (self::isWrappedInOuterParens($expr)) {
            return self::evaluateExpression(substr($expr, 1, -1), $locals, $functions, $executed);
        }

        foreach (['+', '.'] as $operator) {
            $parts = self::splitTopLevelBinary($expr, $operator);
            if ($parts !== null) {
                [$leftExpr, $rightExpr] = $parts;
                $left = self::evaluateExpression($leftExpr, $locals, $functions, $executed);
                $right = self::evaluateExpression($rightExpr, $locals, $functions, $executed);

                return $operator === '+' ? $left + $right : (string) $left . (string) $right;
            }
        }

        if (preg_match('/^(\w+)\s*\((.*)\)$/', $expr, $m)) {
            $args = self::splitArguments($m[2]);
            $values = [];
            $named = [];
            $seenNamed = false;
            foreach ($args as $arg) {
                if (preg_match('/^\.\.\.\s*(.+)$/s', $arg, $unpackArg)) {
                    if ($seenNamed) {
                        throw new \RuntimeException('Oracle unpacked positional arguments cannot follow named arguments');
                    }
                    $unpacked = self::evaluateExpression($unpackArg[1], $locals, $functions, $executed);
                    if (!is_array($unpacked) || !array_is_list($unpacked)) {
                        throw new \RuntimeException('Oracle function argument unpacking currently requires a list array');
                    }
                    foreach ($unpacked as $value) {
                        $values[] = $value;
                    }
                    continue;
                }
                if (preg_match('/^([A-Za-z_]\w*)\s*:\s*(.+)$/s', $arg, $namedArg)) {
                    $seenNamed = true;
                    if (array_key_exists($namedArg[1], $named)) {
                        throw new \RuntimeException("Oracle duplicate named argument: {$namedArg[1]}");
                    }
                    $named[$namedArg[1]] = self::evaluateExpression($namedArg[2], $locals, $functions, $executed);
                    continue;
                }
                if ($seenNamed) {
                    throw new \RuntimeException('Oracle positional argument cannot follow a named argument');
                }
                $values[] = self::evaluateExpression($arg, $locals, $functions, $executed);
            }

            return self::callFunction($m[1], $values, $functions, $executed, $named);
        }

        if (str_starts_with($expr, '[') && str_ends_with($expr, ']')) {
            $body = trim(substr($expr, 1, -1));
            if ($body === '') {
                return [];
            }
            $values = [];
            foreach (self::splitArguments($body) as $item) {
                $values[] = self::evaluateExpression($item, $locals, $functions, $executed);
            }
            return $values;
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

        if (preg_match('/^\$(\w+)\[(-?\d+)\]$/', $expr, $m)) {
            $value = $locals[$m[1]] ?? null;
            $key = (int) $m[2];
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new \RuntimeException("Missing Oracle function array dimension: {$expr}");
            }
            return $value[$key];
        }

        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            if (!array_key_exists($m[1], $locals)) {
                throw new \RuntimeException("Missing Oracle local: {$expr}");
            }

            return $locals[$m[1]];
        }

        throw new \RuntimeException("Unsupported Oracle expression: {$expr}");
    }

    /**
     * @param list<mixed> $args
     * @param array<string,array{params:list<string>,body:list<array<string,mixed>>}> $functions
     */
    private static function callFunction(string $name, array $args, array $functions, int &$executed, array $namedArgs = []): mixed
    {
        $lower = strtolower($name);

        if ($lower === 'strlen') {
            if ($namedArgs !== []) {
                throw new \RuntimeException('Oracle builtin named arguments are not supported in this function family');
            }
            return strlen((string) ($args[0] ?? ''));
        }

        if ($lower === 'strtoupper') {
            if ($namedArgs !== []) {
                throw new \RuntimeException('Oracle builtin named arguments are not supported in this function family');
            }
            return strtoupper((string) ($args[0] ?? ''));
        }

        if (!isset($functions[$lower])) {
            throw new \RuntimeException("Unknown Oracle function call: {$name}");
        }

        $function = $functions[$lower];
        $fixedCount = count($function['params']);
        $variadic = $function['variadic'] ?? null;
        if ($variadic === null && count($args) > $fixedCount) {
            throw new \RuntimeException("Oracle function {$name} expected at most {$fixedCount} positional args, got " . count($args));
        }

        $locals = [];
        foreach ($args as $i => $value) {
            if ($i < $fixedCount) {
                $locals[$function['params'][$i]] = $value;
                continue;
            }
            if ($variadic === null) {
                throw new \RuntimeException("Oracle function {$name} has no positional parameter at index {$i}");
            }
            $locals[$variadic][] = $value;
        }
        if ($variadic !== null && !array_key_exists($variadic, $locals)) {
            $locals[$variadic] = [];
        }

        foreach ($namedArgs as $param => $value) {
            if (!in_array($param, $function['params'], true)) {
                throw new \RuntimeException("Oracle function {$name} received unknown named argument {$param}");
            }
            if (array_key_exists($param, $locals)) {
                throw new \RuntimeException("Oracle function {$name} received duplicate argument {$param}");
            }
            $locals[$param] = $value;
        }

        foreach ($function['params'] as $param) {
            if (array_key_exists($param, $locals)) {
                continue;
            }
            if (array_key_exists($param, $function['defaults'] ?? [])) {
                $defaultLocals = [];
                $locals[$param] = self::evaluateExpression((string) $function['defaults'][$param], $defaultLocals, $functions, $executed);
                continue;
            }
            throw new \RuntimeException("Oracle function {$name} missing required argument {$param}");
        }

        $output = '';
        $index = 0;
        $result = self::executeStatements($function['body'], $index, $locals, $functions, $output, $executed, false);
        if ($output !== '') {
            throw new \RuntimeException("Oracle function {$name} produced unsupported direct output");
        }

        return $result['returned'] ? $result['value'] : null;
    }

    /**
     * @return list<string>
     */
    private static function splitArguments(string $args): array
    {
        $args = trim($args);
        if ($args === '') {
            return [];
        }

        $parts = [];
        $quote = null;
        $depth = 0;
        $start = 0;
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];
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
            if ($depth === 0 && $char === ',') {
                $parts[] = trim(substr($args, $start, $i - $start));
                $start = $i + 1;
            }
        }

        $parts[] = trim(substr($args, $start));

        return $parts;
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
     * @return array{0:string,1:string}|null
     */
    private static function splitTopLevelBinary(string $expr, string $operator): ?array
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
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0 && $char === $operator) {
                return [substr($expr, 0, $i), substr($expr, $i + 1)];
            }
        }

        return null;
    }
}
