<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PhpToJinxLowerer.php';
require_once dirname(__DIR__) . '/runtime/JinxToPasmLowerer.php';
require_once dirname(__DIR__) . '/runtime/PASM.php';

use jinx\lowering\PhpToJinxLowerer;
use jinx\lowering\JinxToPasmLowerer;
use jinx\pasm\PASM;

$root = dirname(__DIR__);
$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 1000;

$cases = [
    [
        'name' => 'simple-add',
        'php_file' => $root . '/fixtures/simple-add.php',
        'expected' => 5,
    ],
    [
        'name' => 'simple-sub',
        'php_file' => $root . '/fixtures/simple-sub.php',
        'expected' => 5,
    ],
    [
        'name' => 'simple-mul',
        'php_file' => $root . '/fixtures/simple-mul.php',
        'expected' => 42,
    ],
    [
        'name' => 'negative-add',
        'php_file' => $root . '/fixtures/negative-add.php',
        'expected' => 7,
    ],
    [
        'name' => 'strlen',
        'php_file' => $root . '/fixtures/strlen.php',
        'expected' => 6,
    ],
];

function bench(string $label, int $iterations, callable $fn): array
{
    $start = hrtime(true);
    $last = null;

    for ($i = 0; $i < $iterations; $i++) {
        $last = $fn();
    }

    $elapsedNs = hrtime(true) - $start;

    return [
        'label' => $label,
        'last' => $last,
        'elapsed_ms' => $elapsedNs / 1_000_000,
        'per_op_us' => ($elapsedNs / $iterations) / 1_000,
    ];
}

function runNativePhp(string $path): mixed
{
    return require $path;
}

/**
 * Compile the current tiny .jinx subset into a prepared Oracle/PASM closure.
 *
 * This removes repeated:
 * - PHP source parsing
 * - .jinx string parsing
 *
 * Runtime still uses PASM::mov/add/sub/mul/jinx_builtin/ret/end.
 */
function compilePhpToPrecompiledOracle(string $phpPath): callable
{
    $jinx = PhpToJinxLowerer::lowerFile($phpPath);
    $lines = array_values(array_filter(
        array_map('trim', preg_split('/\R/', $jinx) ?: []),
        fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')
    ));

    $ops = [];

    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line);

        if ($parts === false || $parts === []) {
            continue;
        }

        if ($parts[0] === 'assign') {
            if (count($parts) !== 4) {
                throw new RuntimeException("Invalid assign line: {$line}");
            }

            [, $name, $type, $raw] = $parts;

            if ($type === 'int') {
                $ops[] = ['mov', 'AH', (int) $raw];
                $ops[] = ['mov', 'LOCAL:' . $name, 'AH'];
                continue;
            }

            if ($type === 'string') {
                $ops[] = ['mov', 'STRING', $raw];
                $ops[] = ['mov', 'LOCAL:' . $name, 'STRING'];
                continue;
            }

            throw new RuntimeException("Unsupported assign type: {$type}");
        }

        if ($parts[0] === 'return' && ($parts[1] ?? null) === 'builtin') {
            if (count($parts) !== 5) {
                throw new RuntimeException("Invalid builtin return line: {$line}");
            }

            [, , $builtin, $argKind, $argName] = $parts;

            if ($builtin !== 'strlen' || $argKind !== 'local') {
                throw new RuntimeException("Unsupported builtin return line: {$line}");
            }

            $ops[] = ['mov', 'STRING', 'LOCAL:' . $argName];
            $ops[] = ['jinx_builtin', 'ACC', 'strlen', ['STRING']];
            $ops[] = ['ret', 'ACC'];
            continue;
        }

        if ($parts[0] === 'return') {
            if (count($parts) !== 6) {
                throw new RuntimeException("Invalid return line: {$line}");
            }

            [, $op, $leftKind, $leftName, $rightKind, $rightName] = $parts;

            if ($leftKind !== 'local' || $rightKind !== 'local') {
                throw new RuntimeException("Unsupported return operands: {$line}");
            }

            if (!in_array($op, ['add', 'sub', 'mul'], true)) {
                throw new RuntimeException("Unsupported return op: {$op}");
            }

            $ops[] = ['mov', 'ECX', 'LOCAL:' . $leftName];
            $ops[] = ['mov', 'AH', 'LOCAL:' . $rightName];
            $ops[] = [$op, 'RDX', 'ECX', 'AH'];
            $ops[] = ['ret', 'RDX'];
            continue;
        }

        throw new RuntimeException("Unsupported .jinx line: {$line}");
    }

    return static function () use ($ops): mixed {
        $pasm = PASM::start();

        foreach ($ops as $op) {
            switch ($op[0]) {
                case 'mov':
                    $pasm->mov($op[1], $op[2]);
                    break;

                case 'add':
                    $pasm->add($op[1], $op[2], $op[3]);
                    break;

                case 'sub':
                    $pasm->sub($op[1], $op[2], $op[3]);
                    break;

                case 'mul':
                    $pasm->mul($op[1], $op[2], $op[3]);
                    break;

                case 'jinx_builtin':
                    $pasm->jinx_builtin($op[1], $op[2], $op[3]);
                    break;

                case 'ret':
                    $pasm->ret($op[1]);
                    break;

                default:
                    throw new RuntimeException("Unknown precompiled Oracle op: {$op[0]}");
            }
        }

        return $pasm->end();
    };
}

