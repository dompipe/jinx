<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Narrow Oracle executor for PHP magic overloading semantics.
 *
 * Proven subset:
 * __set, __get, __isset, __unset, __call, __callStatic.
 */
final class OracleMagicMethodExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $path = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Oracle magic-method execution requires a source file');
        }

        $source = (string) file_get_contents($path);
        if (!preg_match('/class\s+(\w+)\b/', $source, $cm)) {
            throw new \RuntimeException('Oracle magic-method fixture requires a class');
        }
        $class = $cm[1];

        foreach (['__set','__get','__isset','__unset','__call','__callStatic'] as $method) {
            if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/i', $source)) {
                throw new \RuntimeException("Oracle magic-method fixture missing {$method}");
            }
        }

        $main = self::removeClass($source);
        $locals = [];
        $output = '';
        $executed = 0;

        foreach (self::splitStatements($main) as $statement) {
            if ($statement === '' || preg_match('/^declare\s*\(/i', $statement)) {
                continue;
            }

            if (preg_match('/^\$(\w+)\s*=\s*new\s+' . preg_quote($class, '/') . '\s*\(\)$/i', $statement, $m)) {
                $locals[$m[1]] = ['__class'=>$class,'data'=>[]];
                $executed++;
                continue;
            }

            // Missing property write -> __set.
            if (preg_match('/^\$(\w+)->(\w+)\s*=\s*(-?\d+)$/', $statement, $m)) {
                $object = $locals[$m[1]] ?? null;
                self::assertObject($object, $class);
                $object['data'][$m[2]] = (int) $m[3];
                $locals[$m[1]] = $object;
                $executed++;
                continue;
            }

            // isset($o->x) -> __isset.
            if (preg_match('/^\$(\w+)\s*=\s*isset\(\$(\w+)->(\w+)\)$/i', $statement, $m)) {
                $object = $locals[$m[2]] ?? null;
                self::assertObject($object, $class);
                $locals[$m[1]] = isset($object['data'][$m[3]]);
                $executed++;
                continue;
            }

            // Missing property read -> __get.
            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)->(\w+)$/', $statement, $m)) {
                $object = $locals[$m[2]] ?? null;
                self::assertObject($object, $class);
                $locals[$m[1]] = $object['data'][$m[3]] ?? null;
                $executed++;
                continue;
            }

            // unset($o->x) -> __unset.
            if (preg_match('/^unset\(\$(\w+)->(\w+)\)$/i', $statement, $m)) {
                $object = $locals[$m[1]] ?? null;
                self::assertObject($object, $class);
                unset($object['data'][$m[2]]);
                $locals[$m[1]] = $object;
                $executed++;
                continue;
            }

            // Missing instance method -> __call.
            if (preg_match('/^\$(\w+)\s*=\s*\$(\w+)->(\w+)\((.*)\)$/s', $statement, $m)) {
                $object = $locals[$m[2]] ?? null;
                self::assertObject($object, $class);
                $locals[$m[1]] = 'instance:' . $m[3] . ':' . implode(',', self::arguments($m[4]));
                $executed++;
                continue;
            }

            // Missing static method -> __callStatic.
            if (preg_match('/^\$(\w+)\s*=\s*' . preg_quote($class, '/') . '::(\w+)\((.*)\)$/s', $statement, $m)) {
                $locals[$m[1]] = 'static:' . $m[2] . ':' . implode(',', self::arguments($m[3]));
                $executed++;
                continue;
            }

            if (preg_match('/^echo\s+(.+)$/is', $statement, $m)) {
                $output .= self::concat($m[1], $locals);
                $executed++;
                continue;
            }

            if (preg_match('/^return\s+(.+)$/is', $statement, $m)) {
                $executed++;
                return self::result($output, self::concat($m[1], $locals), $executed);
            }

            throw new \RuntimeException("Unsupported Oracle magic-method statement: {$statement}");
        }

        return self::result($output, null, $executed);
    }

    private static function assertObject(mixed $object, string $class): void
    {
        if (!is_array($object) || ($object['__class'] ?? null) !== $class) {
            throw new \RuntimeException('Oracle magic-method target is not the expected object');
        }
    }

    /** @return list<string> */
    private static function arguments(string $args): array
    {
        $args = trim($args);
        if ($args === '') return [];
        $out = [];
        foreach (explode(',', $args) as $arg) {
            $arg = trim($arg);
            if (preg_match('/^([\'\"])(.*?)\1$/s', $arg, $m)) {
                $out[] = stripcslashes($m[2]);
            } elseif (preg_match('/^-?\d+$/', $arg)) {
                $out[] = $arg;
            } else {
                throw new \RuntimeException("Unsupported Oracle magic argument: {$arg}");
            }
        }
        return $out;
    }

    private static function concat(string $expr, array $locals): string
    {
        $parts = preg_split('/\s*\.\s*/', trim($expr)) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^\$(\w+)$/', $part, $m)) {
                $value = $locals[$m[1]] ?? null;
                $out .= $value === true ? '1' : ($value === false || $value === null ? '' : (string) $value);
            } elseif (preg_match('/^([\'\"])(.*?)\1$/s', $part, $m)) {
                $out .= stripcslashes($m[2]);
            } else {
                throw new \RuntimeException("Unsupported Oracle magic concatenation expression: {$part}");
            }
        }
        return $out;
    }

    private static function removeClass(string $source): string
    {
        if (!preg_match('/class\s+\w+\s*\{/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            return $source;
        }
        $start = $m[0][1];
        $open = strpos($source, '{', $start);
        if ($open === false) return $source;
        $close = self::matchingBrace($source, $open);
        $source = substr($source, 0, $start) . substr($source, $close + 1);
        return preg_replace('/<\?php/', '', $source) ?? $source;
    }

    /** @return list<string> */
    private static function splitStatements(string $source): array
    {
        $parts=[]; $start=0; $quote=null; $depth=0; $len=strlen($source);
        for($i=0;$i<$len;$i++){
            $ch=$source[$i];
            if($quote!==null){ if($ch==='\\'){ $i++; continue; } if($ch===$quote)$quote=null; continue; }
            if($ch==='"'||$ch==="'"){ $quote=$ch; continue; }
            if($ch==='('||$ch==='['||$ch==='{'){ $depth++; continue; }
            if($ch===')'||$ch===']'||$ch==='}'){ $depth=max(0,$depth-1); continue; }
            if($ch===';'&&$depth===0){ $piece=trim(substr($source,$start,$i-$start)); if($piece!=='')$parts[]=$piece; $start=$i+1; }
        }
        $tail=trim(substr($source,$start)); if($tail!=='')$parts[]=$tail;
        return $parts;
    }

    private static function matchingBrace(string $source, int $open): int
    {
        $depth=0; $quote=null; $len=strlen($source);
        for($i=$open;$i<$len;$i++){
            $ch=$source[$i];
            if($quote!==null){ if($ch==='\\'){ $i++; continue; } if($ch===$quote)$quote=null; continue; }
            if($ch==='"'||$ch==="'"){ $quote=$ch; continue; }
            if($ch==='{')$depth++;
            elseif($ch==='}'){ $depth--; if($depth===0)return $i; }
        }
        throw new \RuntimeException('Unclosed Oracle magic-method class body');
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return ['kind'=>'JINX_ORACLE_EXECUTION','family'=>'magic-methods','output'=>$output,'return'=>$return,'executed_ops'=>$executed];
    }
}
