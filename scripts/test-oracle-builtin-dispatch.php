<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$buildDir = $root . '/build/oracle-dispatch-test';
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0777, true);
}

$testC = $buildDir . '/test_oracle_dispatch.c';
$binary = $buildDir . '/test_oracle_dispatch';
$cc = getenv('CC') ?: 'gcc';

file_put_contents($testC, <<<'C'
#include <stdio.h>
#include "../../runtime/jinx_builtin_dispatch.h"

static int fail(const char *message) {
    fprintf(stderr, "FAIL: %s\n", message);
    return 1;
}

int main(void) {
    JinxValue strlen_args[1];
    strlen_args[0] = jinx_value_string("oracle", 6);

    JinxValue strlen_result = jinx_call_builtin_through_oracle(
        "strlen",
        strlen_args,
        1
    );

    if (strlen_result.type != 1u || strlen_result.as.i64 != 6) {
        return fail("strlen through Oracle dispatcher did not return 6");
    }

    JinxValue count_args[1];
    count_args[0] = jinx_value_array_count(3);

    JinxValue count_result = jinx_call_builtin_through_oracle(
        "count",
        count_args,
        1
    );

    if (count_result.type != 1u || count_result.as.i64 != 3) {
        return fail("count through Oracle dispatcher did not return 3");
    }

    printf("PASS: Oracle builtin dispatcher executed strlen and count through wrappers\n");
    return 0;
}
C);

$cmd = sprintf(
    '%s -std=c11 -Wall -Wextra -Werror -I%s/runtime %s %s %s %s -lm -lz -o %s 2>&1',
    escapeshellcmd($cc),
    escapeshellarg($root),
    escapeshellarg($testC),
    escapeshellarg($root . '/runtime/jinx_oracle_asm_context.c'),
    escapeshellarg($root . '/runtime/jinx_builtin_dispatch.c'),
    escapeshellarg($root . '/runtime/jinx_zend_engine.c'),
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
