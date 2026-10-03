<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes a first large Zend declaration subset from Oracle records.
 *
 * This covers namespace/import metadata, interface declarations, trait method
 * reuse, backed enum cases, static factories, object method dispatch, echo, and
 * return without falling through to PHP.
 */
final class OracleZendDeclarationExecutor
{
    /**
     * @param array<string,mixed> $program
     * @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int}
     */
    public static function execute(array $program): array
    {
        $sourcePath = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new \RuntimeException('Oracle Zend declaration execution requires a source file');
        }

        $source = (string) file_get_contents($sourcePath);
        $model = self::parseSource($source);
        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements($model['main']) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || preg_match('/^(?:<\?php\s*)?declare\s*\(/i', $statement)) {
                continue;
            }
            if (preg_match('/^namespace\s+/i', $statement) || preg_match('/^use\s+/i', $statement)) {
                continue;
            }

            if (preg_match('/^if\s*\((.+?)\)\s*\{\s*throw\s+new\s+([A-Za-z_\\\\]\w*(?:\\\\\w+)*)\s*\((.+)\)\s*;?\s*}?\s*$/i', $statement, $m)) {
                $executed++;
                if (self::evaluate($m[1], $locals, null, $model)) {
                    throw new \RuntimeException((string) self::evaluate($m[3], $locals, null, $model));
                }
                continue;
            }
            if ($statement === '}') {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*([A-Za-z_]\w*)::(\w+)\s*\((.*)\)$/', $statement, $m)) {
                $locals[$m[1]] = self::callStatic($model, $m[2], $m[3], self::evaluateArguments($m[4], $locals, null, $model));
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)->(\w+)\s*\((.*)\)$/', $statement, $m)) {
                $object = $locals[$m[2]] ?? null;
                if (!is_array($object)) {
                    throw new \RuntimeException("Oracle method call target is not an object: {$statement}");
                }
                $locals[$m[1]] = self::callMethod($model, $object, $m[3], self::evaluateArguments($m[4], $locals, $object, $model));
                $locals[$m[2]] = $object;
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*([A-Za-z_]\w*)::(\w+)$/', $statement, $m)) {
                $locals[$m[1]] = self::enumCase($model, $m[2], $m[3]);
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(.+)$/', $statement, $m)) {
                $locals[$m[1]] = self::evaluate($m[2], $locals, null, $model);
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/i', $statement, $m)) {
                $output .= self::phpString(self::evaluate($m[1], $locals, null, $model));
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                $return = self::evaluate($m[1], $locals, null, $model);
                $executed++;

                return self::result($output, $return, $executed);
            }

            throw new \RuntimeException("Unsupported Oracle Zend declaration statement: {$statement}");
        }

        return self::result($output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'zend-declarations',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }

    /**
     * @return array{
     *   namespace:string,
     *   uses:array<string,string>,
     *   interfaces:array<string,array{name:string}>,
     *   traits:array<string,array{name:string,methods:array<string,array{params:list<string>,body:string}>}>,
     *   enums:array<string,array{name:string,cases:array<string,mixed>}>,
     *   classes:array<string,array{name:string,implements:list<string>,traits:list<string>,properties:array<string,mixed>,methods:array<string,array{params:list<string>,body:string,static:bool}>}>,
     *   main:string
     * }
     */
    private static function parseSource(string $source): array
    {
        $model = [
            'namespace' => '',
            'uses' => [],
            'interfaces' => [],
            'traits' => [],
            'enums' => [],
            'classes' => [],
            'main' => $source,
        ];

        if (preg_match('/namespace\s+([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*)\s*;/', $source, $m)) {
            $model['namespace'] = $m[1];
        }
        if (preg_match_all('/use\s+([A-Za-z_\\\\]\w*(?:\\\\\w+)*)(?:\s+as\s+([A-Za-z_]\w*))?\s*;/', $source, $uses, PREG_SET_ORDER)) {
            foreach ($uses as $use) {
                $target = $use[1];
                $alias = $use[2] ?? basename(str_replace('\\', '/', $target));
                $model['uses'][$alias] = $target;
            }
        }

        foreach (['interface', 'trait', 'enum', 'class'] as $kind) {
            $offset = 0;
            $prefix = $kind === 'class' ? '(?:(?:abstract|final|readonly)\s+)*' : '';
            while (preg_match('/\b' . $prefix . $kind . '\s+(\w+)[^{]*\{/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $name = $m[1][0];
                $header = $m[0][0];
                $open = strpos($source, '{', $m[0][1]);
                if ($open === false) {
                    throw new \RuntimeException("Oracle declaration body missing opening brace: {$name}");
                }
                $close = self::findMatchingBrace($source, $open);
                $body = substr($source, $open + 1, $close - $open - 1);
                $full = substr($source, $m[0][1], $close - $m[0][1] + 1);

                if ($kind === 'interface') {
                    $model['interfaces'][$name] = ['name' => $name];
                } elseif ($kind === 'trait') {
                    $model['traits'][$name] = ['name' => $name, 'methods' => self::extractMethods($body)];
                } elseif ($kind === 'enum') {
                    $model['enums'][$name] = ['name' => $name, 'cases' => self::extractEnumCases($body)];
                } else {
                    $model['classes'][$name] = [
                        'name' => $name,
                        'implements' => self::extractImplements($header),
                        'traits' => self::extractTraitUses($body),
                        'properties' => self::extractProperties($body),
                        'methods' => self::extractMethods($body),
                    ];
                }

                $model['main'] = str_replace($full, '', $model['main']);
                $offset = $close + 1;
            }
        }

        return $model;
    }

    /** @return list<string> */
    private static function extractImplements(string $header): array
    {
        if (!preg_match('/\bimplements\s+(.+)$/i', rtrim($header, '{ '), $m)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }

    /** @return list<string> */
    private static function extractTraitUses(string $classBody): array
    {
        $traits = [];
        if (preg_match_all('/^\s*use\s+([A-Za-z_]\w*)\s*;/m', $classBody, $matches)) {
            foreach ($matches[1] as $trait) {
                $traits[] = $trait;
            }
        }

        return $traits;
    }

    /** @return array<string,mixed> */
    private static function extractProperties(string $classBody): array
    {
        $properties = [];
        if (preg_match_all('/(?:public|protected|private)\s+(?:static\s+)?(?:\??[A-Za-z_\\\\]\w*(?:\\\\\w*)*\s+)?\$(\w+)\s*=\s*([^;]+);/i', $classBody, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $properties[$match[1]] = self::evaluateLiteral($match[2]);
            }
        }

        return $properties;
    }

    /** @return array<string,mixed> */
    private static function extractEnumCases(string $enumBody): array
    {
        $cases = [];
        if (preg_match_all('/case\s+(\w+)\s*=\s*([^;]+);/i', $enumBody, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $cases[$match[1]] = self::evaluateLiteral($match[2]);
            }
        }

        return $cases;
    }

    /** @return array<string,array{params:list<string>,body:string,static:bool}> */
    private static function extractMethods(string $body): array
    {
        $methods = [];
        $offset = 0;
        while (preg_match('/((?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(([^)]*)\)\s*(?::\s*[^\{;]+)?\s*\{/i', $body, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $method = $m[2][0];
            $params = [];
            foreach (array_filter(array_map('trim', explode(',', $m[3][0]))) as $param) {
                if (preg_match('/\$(\w+)/', $param, $pm)) {
                    $params[] = $pm[1];
                }
            }
            $open = strpos($body, '{', $m[0][1]);
            if ($open === false) {
                throw new \RuntimeException("Oracle method body missing opening brace: {$method}");
            }
            $close = self::findMatchingBrace($body, $open);
            $methods[$method] = [
                'params' => $params,
                'body' => substr($body, $open + 1, $close - $open - 1),
                'static' => str_contains(strtolower($m[1][0]), 'static'),
            ];
            $offset = $close + 1;
        }

        return $methods;
    }

    /** @param array<string,mixed> $model @param list<mixed> $args */
    private static function callStatic(array $model, string $className, string $method, array $args): mixed
    {
        $class = $model['classes'][$className] ?? null;
        if (!is_array($class) || !isset($class['methods'][$method])) {
            throw new \RuntimeException("Unknown Oracle static method: {$className}::{$method}");
        }

        return self::executeMethod($model, $className, $method, null, $args);
    }

    /** @param array<string,mixed> $model */
    private static function newObject(array $model, string $className): array
    {
        $class = $model['classes'][$className] ?? null;
        if (!is_array($class)) {
            throw new \RuntimeException("Unknown Oracle class: {$className}");
        }

        return [
            '__class' => $className,
            'props' => $class['properties'],
        ];
    }

    /** @param array<string,mixed> $model @param list<mixed> $args */
    private static function callMethod(array $model, array &$object, string $method, array $args): mixed
    {
        $className = (string) ($object['__class'] ?? '');
        $class = $model['classes'][$className] ?? null;
        if (!is_array($class)) {
            throw new \RuntimeException("Unknown Oracle object class: {$className}");
        }
        if (isset($class['methods'][$method])) {
            return self::executeMethod($model, $className, $method, $object, $args);
        }
        foreach ($class['traits'] as $traitName) {
            if (isset($model['traits'][$traitName]['methods'][$method])) {
                return self::executeTraitMethod($model, $traitName, $method, $object, $args);
            }
        }

        throw new \RuntimeException("Unknown Oracle object method: {$className}->{$method}");
    }

    /** @param array<string,mixed> $model @param list<mixed> $args */
    private static function executeTraitMethod(array $model, string $traitName, string $method, array &$object, array $args): mixed
    {
        $definition = $model['traits'][$traitName]['methods'][$method] ?? null;
        if (!is_array($definition)) {
            throw new \RuntimeException("Missing Oracle trait method: {$traitName}::{$method}");
        }

        return self::executeBody($model, $definition, $object, $args);
    }

    /** @param array<string,mixed> $model @param list<mixed> $args */
    private static function executeMethod(array $model, string $className, string $method, ?array $object, array $args): mixed
    {
        $definition = $model['classes'][$className]['methods'][$method] ?? null;
        if (!is_array($definition)) {
            throw new \RuntimeException("Missing Oracle class method: {$className}::{$method}");
        }

        return self::executeBody($model, $definition, $object, $args, $className);
    }

    /** @param array<string,mixed> $model @param array{params:list<string>,body:string,static?:bool} $definition @param list<mixed> $args */
    private static function executeBody(array $model, array $definition, ?array $object, array $args, ?string $className = null): mixed
    {
        $locals = [];
        foreach ($definition['params'] as $i => $param) {
            $locals[$param] = $args[$i] ?? null;
        }

        foreach (self::splitStatements($definition['body']) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            if (preg_match('/^return\s+new\s+self\s*\(\s*\)$/i', $statement)) {
                if ($className === null) {
                    throw new \RuntimeException('Oracle new self requires a class context');
                }

                return self::newObject($model, $className);
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                return self::evaluate($m[1], $locals, $object, $model);
            }

            throw new \RuntimeException("Unsupported Oracle declaration method statement: {$statement}");
        }

        return null;
    }

    /** @param array<string,mixed> $model */
    private static function enumCase(array $model, string $enumName, string $caseName): array
    {
        if (!array_key_exists($caseName, $model['enums'][$enumName]['cases'] ?? [])) {
            throw new \RuntimeException("Unknown Oracle enum case: {$enumName}::{$caseName}");
        }

        return [
            '__enum' => $enumName,
            'name' => $caseName,
            'value' => $model['enums'][$enumName]['cases'][$caseName],
        ];
    }

    /** @param array<string,mixed> $locals @param array<string,mixed>|null $object @param array<string,mixed> $model */
    private static function evaluate(string $expression, array $locals, ?array $object, array $model): mixed
    {
        $expr = trim(rtrim(trim($expression), ';'));
        if ($expr === '') {
            return null;
        }
        if (preg_match('/^\((string)\)\s*(.+)$/i', $expr, $m)) {
            return (string) self::evaluate($m[2], $locals, $object, $model);
        }

        $comparison = self::splitTopLevelBinary($expr, '===');
        if ($comparison !== null) {
            return self::evaluate($comparison[0], $locals, $object, $model) === self::evaluate($comparison[1], $locals, $object, $model);
        }

        foreach (['+', '.'] as $operator) {
            $parts = self::splitTopLevelBinary($expr, $operator);
            if ($parts !== null) {
                $left = self::evaluate($parts[0], $locals, $object, $model);
                $right = self::evaluate($parts[1], $locals, $object, $model);

                return $operator === '+'
                    ? $left + $right
                    : self::phpString($left) . self::phpString($right);
            }
        }

        if (preg_match('/^\$(\w+)->(\w+)\s*\((.*)\)$/', $expr, $m)) {
            $target = $locals[$m[1]] ?? null;
            if (!is_array($target)) {
                throw new \RuntimeException("Oracle expression method target is not object: {$expr}");
            }

            return self::callMethod($model, $target, $m[2], self::evaluateArguments($m[3], $locals, $target, $model));
        }

        if (preg_match('/^\$this->(\w+)$/', $expr, $m)) {
            if ($object === null) {
                throw new \RuntimeException("Oracle \$this used outside object context: {$expr}");
            }

            return $object['props'][$m[1]] ?? null;
        }
        if (preg_match('/^\$(\w+)->value$/', $expr, $m)) {
            $value = $locals[$m[1]] ?? null;
            if (is_array($value) && array_key_exists('value', $value)) {
                return $value['value'];
            }
            throw new \RuntimeException("Oracle value fetch target is not enum: {$expr}");
        }
        if (preg_match('/^\$(\w+)$/', $expr, $m)) {
            return $locals[$m[1]] ?? null;
        }

        return self::evaluateLiteral($expr);
    }

    private static function evaluateLiteral(string $expr): mixed
    {
        $expr = trim(rtrim(trim($expr), ';'));
        if (preg_match('/^([\'"])(.*)\1$/s', $expr, $m)) {
            return stripcslashes($m[2]);
        }
        if (preg_match('/^-?\d+$/', $expr)) {
            return (int) $expr;
        }
        if ($expr === 'null') {
            return null;
        }
        if ($expr === 'true') {
            return true;
        }
        if ($expr === 'false') {
            return false;
        }

        throw new \RuntimeException("Unsupported Oracle declaration literal: {$expr}");
    }

    /** @return list<mixed> */
    private static function evaluateArguments(string $body, array $locals, ?array $object, array $model): array
    {
        $args = [];
        foreach (self::splitTopLevel($body, ',') as $arg) {
            if (trim($arg) !== '') {
                $args[] = self::evaluate($arg, $locals, $object, $model);
            }
        }

        return $args;
    }

    /** @return list<string> */
    private static function splitStatements(string $source): array
    {
        $clean = preg_replace('/^\s*<\?php\s*/', '', $source) ?? $source;
        $clean = preg_replace('/}\s*(?=(?:echo|return|\$[A-Za-z_]\w*\s*=)\b)/i', "};\n", $clean) ?? $clean;
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

        throw new \RuntimeException('Unclosed Oracle declaration brace');
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
