<?php

declare(strict_types=1);

namespace jinx\lowering;

use jinx\pasm\PASM;

require_once __DIR__ . '/PASM.php';

final class JinxToPasmLowerer
{
    public static function runFile(string $path): mixed
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Missing .jinx file: {$path}");
        }

        return self::run((string) file_get_contents($path));
    }

    public static function run(string $source): mixed
    {
        $pasm = PASM::start();

        foreach (self::lines($source) as $line) {
            self::lowerLine($pasm, $line);
        }

        return $pasm->end();
    }

    /**
     * Supported .jinx shape:
     *
     * assign x int 2
     * assign y int 3
     * return add local x local y
     */
    private static function lowerLine(PASM $pasm, string $line): void
    {
        $parts = preg_split('/\s+/', trim($line));

        if ($parts === false || $parts === ['']) {
            return;
        }

        if ($parts[0] === 'assign') {
            self::lowerAssign($pasm, $parts);
            return;
        }

        if ($parts[0] === 'return') {
            if (($parts[1] ?? null) === 'builtin') {
                self::lowerReturnBuiltin($pasm, $parts);
                return;
            }

            self::lowerReturn($pasm, $parts);
            return;
        }

        throw new \RuntimeException("Unknown .jinx instruction: {$line}");
    }

    /**
     * assign x int 2
     */
    private static function lowerAssign(PASM $pasm, array $parts): void
    {
        if (count($parts) !== 4) {
            throw new \RuntimeException('Invalid assign form');
        }

        [, $name, $type, $rawValue] = $parts;

        if ($type === 'int') {
            $pasm
                ->mov('AH', (int) $rawValue)
                ->mov('LOCAL:' . $name, 'AH');
            return;
        }

        if ($type === 'string') {
            $pasm
                ->mov('STRING', $rawValue)
                ->mov('LOCAL:' . $name, 'STRING');
            return;
        }

        throw new \RuntimeException("Unsupported assign type: {$type}");
    }


    /**
     * return builtin strlen local s
     */
    private static function lowerReturnBuiltin(PASM $pasm, array $parts): void
    {
        if (count($parts) !== 5) {
            throw new \RuntimeException('Invalid return builtin form');
        }

        [, $kind, $name, $argKind, $argName] = $parts;

        if ($kind !== 'builtin') {
            throw new \RuntimeException("Invalid builtin return kind: {$kind}");
        }

        if ($argKind !== 'local') {
            throw new \RuntimeException('Only local builtin operands are supported here');
        }

        if ($name === 'strlen') {
            $pasm
                ->mov('STRING', 'LOCAL:' . $argName)
                ->jinx_builtin('ACC', 'strlen', ['STRING'])
                ->ret('ACC');
            return;
        }

        throw new \RuntimeException("Unsupported builtin: {$name}");
    }

    /**
     * return add local x local y
     */
    private static function lowerReturn(PASM $pasm, array $parts): void
    {
        if (count($parts) !== 6) {
            throw new \RuntimeException('Invalid return form');
        }

        [, $op, $leftKind, $leftName, $rightKind, $rightName] = $parts;

        if (!in_array($op, ['add', 'sub', 'mul'], true)) {
            throw new \RuntimeException("Unsupported return op: {$op}");
        }

        if ($leftKind !== 'local' || $rightKind !== 'local') {
            throw new \RuntimeException('Only local operands are supported here');
        }

        $pasm
            ->mov('ECX', 'LOCAL:' . $leftName)
            ->mov('AH', 'LOCAL:' . $rightName);

        if ($op === 'add') {
            $pasm->add('RDX', 'ECX', 'AH');
        } elseif ($op === 'sub') {
            $pasm->sub('RDX', 'ECX', 'AH');
        } elseif ($op === 'mul') {
            $pasm->mul('RDX', 'ECX', 'AH');
        }

        $pasm->ret('RDX');
    }

    /**
     * @return list<string>
     */
    private static function lines(string $source): array
    {
        $lines = preg_split('/\R/', $source);

        if ($lines === false) {
            return [];
        }

        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $out[] = $line;
        }

        return $out;
    }
}