/**
 * Stronger precompile mode:
 * emits a direct PHP closure for the current tiny subset.
 *
 * This is the beginning of "precompiled Oracle":
 * the source has already been lowered, and the executable PHP closure
 * contains the final command sequence.
 */
function compilePhpToDirectClosure(string $phpPath): callable
{
    $jinx = PhpToJinxLowerer::lowerFile($phpPath);
    $lines = array_values(array_filter(
        array_map('trim', preg_split('/\R/', $jinx) ?: []),
        fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')
    ));

    $locals = [];
    $return = null;

    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line);

        if ($parts === false || $parts === []) {
            continue;
        }

        if ($parts[0] === 'assign') {
            [, $name, $type, $raw] = $parts;

            if ($type === 'int') {
                $locals[$name] = (int) $raw;
                continue;
            }

            if ($type === 'string') {
                $locals[$name] = $raw;
                continue;
            }
        }

        if ($parts[0] === 'return' && ($parts[1] ?? null) === 'builtin') {
            [, , $builtin, $argKind, $argName] = $parts;

            if ($builtin === 'strlen' && $argKind === 'local') {
                $value = $locals[$argName] ?? '';
                $return = static fn() => strlen($value);
                continue;
            }
        }

        if ($parts[0] === 'return') {
            [, $op, , $leftName, , $rightName] = $parts;

            $left = $locals[$leftName] ?? null;
            $right = $locals[$rightName] ?? null;

            if ($op === 'add') {
                $return = static fn() => $left + $right;
                continue;
            }

            if ($op === 'sub') {
                $return = static fn() => $left - $right;
                continue;
            }

            if ($op === 'mul') {
                $return = static fn() => $left * $right;
                continue;
            }
        }
    }

    if (!$return) {
        throw new RuntimeException("Could not compile direct closure for {$phpPath}");
    }

    return $return;
}

echo "Precompiled Oracle/PASM vs regular PHP benchmark" . PHP_EOL;
echo "Iterations per case: {$iterations}" . PHP_EOL;
echo PHP_EOL;

printf(
    "%-16s %-22s %10s %14s %14s %12s\n",
    "case",
    "engine",
    "result",
    "elapsed ms",
    "per op us",
    "ratio"
);

echo str_repeat("-", 98) . PHP_EOL;

foreach ($cases as $case) {
    $native = bench(
        $case['name'] . ' native',
        $iterations,
        fn() => runNativePhp($case['php_file'])
    );

    $oracle = compilePhpToPrecompiledOracle($case['php_file']);
    $oracleBench = bench(
        $case['name'] . ' precompiled-oracle',
        $iterations,
        $oracle
    );

    $direct = compilePhpToDirectClosure($case['php_file']);
    $directBench = bench(
        $case['name'] . ' direct-closure',
        $iterations,
        $direct
    );

    foreach ([
        'native' => $native,
        'precompiled-oracle' => $oracleBench,
        'direct-closure' => $directBench,
    ] as $label => $bench) {
        if ($bench['last'] !== $case['expected']) {
            fwrite(
                STDERR,
                "FAIL: {$case['name']} {$label} expected {$case['expected']}, got " .
                var_export($bench['last'], true) .
                PHP_EOL
            );
            exit(1);
        }
    }

    $oracleRatio = $native['elapsed_ms'] > 0
        ? $oracleBench['elapsed_ms'] / $native['elapsed_ms']
        : 0.0;

    $directRatio = $native['elapsed_ms'] > 0
        ? $directBench['elapsed_ms'] / $native['elapsed_ms']
        : 0.0;

    printf(
        "%-16s %-22s %10s %14.3f %14.3f %12s\n",
        $case['name'],
        "php-require",
        (string) $native['last'],
        $native['elapsed_ms'],
        $native['per_op_us'],
        "1.00x"
    );

    printf(
        "%-16s %-22s %10s %14.3f %14.3f %11.2fx\n",
        $case['name'],
        "precompiled-oracle",
        (string) $oracleBench['last'],
        $oracleBench['elapsed_ms'],
        $oracleBench['per_op_us'],
        $oracleRatio
    );

    printf(
        "%-16s %-22s %10s %14.3f %14.3f %11.2fx\n",
        $case['name'],
        "direct-closure",
        (string) $directBench['last'],
        $directBench['elapsed_ms'],
        $directBench['per_op_us'],
        $directRatio
    );

    echo PHP_EOL;
}

echo "PASS: precompiled Oracle/PASM benchmark completed successfully" . PHP_EOL;
