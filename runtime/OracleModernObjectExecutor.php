<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Executes two modern object-model subsets without PHP fallback:
 * late static binding and nullsafe object access/calls.
 */
final class OracleModernObjectExecutor
{
    /** @param array<string,mixed> $program */
    public static function executeLateStaticBinding(array $program): array
    {
        $source = self::source($program);
        $classes = self::parseClasses($source);

        if (count($classes) < 2) {
            throw new \RuntimeException('Oracle late-static-binding execution requires parent and child classes');
        }

        $main = self::removeClassBodies($source);
        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements($main) as $statement) {
            if ($statement === '' || preg_match('/^declare\s*\(/i', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(\w+)::who\(\)$/', $statement, $m)) {
                $called = $m[2];
                self::classOrFail($classes, $called);
                $declaring = self::resolveMethodDeclaringClass($classes, $called, 'who');
                $selfName = $declaring['name'];
                $staticProp = self::resolveStaticProperty($classes, $called, 'name');
                $selfProp = self::resolveStaticProperty($classes, $selfName, 'name');
                $locals[$m[1]] = $called . ':' . $staticProp . ':' . $selfProp;
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*(\w+)::make\(\)$/', $statement, $m)) {
                self::classOrFail($classes, $m[2]);
                self::resolveMethodDeclaringClass($classes, $m[2], 'make');
                $locals[$m[1]] = ['__class' => $m[2]];
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)->label\(\)$/', $statement, $m)) {
                $object = $locals[$m[2]] ?? null;
                if (!is_array($object) || !isset($object['__class'])) {
                    throw new \RuntimeException("Oracle late-static-binding method target is not an object: {$statement}");
                }
                self::resolveMethodDeclaringClass($classes, (string) $object['__class'], 'label');
                $locals[$m[1]] = (string) $object['__class'];
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/i', $statement, $m)) {
                $output .= self::evalConcatWithCoalesce($m[1], $locals);
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                $return = self::evalConcatWithCoalesce($m[1], $locals);
                $executed++;
                return self::result('late-static-binding', $output, $return, $executed);
            }

            throw new \RuntimeException("Unsupported Oracle late-static-binding statement: {$statement}");
        }

