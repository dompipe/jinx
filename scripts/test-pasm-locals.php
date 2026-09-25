<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$buildDir = $root . '/build/pasm-locals-test';
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$testC = $buildDir . '/test_pasm_locals.c';
$binary = $buildDir . '/test_pasm_locals';

file_put_contents($testC, <<<'C'
#include <stdio.h>
#include "../../runtime/jinx_pasm_machine.h"

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxPasmMachine machine;
    JinxValue out;

    const JinxPasmOp program[] = {
        {
            .op = JINX_PASM_PUSH_VALUE,
            .name = NULL,
            .value = jinx_value_int(2),
            .argc = 0
        },
        {
            .op = JINX_PASM_STORE_LOCAL,
            .name = "x",
            .value = {0},
            .argc = 0
        },
        {
            .op = JINX_PASM_PUSH_VALUE,
            .name = NULL,
            .value = jinx_value_int(3),
            .argc = 0
        },
        {
            .op = JINX_PASM_STORE_LOCAL,
            .name = "y",
            .value = {0},
            .argc = 0
        },
        {
            .op = JINX_PASM_LOAD_LOCAL,
            .name = "x",
            .value = {0},
            .argc = 0
        },
        {
            .op = JINX_PASM_LOAD_LOCAL,
            .name = "y",
            .value = {0},
            .argc = 0
        },
        {
            .op = JINX_PASM_ADD,
            .name = NULL,
            .value = {0},
            .argc = 0
        },
        {
            .op = JINX_PASM_RETURN_VALUE,
            .name = NULL,
            .value = {0},
            .argc = 0
        }
    };

    jinx_pasm_machine_init(&machine);

    if (!jinx_pasm_run(&machine, program, &out)) {
        fprintf(stderr, "PASM fault: %s\n", machine.fault ? machine.fault : "(none)");
        return fail("PASM locals program failed");
    }

    if (out.as.i64 != 5) {
        fprintf(
            stderr,
            "DEBUG locals type=%u flags=%u i64=%lld\n",
            out.type,
            out.flags,
            (long long) out.as.i64
        );

        return fail("PASM locals ADD did not return 5");
    }

    printf("PASS: PASM machine executes STORE_LOCAL LOAD_LOCAL ADD RETURN_VALUE\n");
    return 0;
}
C);

$cmd = sprintf(
    'cc -std=c11 -Wall -Wextra -I%s/runtime -I%s/build/oracle-asm %s %s %s %s -o %s 2>&1',
    escapeshellarg($root),
    escapeshellarg($root),
    escapeshellarg($testC),
    escapeshellarg($root . '/runtime/jinx_oracle_asm_context.c'),
    escapeshellarg($root . '/runtime/jinx_builtin_dispatch.generated.c'),
    escapeshellarg($root . '/runtime/jinx_pasm_machine.c'),
    escapeshellarg($binary)
);

exec($cmd, $compileOutput, $compileCode);

if ($compileCode !== 0) {
    fwrite(STDERR, implode(PHP_EOL, $compileOutput) . PHP_EOL);
    exit(1);
}

exec(escapeshellarg($binary) . ' 2>&1', $runOutput, $runCode);

echo implode(PHP_EOL, $runOutput) . PHP_EOL;

if ($runCode !== 0) {
    exit($runCode);
}
