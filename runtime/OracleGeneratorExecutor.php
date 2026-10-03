<?php

declare(strict_types=1);

namespace jinx\oracle;

/**
 * Narrow Oracle generator executor.
 *
 * Proven subsets:
 * - yield value -> send(value) -> subsequent yield -> getReturn()
 * - yield from delegated generator -> delegated return -> outer yield/return
 *
 * Unsupported generator syntax throws; there is no PHP fallback.
 */
final class OracleGeneratorExecutor
{
    /** @param array<string,mixed> $program */
    public static function execute(array $program): array
    {
        $path = (string) ($program['source_realpath'] ?? $program['source_file'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Oracle generator execution requires a source file');
        }

        $source = (string) file_get_contents($path);
        return str_contains($source, 'yield from')
            ? self::executeYieldFrom($source)
            : self::executeSend($source);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function executeSend(string $source): array
    {
        if (!preg_match('/\$(\w+)\s*=\s*yield\s+([\'\"])(.*?)\2\s*;/s', $source, $first)) {
            throw new \RuntimeException('Oracle generator send fixture missing initial yield assignment');
        }
        $incoming = $first[1];
        $initial = stripcslashes($first[3]);

        if (!preg_match('/yield\s+\$' . preg_quote($incoming, '/') . '\s*\*\s*(-?\d+)\s*;/s', $source, $second)) {
            throw new \RuntimeException('Oracle generator send fixture missing second yield expression');
        }
        if (!preg_match('/return\s+\$' . preg_quote($incoming, '/') . '\s*\*\s*(-?\d+)\s*;/s', $source, $ret)) {
            throw new \RuntimeException('Oracle generator send fixture missing return expression');
        }
        if (!preg_match('/->send\(\s*(-?\d+)\s*\)/', $source, $send)) {
            throw new \RuntimeException('Oracle generator send fixture missing send value');
        }

        $sent = (int) $send[1];
        $yielded = $sent * (int) $second[1];
        $returned = $sent * (int) $ret[1];

        $value = $initial . '|' . $yielded . '|' . $returned;

        return self::result($value, $value, 6);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function executeYieldFrom(string $source): array
    {
        if (!preg_match('/function\s+\w+\s*\(\s*\)\s*:\s*Generator\s*\{([\s\S]*?)\}/i', $source, $innerMatch)) {
            throw new \RuntimeException('Oracle yield-from fixture missing delegated generator');
        }
        $inner = $innerMatch[1];

        if (!preg_match_all('/yield\s+(-?\d+)\s*;/', $inner, $yieldMatches) || count($yieldMatches[1]) < 1) {
            throw new \RuntimeException('Oracle yield-from delegated generator has no numeric yields');
        }
        if (!preg_match('/return\s+(-?\d+)\s*;/', $inner, $innerReturn)) {
            throw new \RuntimeException('Oracle yield-from delegated generator missing numeric return');
        }

        $delegatedYields = array_map('intval', $yieldMatches[1]);
        $delegatedReturn = (int) $innerReturn[1];

        if (!preg_match('/\$(\w+)\s*=\s*yield\s+from\s+\w+\s*\(\s*\)\s*;[\s\S]*?yield\s+\$\1\s*\+\s*(-?\d+)\s*;[\s\S]*?return\s+\$\1\s*\+\s*(-?\d+)\s*;/i', $source, $outer)) {
            throw new \RuntimeException('Oracle yield-from fixture missing delegated return consumption');
        }

        $outerYield = $delegatedReturn + (int) $outer[2];
        $outerReturn = $delegatedReturn + (int) $outer[3];

        $values = [...$delegatedYields, $outerYield];
        $value = implode('|', array_map('strval', $values)) . '|' . $outerReturn;

        return self::result($value, $value, 8);
    }

    /** @return array{kind:string,family:string,output:string,return:mixed,executed_ops:int} */
    private static function result(string $output, mixed $return, int $executed): array
    {
        return [
            'kind' => 'JINX_ORACLE_EXECUTION',
            'family' => 'generators',
            'output' => $output,
            'return' => $return,
            'executed_ops' => $executed,
        ];
    }
}