        return self::result('late-static-binding', $output, null, $executed);
    }

    /** @param array<string,mixed> $program */
    public static function executeNullsafe(array $program): array
    {
        $source = self::source($program);
        $classes = self::parseClasses($source);
        $main = self::removeClassBodies($source);

        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements($main) as $statement) {
            if ($statement === '' || preg_match('/^declare\s*\(/i', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*new\s+(\w+)\(\)$/', $statement, $m)) {
                self::classOrFail($classes, $m[2]);
                $locals[$m[1]] = ['__class' => $m[2], 'props' => []];
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*null$/i', $statement, $m)) {
                $locals[$m[1]] = null;
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)->(\w+)\s*=\s*([\'\"])(.*)\3$/', $statement, $m)) {
                $object = $locals[$m[1]] ?? null;
                if (!is_array($object)) {
                    throw new \RuntimeException("Oracle nullsafe property assignment target is not object: {$statement}");
                }
                $object['props'][$m[2]] = stripcslashes($m[4]);
                $locals[$m[1]] = $object;
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\?->(\w+)\(\)$/', $statement, $m)) {
                $target = $locals[$m[2]] ?? null;
                if ($target === null) {
                    $locals[$m[1]] = null;
                } else {
                    if (!is_array($target) || !isset($target['__class'])) {
                        throw new \RuntimeException('Oracle nullsafe method target is not object/null');
                    }
                    self::resolveMethodDeclaringClass($classes, (string) $target['__class'], $m[3]);
                    if ($m[3] !== 'label') {
                        throw new \RuntimeException("Unsupported Oracle nullsafe method: {$m[3]}");
                    }
                    $locals[$m[1]] = $target['props']['value'] ?? 'none';
                }
                $executed++;
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)\?->(\w+)$/', $statement, $m)) {
                $target = $locals[$m[2]] ?? null;
                if ($target === null) {
                    $locals[$m[1]] = null;
                } else {
                    if (!is_array($target)) {
                        throw new \RuntimeException('Oracle nullsafe property target is not object/null');
                    }
                    $locals[$m[1]] = $target['props'][$m[3]] ?? null;
                }
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/i', $statement, $m)) {
                $output .= self::evalConcatWithCoalesce($m[1], $locals);
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/i', $statement, $m)) {
                $return = self::evalConcatWithCoalesce($m[1], $locals);
                $executed++;
                return self::result('nullsafe-objects', $output, $return, $executed);
            }

            throw new \RuntimeException("Unsupported Oracle nullsafe statement: {$statement}");
        }

        return self::result('nullsafe-objects', $output, null, $executed);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $family, string $output, mixed $return, int $executed): array
    {
        return ['kind'=>'JINX_ORACLE_EXECUTION','family'=>$family,'output'=>$output,'return'=>$return,'executed_ops'=>$executed];
    }

    /** @param array<string,mixed> $program */
    private static function source(array $program): string
    {
        $path = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Oracle modern-object execution requires a source file');
        }
        return (string) file_get_contents($path);
    }

    /** @return array<string,array{name:string,parent:?string,body:string,methods:list<string>,static_properties:array<string,mixed>}> */
    private static function parseClasses(string $source): array
    {
        $classes = [];
        $offset = 0;
        while (preg_match('/class\s+(\w+)(?:\s+extends\s+(\w+))?\s*\{/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $name = $m[1][0];
            $parent = isset($m[2][0]) && $m[2][0] !== '' ? $m[2][0] : null;
            $open = strpos($source, '{', $m[0][1]);
            if ($open === false) {
                throw new \RuntimeException("Oracle class {$name} has no body");
            }
            $close = self::matchingBrace($source, $open);
            $body = substr($source, $open + 1, $close - $open - 1);
            $methods = [];
            if (preg_match_all('/function\s+(\w+)\s*\(/i', $body, $mm)) {
                $methods = array_values(array_unique($mm[1]));
            }
            $props = [];
            if (preg_match_all('/static\s+(?:\??[A-Za-z_\\][A-Za-z0-9_\\|&]*\s+)?\$(\w+)\s*=\s*([\'\"])(.*?)\2\s*;/s', $body, $pm, PREG_SET_ORDER)) {
                foreach ($pm as $p) {
                    $props[$p[1]] = stripcslashes($p[3]);
                }
            }
            $classes[$name] = ['name'=>$name,'parent'=>$parent,'body'=>$body,'methods'=>$methods,'static_properties'=>$props];
            $offset = $close + 1;
        }
        return $classes;
    }

    private static function removeClassBodies(string $source): string
    {
        $ranges = [];
        $offset = 0;
        while (preg_match('/class\s+\w+(?:\s+extends\s+\w+)?\s*\{/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $open = strpos($source, '{', $m[0][1]);
            if ($open === false) {
                break;
            }
            $close = self::matchingBrace($source, $open);
            $ranges[] = [$m[0][1], $close + 1];
            $offset = $close + 1;
        }
        for ($i = count($ranges)-1; $i >= 0; $i--) {
            [$start,$end]=$ranges[$i];
            $source = substr($source,0,$start) . substr($source,$end);
        }
        return preg_replace('/<\?php/', '', $source) ?? $source;
    }

    /** @param array<string,array{name:string,parent:?string,body:string,methods:list<string>,static_properties:array<string,mixed>}> $classes */
    private static function classOrFail(array $classes, string $name): array
    {
        if (!isset($classes[$name])) {
            throw new \RuntimeException("Unknown Oracle class: {$name}");
        }
        return $classes[$name];
    }

    private static function resolveMethodDeclaringClass(array $classes, string $class, string $method): array
    {
        $cursor = $class;
        while ($cursor !== null) {
            $info = self::classOrFail($classes, $cursor);
            if (in_array($method, $info['methods'], true)) {
                return $info;
            }
            $cursor = $info['parent'];
        }
        throw new \RuntimeException("Unknown Oracle method {$class}::{$method}");
    }

    private static function resolveStaticProperty(array $classes, string $class, string $property): mixed
    {
        $cursor = $class;
        while ($cursor !== null) {
            $info = self::classOrFail($classes, $cursor);
            if (array_key_exists($property, $info['static_properties'])) {
                return $info['static_properties'][$property];
            }
            $cursor = $info['parent'];
        }
        throw new \RuntimeException('Unknown Oracle static property ' . $class . '::$' . $property);
    }

    private static function evalConcatWithCoalesce(string $expr, array $locals): string
    {
        $parts = self::splitTopLevel($expr, '.');
        $out = '';
        foreach ($parts as $part) {
            $part = trim($part);
            while (str_starts_with($part, '(') && str_ends_with($part, ')')) {
                $part = trim(substr($part, 1, -1));
            }
            if (preg_match('/^\$(\w+)\s*\?\?\s*([\'\"])(.*?)\2$/s', $part, $m)) {
                $value = $locals[$m[1]] ?? null;
                $out .= $value ?? stripcslashes($m[3]);
            } elseif (preg_match('/^\$(\w+)$/', $part, $m)) {
                $out .= (string) ($locals[$m[1]] ?? '');
            } elseif (preg_match('/^([\'\"])(.*?)\1$/s', $part, $m)) {
                $out .= stripcslashes($m[2]);
            } else {
                throw new \RuntimeException("Unsupported Oracle concatenation expression: {$part}");
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function splitStatements(string $source): array
    {
        $parts = self::splitTopLevel($source, ';');
        return array_values(array_filter(array_map(static fn(string $s): string => trim($s), $parts), static fn(string $s): bool => $s !== ''));
    }

    /** @return list<string> */
    private static function splitTopLevel(string $source, string $delimiter): array
    {
        $parts=[]; $start=0; $quote=null; $depth=0; $len=strlen($source);
        for($i=0;$i<$len;$i++){
            $ch=$source[$i];
            if($quote!==null){
                if($ch==='\\'){ $i++; continue; }
                if($ch===$quote) $quote=null;
                continue;
            }
            if($ch==='"'||$ch==="'"){ $quote=$ch; continue; }
            if($ch==='('||$ch==='['||$ch==='{'){ $depth++; continue; }
            if($ch===')'||$ch===']'||$ch==='}'){ $depth=max(0,$depth-1); continue; }
            if($depth===0 && substr($source,$i,strlen($delimiter))===$delimiter){
                $parts[]=substr($source,$start,$i-$start); $start=$i+strlen($delimiter); $i+=strlen($delimiter)-1;
            }
        }
        $parts[]=substr($source,$start);
        return $parts;
    }

    private static function matchingBrace(string $source, int $open): int
    {
        $depth=0; $quote=null; $len=strlen($source);
        for($i=$open;$i<$len;$i++){
            $ch=$source[$i];
            if($quote!==null){
                if($ch==='\\'){ $i++; continue; }
                if($ch===$quote) $quote=null;
                continue;
            }
            if($ch==='"'||$ch==="'"){ $quote=$ch; continue; }
            if($ch==='{') $depth++;
            elseif($ch==='}'){ $depth--; if($depth===0) return $i; }
        }
        throw new \RuntimeException('Unclosed Oracle class body');
    }
}
