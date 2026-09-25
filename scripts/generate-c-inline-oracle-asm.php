<?php
declare(strict_types=1);

/**
 * Generate C inline Oracle-ASM representations from the PHP callable manifest.
 *
 * Usage:
 *   php scripts/generate-c-inline-oracle-asm.php spec/php-functions.from-php-src.json build/oracle-asm
 *
 * Input may come from:
 *   - scripts/import-php-src-stubs.php over a local php-src checkout/cache
 *   - scripts/import-php-runtime-reflection.php as a fallback inventory
 *
 * Doctrine:
 *   .stub.php signatures -> manifest -> lower-level PASM call ops -> C inline Oracle-ASM form.
 *   This generator is a backend consumer of PASM-shaped call lowering. It must not invent PHP behavior.
 */

$input = $argv[1] ?? null;
$outDir = $argv[2] ?? (dirname(__DIR__) . '/build/oracle-asm');
if ($input === null || !is_file($input)) {
    fwrite(STDERR, "Usage: php scripts/generate-c-inline-oracle-asm.php manifest.json [output-dir]\n");
    exit(2);
}

$manifest = json_decode((string)file_get_contents($input), true);
if (!is_array($manifest) || !isset($manifest['functions']) || !is_array($manifest['functions'])) {
    fwrite(STDERR, "Input manifest must contain a functions array: {$input}\n");
    exit(1);
}

@mkdir($outDir, 0777, true);
@mkdir($outDir . '/stubs', 0777, true);

$runtimeHeader = $outDir . '/jinx_oracle_asm_runtime.h';
file_put_contents($runtimeHeader, emit_runtime_header());

$groups = group_functions_by_stub($manifest['functions']);
$index = [
    'manifest' => [
        'name' => 'jinx-c-inline-oracle-asm-index',
        'version' => '0.1',
        'source_manifest' => $input,
        'generated_at' => gmdate('c'),
        'doctrine' => '.stub.php signatures become lower-level PASM call skeletons, then C inline Oracle-ASM wrappers. Native behavior is still supplied by the PASM/runtime backend.',
    ],
    'files' => [],
];

foreach ($groups as $sourceFile => $functions) {
    $fileName = safe_file_slug($sourceFile) . '.oracle_asm.h';
    $path = $outDir . '/stubs/' . $fileName;
    file_put_contents($path, emit_stub_header($sourceFile, $functions));
    $index['files'][] = [
        'source_file' => $sourceFile,
        'output_file' => 'stubs/' . $fileName,
        'callables' => count($functions),
    ];
}

