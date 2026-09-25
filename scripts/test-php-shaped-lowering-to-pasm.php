<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PASM.php';

use jinx\pasm\PASM;

/*
 * Source shape:
 *
 * $x = 2;
 * $y = 3;
 * return $x + $y;
 *
 * Lowered Oracle-style PASM:
 *
 * MOV AH, 2
 * MOV LOCAL:x, AH
 * MOV AH, 3
 * MOV LOCAL:y, AH
 * MOV ECX, LOCAL:x
 * MOV AH, LOCAL:y
 * ADD RDX, ECX, AH
 * RET RDX
 * END
 */

$result = PASM::start()
    ->mov('AH', 2)
    ->mov('LOCAL:x', 'AH')
    ->mov('AH', 3)
    ->mov('LOCAL:y', 'AH')
    ->mov('ECX', 'LOCAL:x')
    ->mov('AH', 'LOCAL:y')
    ->add('RDX', 'ECX', 'AH')
    ->ret('RDX')
    ->end();

if ($result !== 5) {
    fwrite(STDERR, "FAIL: expected 5, got " . var_export($result, true) . PHP_EOL);
    exit(1);
}

$expected = [
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'MOV',
    'ADD',
    'RET',
    'END',
];

if (PASM::$chain !== $expected) {
    fwrite(STDERR, "FAIL: PASM chain mismatch\n");
    fwrite(STDERR, json_encode(PASM::$chain) . PHP_EOL);
    exit(1);
}

echo "PASS: PHP-shaped assignment lowers to chained Oracle-style PASM and returns 5\n";
