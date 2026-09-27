<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jinx-c-inline-oracle-asm-test-' . getmypid();
@mkdir($tmp, 0777, true);
$manifest = [
    'manifest' => [
        'name' => 'fixture',
        'version' => '0.1',
        'source' => 'test fixture',
        'doctrine' => 'fixture',
    ],
    'functions' => [
        [
            'name' => 'strlen',
            'kind' => 'builtin',
            'extension' => 'standard',
            'source_file' => 'ext/standard/basic_functions.stub.php',
            'parameters' => [
                ['name' => 'string', 'type' => 'string', 'by_ref' => false, 'variadic' => false, 'required' => true, 'default' => null],
            ],
            'return' => ['type' => 'int'],
            'pasm_lowering' => ['LOAD_ARG R0, string', 'PUSH_ARG R0', 'CALL_BUILTIN strlen, argc=1', 'MOV ACC, RET'],
            'native_strategy' => 'intrinsic',
            'status' => 'imported_signature',
        ],
        [
            'name' => 'array_push',
            'kind' => 'builtin',
            'extension' => 'standard',
            'source_file' => 'ext/standard/basic_functions.stub.php',
            'parameters' => [
                ['name' => 'array', 'type' => 'array', 'by_ref' => true, 'variadic' => false, 'required' => true, 'default' => null],
                ['name' => 'values', 'type' => 'mixed', 'by_ref' => false, 'variadic' => true, 'required' => true, 'default' => null],
            ],
            'return' => ['type' => 'int'],
            'pasm_lowering' => ['LOAD_ARG R0, array', 'PUSH_ARG_REF R0', 'LOAD_ARG R1, values', 'PUSH_ARG_VARIADIC R1', 'CALL_BUILTIN array_push, argc=2', 'MOV ACC, RET'],
            'native_strategy' => 'runtime_helper',
            'status' => 'imported_signature',
        ],
        [
            'name' => 'DateTime::format',
            'kind' => 'method',
            'owner' => 'DateTime',
            'extension' => 'date',
            'source_file' => 'ext/date/php_date.stub.php',
            'parameters' => [
                ['name' => 'format', 'type' => 'string', 'by_ref' => false, 'variadic' => false, 'required' => true, 'default' => null],
            ],
            'return' => ['type' => 'string'],
            'pasm_lowering' => ['LOAD_ARG R0, format', 'PUSH_ARG R0', 'CALL_METHOD_BUILTIN DateTime::format, argc=1', 'MOV ACC, RET'],
            'native_strategy' => 'runtime_helper',
            'status' => 'imported_signature',
        ],
    ],
];
$manifestPath = $tmp . '/manifest.json';
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$outDir = $tmp . '/out';
$cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/scripts/generate-c-inline-oracle-asm.php') . ' ' . escapeshellarg($manifestPath) . ' ' . escapeshellarg($outDir);
exec($cmd, $output, $code);
if ($code !== 0) {
    fwrite(STDERR, implode("\n", $output) . "\n");
    exit($code);
}
$standardHeader = $outDir . '/stubs/ext-standard-basic-functions-stub-php.oracle_asm.h';
$dateHeader = $outDir . '/stubs/ext-date-php-date-stub-php.oracle_asm.h';
$runtimeHeader = $outDir . '/jinx_oracle_asm_runtime.h';
foreach ([$standardHeader, $dateHeader, $runtimeHeader, $outDir . '/oracle_asm_index.json'] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing generated file: {$path}\n");
        exit(1);
    }
}
$standard = (string)file_get_contents($standardHeader);
$date = (string)file_get_contents($dateHeader);
$runtime = (string)file_get_contents($runtimeHeader);
$checks = [
    'static inline JinxValue jinx_ora_strlen' => $standard,
    'JINX_ORA_LOAD_ARG(ctx, JINX_ORA_R0, 0, "string")' => $standard,
    'JINX_ORA_PUSH_ARG(ctx, JINX_ORA_R0)' => $standard,
    'JINX_ORA_CALL_BUILTIN(ctx, "strlen", 1)' => $standard,
    'JINX_ORA_PUSH_ARG_REF(ctx, JINX_ORA_R0)' => $standard,
    'JINX_ORA_PUSH_ARG_VARIADIC(ctx, JINX_ORA_R1)' => $standard,
    'static inline JinxValue jinx_ora_DateTime_format' => $date,
    'JINX_ORA_CALL_METHOD_BUILTIN(ctx, "DateTime::format", 1)' => $date,
    'enum JinxOracleRegister' => $runtime,
];
foreach ($checks as $needle => $haystack) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "Generated output missing expected fragment: {$needle}\n");
        exit(1);
    }
}

// Compile a small C translation unit to prove the generated representation is syntactically usable.
$cFile = $tmp . '/compile_check.c';
file_put_contents($cFile, "#include \"{$standardHeader}\"\n#include \"{$dateHeader}\"\nint main(void) { JinxOracleAsmContext ctx = {0}; (void)jinx_ora_strlen(&ctx); (void)jinx_ora_array_push(&ctx); (void)jinx_ora_DateTime_format(&ctx); return 0; }\n");
$gcc = trim((string)shell_exec('command -v gcc 2>/dev/null'));
if ($gcc !== '') {
    $compile = $gcc . ' -std=c99 -Wall -Wextra -Werror -I' . escapeshellarg($outDir . '/stubs') . ' -I' . escapeshellarg($outDir) . ' ' . escapeshellarg($cFile) . ' -o ' . escapeshellarg($tmp . '/compile_check');
    exec($compile . ' 2>&1', $compileOutput, $compileCode);
    if ($compileCode !== 0) {
        fwrite(STDERR, implode("\n", $compileOutput) . "\n");
        exit(1);
    }
}

$sentinel = "/* preserve-existing-runtime */\n";
file_put_contents($runtimeHeader, $sentinel);
$output2 = [];
$code2 = 0;
exec($cmd, $output2, $code2);
if ($code2 !== 0 || (string)file_get_contents($runtimeHeader) !== $sentinel) {
    fwrite(STDERR, "Generator overwrote an existing Oracle ASM runtime header\n");
    exit(1);
}

echo "PASS: C inline Oracle-ASM generator emitted callable wrappers, by-ref/variadic handling, method wrappers, compilable headers, and preserves the implemented runtime\n";