file_put_contents($outDir . '/oracle_asm_index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo 'PASS: generated C inline Oracle-ASM wrappers for ' . count($manifest['functions']) . ' callables across ' . count($groups) . " stub groups in {$outDir}\n";

/** @param list<array<string,mixed>> $functions @return array<string,list<array<string,mixed>>> */
function group_functions_by_stub(array $functions): array
{
    $groups = [];
    foreach ($functions as $fn) {
        if (!is_array($fn)) {
            continue;
        }
        $source = (string)($fn['source_file'] ?? 'runtime/reflection.stub.php');
        if ($source === '') {
            $source = 'runtime/reflection.stub.php';
        }
        $groups[$source][] = $fn;
    }
    ksort($groups, SORT_STRING);
    return $groups;
}

function emit_runtime_header(): string
{
    return <<<'C'
#ifndef JINX_ORACLE_ASM_RUNTIME_H
#define JINX_ORACLE_ASM_RUNTIME_H

#include <stdint.h>
#include <stddef.h>

/*
 * This header is intentionally a low-level representation layer.
 * It is not the PHP implementation. The generated inline functions are
 * C carriers for Oracle/PASM-shaped operations that a later backend can
 * expand into GCC inline asm, ASM-shaped C, or direct runtime calls.
 */

typedef struct JinxOracleAsmContext JinxOracleAsmContext;
typedef struct JinxValue JinxValue;

struct JinxValue {
    uint32_t type;
    uint32_t flags;
    union {
        int64_t i64;
        double f64;
        void *ptr;
    } as;
};

enum JinxOracleRegister {
    JINX_ORA_ACC = 0,
    JINX_ORA_RET = 1,
    JINX_ORA_R0 = 16,
    JINX_ORA_R1 = 17,
    JINX_ORA_R2 = 18,
    JINX_ORA_R3 = 19,
    JINX_ORA_R4 = 20,
    JINX_ORA_R5 = 21,
    JINX_ORA_R6 = 22,
    JINX_ORA_R7 = 23,
};

struct JinxOracleAsmContext {
    JinxValue registers[64];
    JinxValue *argv;
    uint32_t argc;
    const char *fault;
};

static inline JinxValue jinx_oracle_zero_value(void) {
    JinxValue v;
    v.type = 0;
    v.flags = 0;
    v.as.i64 = 0;
    return v;
}

static inline JinxValue jinx_oracle_asm_load_arg(JinxOracleAsmContext *ctx, uint32_t reg, uint32_t arg_index) {
    JinxValue value = jinx_oracle_zero_value();
    if (ctx != NULL && arg_index < ctx->argc && ctx->argv != NULL) {
        value = ctx->argv[arg_index];
    } else if (ctx != NULL) {
        ctx->fault = "LOAD_ARG out of range";
    }
    if (ctx != NULL && reg < 64) {
        ctx->registers[reg] = value;
    }
    return value;
}

static inline void jinx_oracle_asm_push_arg(JinxOracleAsmContext *ctx, uint32_t reg) {
    (void)ctx;
    (void)reg;
    /* Placeholder: backend/runtime call frame push. */
}

static inline void jinx_oracle_asm_push_arg_ref(JinxOracleAsmContext *ctx, uint32_t reg) {
    (void)ctx;
    (void)reg;
    /* Placeholder: backend/runtime by-reference push. */
}

static inline void jinx_oracle_asm_push_arg_variadic(JinxOracleAsmContext *ctx, uint32_t reg) {
    (void)ctx;
    (void)reg;
    /* Placeholder: backend/runtime variadic spread push. */
}

static inline JinxValue jinx_oracle_asm_call_builtin(JinxOracleAsmContext *ctx, const char *name, uint32_t argc) {
    (void)name;
    (void)argc;
    /* Placeholder: runtime dispatch table or intrinsic backend. */
    JinxValue ret = jinx_oracle_zero_value();
    if (ctx != NULL) {
        ctx->registers[JINX_ORA_RET] = ret;
    }
    return ret;
}

static inline JinxValue jinx_oracle_asm_call_method_builtin(JinxOracleAsmContext *ctx, const char *name, uint32_t argc) {
    return jinx_oracle_asm_call_builtin(ctx, name, argc);
}

static inline JinxValue jinx_oracle_asm_mov(JinxOracleAsmContext *ctx, uint32_t dst, uint32_t src) {
    JinxValue value = jinx_oracle_zero_value();
    if (ctx != NULL && src < 64) {
        value = ctx->registers[src];
    }
    if (ctx != NULL && dst < 64) {
        ctx->registers[dst] = value;
    }
    return value;
}

#define JINX_ORA_LOAD_ARG(ctx, reg, index, name_literal) \
    jinx_oracle_asm_load_arg((ctx), (reg), (index))
#define JINX_ORA_PUSH_ARG(ctx, reg) \
    jinx_oracle_asm_push_arg((ctx), (reg))
#define JINX_ORA_PUSH_ARG_REF(ctx, reg) \
    jinx_oracle_asm_push_arg_ref((ctx), (reg))
#define JINX_ORA_PUSH_ARG_VARIADIC(ctx, reg) \
    jinx_oracle_asm_push_arg_variadic((ctx), (reg))
#define JINX_ORA_CALL_BUILTIN(ctx, name_literal, argc_literal) \
    jinx_oracle_asm_call_builtin((ctx), (name_literal), (argc_literal))
#define JINX_ORA_CALL_METHOD_BUILTIN(ctx, name_literal, argc_literal) \
    jinx_oracle_asm_call_method_builtin((ctx), (name_literal), (argc_literal))
#define JINX_ORA_MOV(ctx, dst, src) \
    jinx_oracle_asm_mov((ctx), (dst), (src))

#endif /* JINX_ORACLE_ASM_RUNTIME_H */
C;
}

/** @param list<array<string,mixed>> $functions */
function emit_stub_header(string $sourceFile, array $functions): string
{
    $guard = 'JINX_ORACLE_ASM_' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $sourceFile) ?? 'STUB') . '_H';
    $body = [];
    $body[] = '#ifndef ' . $guard;
    $body[] = '#define ' . $guard;
    $body[] = '';
    $body[] = '#include "../jinx_oracle_asm_runtime.h"';
    $body[] = '';
    $body[] = '/* Source stub: ' . c_comment_escape($sourceFile) . ' */';
    $body[] = '/* Generated from PHP stub signatures through the lower-level PASM call profile. */';
    $body[] = '';

    foreach ($functions as $fn) {
        $body[] = emit_function_inline($fn);
        $body[] = '';
    }

    $body[] = '#endif /* ' . $guard . ' */';
    $body[] = '';
    return implode("\n", $body);
}

