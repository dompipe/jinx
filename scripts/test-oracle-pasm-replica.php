<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/PASM.php';

use jinx\pasm\PASM;

/*
 * Oracle-style PASM chain:
 *
 * MOV AH, 2
 * MOV ECX, 3
 * ADD RDX, ECX, AH
 * RET RDX
 * END
 */
$result = PASM::start()
    ->mov('AH', 2)
    ->mov('ECX', 3)
    ->add('RDX', 'ECX', 'AH')
    ->ret('RDX')
    ->end();

if ($result !== 5) {
    fwrite(STDERR, "FAIL: expected 5, got " . var_export($result, true) . PHP_EOL);
    exit(1);
}

$expected = ['MOV', 'MOV', 'ADD', 'RET', 'END'];

if (PASM::$chain !== $expected) {
    fwrite(STDERR, "FAIL: PASM chain did not match Oracle command sequence" . PHP_EOL);
    fwrite(STDERR, json_encode(PASM::$chain) . PHP_EOL);
    exit(1);
}

echo "PASS: PASM Oracle ASM replica chain executes MOV ADD RET END\n";