/** @param array<string,mixed> $fn */
function emit_function_inline(array $fn): string
{
    $name = (string)($fn['name'] ?? 'unknown');
    $safe = safe_c_identifier($name);
    $params = is_array($fn['parameters'] ?? null) ? $fn['parameters'] : [];
    $kind = (string)($fn['kind'] ?? 'builtin');
    $callMacro = $kind === 'method' ? 'JINX_ORA_CALL_METHOD_BUILTIN' : 'JINX_ORA_CALL_BUILTIN';
    $lines = [];
    $lines[] = '/* Callable: ' . c_comment_escape($name) . ' */';
    $lines[] = 'static inline JinxValue jinx_ora_' . $safe . '(JinxOracleAsmContext *ctx) {';

    foreach ($params as $index => $param) {
        if (!is_array($param)) {
            continue;
        }
        $regName = 'JINX_ORA_R' . $index;
        $paramName = (string)($param['name'] ?? ('arg' . $index));
        $lines[] = '    /* PASM: LOAD_ARG R' . $index . ', ' . c_comment_escape($paramName) . ' */';
        $lines[] = '    JINX_ORA_LOAD_ARG(ctx, ' . $regName . ', ' . $index . ', "' . c_string_escape($paramName) . '");';
        if (($param['by_ref'] ?? false) === true) {
            $lines[] = '    /* PASM: PUSH_ARG_REF R' . $index . ' */';
            $lines[] = '    JINX_ORA_PUSH_ARG_REF(ctx, ' . $regName . ');';
        } elseif (($param['variadic'] ?? false) === true) {
            $lines[] = '    /* PASM: PUSH_ARG_VARIADIC R' . $index . ' */';
            $lines[] = '    JINX_ORA_PUSH_ARG_VARIADIC(ctx, ' . $regName . ');';
        } else {
            $lines[] = '    /* PASM: PUSH_ARG R' . $index . ' */';
            $lines[] = '    JINX_ORA_PUSH_ARG(ctx, ' . $regName . ');';
        }
    }

    $lines[] = '    /* PASM: ' . ($kind === 'method' ? 'CALL_METHOD_BUILTIN ' : 'CALL_BUILTIN ') . c_comment_escape($name) . ', argc=' . count($params) . ' */';
    $lines[] = '    (void)' . $callMacro . '(ctx, "' . c_string_escape($name) . '", ' . count($params) . ');';
    $lines[] = '    /* PASM: MOV ACC, RET */';
    $lines[] = '    return JINX_ORA_MOV(ctx, JINX_ORA_ACC, JINX_ORA_RET);';
    $lines[] = '}';
    return implode("\n", $lines);
}

function safe_file_slug(string $path): string
{
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $path) ?? 'stub');
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'stub';
}

function safe_c_identifier(string $name): string
{
    $id = preg_replace('/[^A-Za-z0-9_]+/', '_', $name) ?? 'unknown';
    $id = trim($id, '_');
    if ($id === '') {
        $id = 'unknown';
    }
    if (preg_match('/^[0-9]/', $id)) {
        $id = '_' . $id;
    }
    return $id;
}

function c_string_escape(string $text): string
{
    return addcslashes($text, "\\\"\n\r\t");
}

function c_comment_escape(string $text): string
{
    return str_replace(['*/', "\r", "\n"], ['* /', ' ', ' '], $text);
}


function unique_c_symbol(string $base, array &$seen): string
{
    if (!isset($seen[$base])) {
        $seen[$base] = 1;
        return $base;
    }

    $seen[$base]++;
    return $base . '__' . $seen[$base];
}
