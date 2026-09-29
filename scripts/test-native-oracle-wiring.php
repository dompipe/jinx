<?php

declare(strict_types=1);

// This is a wiring inventory and fail-closed test, not a semantic parity claim.
// Keep the reviewed ledger separate from extraction: removal of a handler must
// fail the test instead of silently becoming another unsupported function.
$root = dirname(__DIR__);
require_once $root . '/runtime/WebNativeFunctions.php';

function wiringFail(string $message): never
{
    fwrite(STDERR, "FAIL: native Oracle wiring: {$message}\n");
    exit(1);
}

function wiringRead(string $path): string
{
    $text = file_get_contents($path);
    if ($text === false) wiringFail("cannot read {$path}");
    return $text;
}

/** @return array<string,true> */
function wiringSet(array $names, string $label): array
{
    $set = [];
    foreach ($names as $name) {
        $key = strtolower($name);
        if (isset($set[$key])) wiringFail("duplicate {$label} name: {$name}");
        $set[$key] = true;
    }
    ksort($set);
    return $set;
}

function wiringSame(array $expected, array $actual, string $label): void
{
    $missing = array_keys(array_diff_key($expected, $actual));
    $extra = array_keys(array_diff_key($actual, $expected));
    if ($missing || $extra) {
        wiringFail("{$label}: missing=" . json_encode($missing) . ' extra=' . json_encode($extra));
    }
}

function wiringBody(string $text, string $function): string
{
    if (!preg_match('/\b' . preg_quote($function, '/') . '\s*\([^;]*?\)\s*(?::\s*\??[a-zA-Z_]+\s*)?\{/s', $text, $m, PREG_OFFSET_CAPTURE)) {
        wiringFail("missing C function {$function}");
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    // Ignore braces inside comments and literals while locating the function end.
    preg_match_all('~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|/\*.*?\*/|//[^\n]*|[{}]~s', substr($text, $start), $tokens, PREG_OFFSET_CAPTURE);
    foreach ($tokens[0] as [$token, $offset]) {
        if ($token === '{') ++$depth;
        if ($token === '}' && --$depth === 0) return substr($text, $start, $offset);
    }
    wiringFail("unclosed C function {$function}");
}

/** @return array<string,true> */
function wiringNamesInConditions(string $body): array
{
    // Read only named routing predicates, never diagnostic strings or comments.
    $body = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $body);
    preg_match_all('/(?:jinx_oracle_name_(?:is|in\d+)|strcmp)\s*\(\s*name\s*,([^)]*)\)/s', $body, $groups);
    $names = [];
    foreach ($groups[1] as $group) {
        preg_match_all('/"([A-Za-z_][A-Za-z0-9_:]*)"/', $group, $matches);
        foreach ($matches[1] as $name) $names[strtolower($name)] = true;
    }
    ksort($names);
    return $names;
}

function wiringOuterScope(string $body): string
{
    // Preserve only the outer guards, so an unreachable inner case is not
    // mistaken for a working dispatch route after its outer guard is removed.
    preg_match_all('~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|/\*.*?\*/|//[^\n]*|[{}]~s', $body, $tokens, PREG_OFFSET_CAPTURE);
    $depth = 0;
    $previous = 0;
    $outer = '';
    foreach ($tokens[0] as [$token, $offset]) {
        if ($depth === 0) $outer .= substr($body, $previous, $offset - $previous);
        if ($token === '{') {
            if ($depth === 0) $outer .= '{}';
            ++$depth;
        } elseif ($token === '}') {
            --$depth;
        } elseif ($depth === 0) {
            $outer .= $token;
        }
        $previous = $offset + strlen($token);
    }
    return $outer . substr($body, $previous);
}

$manifest = json_decode(wiringRead($root . '/spec/php-functions.from-runtime.json'), true, 512, JSON_THROW_ON_ERROR);
$functions = $manifest['functions'];
$expected = wiringSet(array_column($functions, 'name'), 'manifest');
$metadata = [];
foreach ($functions as $function) $metadata[strtolower($function['name'])] = $function;
ksort($metadata);

$registry = \jinx\web\WebNativeFunctionRegistry::wrappers();
wiringSame($expected, wiringSet(array_column($registry, 'name'), 'PHP registry'), 'PHP wrapper inventory');
wiringSame($expected, wiringSet(\jinx\web\WebNativeFunctionRegistry::names(), 'PHP compact registry'), 'PHP compact inventory');
wiringSame($expected, wiringSet(\jinx\web\WebNativeFunctions::allowedNames(), 'PHP callable inventory'), 'PHP callable inventory');
if (\jinx\web\WebNativeFunctionRegistry::COUNT !== count($expected)) wiringFail('PHP registry COUNT is stale');
foreach ($metadata as $name => $function) {
    $row = $registry[$name] ?? null;
    foreach (['required', 'total', 'variadic'] as $field) {
        if (($row[$field] ?? null) !== $function['arity'][$field]) wiringFail("PHP registry {$name} stale {$field}");
    }
    if ($row['kind'] !== $function['kind']) wiringFail("PHP registry {$name} stale kind");
}

$list = wiringRead($root . '/runtime/jinx_function_list.generated.h');
preg_match_all('/^\s*"([^"\r\n]+)",\s*$/m', $list, $listed);
wiringSame($expected, wiringSet(array_map('stripcslashes', $listed[1]), 'native list'), 'native function inventory');
if (!preg_match('/jinx_all_function_count\s*=\s*(\d+)u/', $list, $count) || (int)$count[1] !== count($expected)) {
    wiringFail('native list count is stale');
}

$index = json_decode(wiringRead($root . '/build/oracle-asm/oracle_asm_index.json'), true, 512, JSON_THROW_ON_ERROR);
$wrappers = [];
$symbols = [];
foreach ($index['files'] as $file) {
    $header = wiringRead($root . '/build/oracle-asm/' . $file['output_file']);
    preg_match_all('/\/\*\s*Callable:\s*([^*]+?)\s*\*\/\s*static\s+inline\s+JinxValue\s+(jinx_ora_\w+)\s*\([^)]*\)\s*\{(.*?)\n\}/s', $header, $matches, PREG_SET_ORDER);
    if (count($matches) !== $file['callables']) wiringFail("stale ASM index count: {$file['output_file']}");
    foreach ($matches as $match) {
        $name = strtolower(trim($match[1]));
        $symbol = $match[2];
        $body = preg_replace('~/\*.*?\*/~s', '', $match[3]);
        if (isset($wrappers[$name]) || isset($symbols[$symbol])) wiringFail("duplicate ASM wrapper {$name}/{$symbol}");
        $wrappers[$name] = $symbol;
        $symbols[$symbol] = true;
        if (!isset($metadata[$name])) wiringFail("stale ASM wrapper {$name}");
        $function = $metadata[$name];
        preg_match_all('/JINX_ORA_CALL_(METHOD_)?BUILTIN\(ctx,\s*"([^"]+)",\s*(\d+)\)/', $body, $calls, PREG_SET_ORDER);
        if (count($calls) !== 1 || stripcslashes($calls[0][2]) !== $function['name'] ||
            (bool)$calls[0][1] !== ($function['kind'] === 'method') ||
            (int)$calls[0][3] !== count($function['parameters'])) {
            wiringFail("ASM wrapper {$name} call target/kind/argument count mismatch");
        }
        preg_match_all('/JINX_ORA_LOAD_ARG\(ctx,\s*JINX_ORA_R(\d+),\s*(\d+),\s*"([^"]+)"\)/', $body, $loads, PREG_SET_ORDER);
        preg_match_all('/JINX_ORA_PUSH_ARG(_VARIADIC_REF|_REF|_VARIADIC)?\(ctx,\s*JINX_ORA_R(\d+)\)/', $body, $pushes, PREG_SET_ORDER);
        if (count($loads) !== count($function['parameters']) || count($pushes) !== count($loads)) wiringFail("ASM wrapper {$name} incomplete argument routing");
        foreach ($function['parameters'] as $i => $parameter) {
            $suffix = $parameter['variadic'] ? ($parameter['by_ref'] ? '_VARIADIC_REF' : '_VARIADIC') : ($parameter['by_ref'] ? '_REF' : '');
            if ((int)$loads[$i][1] !== $i || (int)$loads[$i][2] !== $i || $loads[$i][3] !== $parameter['name'] ||
                $pushes[$i][1] !== $suffix || (int)$pushes[$i][2] !== $i) wiringFail("ASM wrapper {$name} argument {$i} routing mismatch");
        }
    }
}
wiringSame($expected, $wrappers, 'ASM wrappers');

$dispatch = wiringRead($root . '/runtime/jinx_builtin_dispatch.generated.c');
preg_match_all('/\{\s*"([^"\r\n]+)",\s*(jinx_ora_\w+)\s*,\s*(\d+)u?\s*,\s*(\d+)u?\s*,\s*(\d+)u?\s*\}/', $dispatch, $entries, PREG_SET_ORDER);
wiringSame($expected, wiringSet(array_map('stripcslashes', array_column($entries, 1)), 'dispatch table'), 'native dispatch table');
foreach ($entries as $entry) {
    $name = strtolower(stripcslashes($entry[1]));
    if ($wrappers[$name] !== $entry[2]) wiringFail("dispatch table {$entry[1]} targets wrong wrapper");
    if ((int)$entry[3] !== $metadata[$name]['arity']['required'] ||
        (int)$entry[4] !== $metadata[$name]['arity']['total'] ||
        (int)$entry[5] !== (int)$metadata[$name]['arity']['variadic']) wiringFail("dispatch table {$entry[1]} stale arity metadata");
}

$runtime = wiringRead($root . '/build/oracle-asm/jinx_oracle_asm_runtime.h');
$scalarBody = wiringBody($runtime, 'jinx_oracle_asm_call_builtin');
$scalarOuter = wiringOuterScope($scalarBody);
$scalar = wiringNamesInConditions($scalarOuter);
$allScalarNames = wiringNamesInConditions($scalarBody);
if (str_contains($scalarOuter, 'jinx_oracle_name_starts(name, "ctype_")')) {
    foreach ($allScalarNames as $name => $_) if (str_starts_with($name, 'ctype_')) $scalar[$name] = true;
}
wiringSame($allScalarNames, $scalar, 'scalar outer guards versus named implementations');
$container = wiringNamesInConditions(wiringBody(wiringRead($root . '/runtime/jinx_oracle_zend_array_builtins.h'), 'jinx_oracle_zend_array_dispatch_builtin'));
$gates = wiringNamesInConditions(wiringBody($dispatch, 'jinx_oracle_name_is_zend_array_builtin')) +
    wiringNamesInConditions(wiringBody($dispatch, 'jinx_oracle_name_is_zend_container_builtin')) +
    wiringNamesInConditions(wiringBody($dispatch, 'jinx_call_builtin_entry_checked')) +
    wiringNamesInConditions(wiringBody($dispatch, 'jinx_call_builtin_through_oracle_checked'));
wiringSame($container, $gates, 'Zend container routes versus implementations');

$extendedSpecs = [
    ['runtime/jinx_oracle_extended_builtins.c', ['jinx_oracle_extended_builtin']],
    ['runtime/jinx_oracle_batch2_builtins.c', ['jinx_oracle_batch2_builtin']],
    ['runtime/jinx_oracle_hash_builtins.c', ['jinx_oracle_hash_builtin']],
    ['runtime/jinx_oracle_finfo_builtins.c', ['jinx_oracle_finfo_builtin']],
    ['runtime/jinx_oracle_solar_builtins.c', ['jinx_oracle_solar_builtin']],
    ['runtime/jinx_oracle_dns_builtins.c', ['jinx_oracle_dns_builtin']],
    ['runtime/jinx_oracle_ftp_builtins.c', ['jinx_oracle_ftp_builtin']],
    ['runtime/jinx_oracle_curl_ftp_builtins.c', ['jinx_oracle_curl_ftp_builtin']],
    ['runtime/jinx_oracle_http_meta_builtins.c', ['jinx_oracle_http_meta_builtin']],
    ['runtime/jinx_oracle_exif_builtins.c', ['jinx_oracle_exif_builtin']],
];
$contextSpecs = [
    ['runtime/jinx_oracle_batch2_builtins.c', ['jinx_oracle_batch2_builtin_with_context']],
    ['runtime/jinx_oracle_ftp_builtins.c', ['jinx_oracle_ftp_builtin_with_context']],
];
$extended = [];
foreach ($extendedSpecs as [$sourcePath, $functions]) {
    $source = wiringRead($root . '/' . $sourcePath);
    foreach ($functions as $function) {
        $extended += wiringNamesInConditions(wiringBody($source, $function));
    }
}
$context = [];
foreach ($contextSpecs as [$sourcePath, $functions]) {
    $source = wiringRead($root . '/' . $sourcePath);
    foreach ($functions as $function) {
        $context += wiringNamesInConditions(wiringBody($source, $function));
    }
}

/*
 * Method execution is audited by test-native-method-oracle-asm.php and must
 * not inflate the builtin named-route ledger.  A method handler may share an
 * extended backend with procedural builtins, but its public route remains
 * method-dispatch only.
 */
foreach (array_keys($extended) as $name) {
    if (($metadata[$name]['kind'] ?? null) === 'method') unset($extended[$name]);
}
foreach (array_keys($context) as $name) {
    if (($metadata[$name]['kind'] ?? null) === 'method') unset($context[$name]);
}
$delegateChecks = [
    ['runtime/jinx_oracle_extended_builtins.c', 'jinx_oracle_batch2_builtin('],
    ['runtime/jinx_oracle_extended_builtins.c', 'jinx_oracle_batch2_builtin_with_context('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_hash_builtin('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_finfo_builtin('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_solar_builtin('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_dns_builtin('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_ftp_builtin('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_ftp_builtin_with_context('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_curl_ftp_builtin('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_http_meta_builtin('],
    ['runtime/jinx_oracle_batch2_builtins.c', 'jinx_oracle_exif_builtin('],
];
foreach ($delegateChecks as [$sourcePath, $needle]) {
    if (!str_contains(wiringRead($root . '/' . $sourcePath), $needle)) {
        wiringFail("missing extended backend delegation {$needle} in {$sourcePath}");
    }
}
foreach ($scalar + $container + $extended + $context as $name => $_) {
    if (!isset($expected[$name])) wiringFail("native handler name absent from manifest: {$name}");
}

// PHP direct-dispatch entries must agree with their public name inventory too.
$phpDirect = \jinx\web\WebNativeOracleDispatch::names();
$phpDirectSet = wiringSet($phpDirect, 'PHP direct dispatch');
$phpDirectSource = wiringRead($root . '/runtime/WebNativeOracleDispatch.generated.php');
foreach (['callLowercase', 'tryCallLowercase'] as $method) {
    preg_match_all('/\'([a-z_][a-z0-9_]*)\'\s*=>\s*([a-z_][a-z0-9_]*)\(\.\.\.\$args\)/', wiringBody($phpDirectSource, $method), $phpCases, PREG_SET_ORDER);
    wiringSame($phpDirectSet, wiringSet(array_column($phpCases, 1), "PHP {$method} case"), "PHP {$method} cases");
    foreach ($phpCases as $case) if ($case[1] !== $case[2]) wiringFail("PHP {$method} name mismatch: {$case[1]}");
}
foreach ($phpDirectSet as $name => $_) if (!isset($expected[$name])) wiringFail("PHP direct callable absent from manifest: {$name}");
foreach (wiringNamesInConditions(wiringRead($root . '/runtime/jinx_php_manual_manifest.h')) as $name => $_) {
    if (!isset($expected[$name])) wiringFail("manual native classification names unregistered callable: {$name}");
}
$cli = wiringRead($root . '/native/jinx_cli.c');
if (!preg_match('/first100_names\[\]\s*=\s*\{(.*?)\};/s', $cli, $first100)) wiringFail('missing first100 inventory');
preg_match_all('/"([^"]+)"/', $first100[1], $first100Names);
foreach (wiringSet($first100Names[1], 'first100') as $name => $_) {
    if (!isset($expected[$name])) wiringFail("first100 names unregistered callable: {$name}");
}

$routes = [];
$counts = [
    'registered' => count($metadata),
    'builtin' => 0,
    'method' => 0,
    'native_named_route' => 0,
    'extended_native_route' => 0,
    'context_native_route' => 0,
    'intentional_native_fault' => 0,
];
foreach ($metadata as $name => $function) {
    ++$counts[$function['kind']];
    $route = [];
    if (isset($scalar[$name])) $route[] = 'asm';
    if (isset($container[$name])) $route[] = 'zend-container';
    if (isset($extended[$name])) {
        $route[] = 'extended';
        ++$counts['extended_native_route'];
    }
    if (isset($context[$name])) {
        $route[] = 'context';
        ++$counts['context_native_route'];
    }
    if (!$route) {
        $route[] = 'intentional-native-fault';
        ++$counts['intentional_native_fault'];
    } else {
        ++$counts['native_named_route'];
    }
    $routes[$name] = implode('+', $route);
}
$ledgerPath = $root . '/spec/native-oracle-wiring.json';
if (in_array('--write-ledger', $argv, true)) {
    $ledger = ['contract' => 'Named routes are bounded implementations, not full PHP parity. All other registered names intentionally fault in native execution; PHP worker fallbacks remain separate.', 'counts' => $counts, 'routes' => $routes];
    file_put_contents($ledgerPath, json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "Wrote reviewed-wiring candidate: {$ledgerPath}\n";
}
$ledger = json_decode(wiringRead($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
if ($ledger['routes'] !== $routes || $ledger['counts'] !== $counts) {
    $changed = array_keys(array_diff_assoc($routes, $ledger['routes']) + array_diff_assoc($ledger['routes'], $routes));
    wiringFail('reviewed native wiring ledger changed: ' . implode(', ', $changed));
}

// Compile one batch harness rather than launch thousands of CLI processes.
// Unsupported wrappers get all declared arguments so an arity error cannot
// disguise a missing terminal fault or a fabricated native result. Extended
// and context routes are inventoried statically here; their dedicated parity
// tests and the full native build exercise the linked backend implementations.
$build = $root . '/build/native';
if (!is_dir($build)) mkdir($build, 0777, true);
$rows = [];
foreach ($metadata as $name => $function) {
    $argc = (int)$function['arity']['total'] + ($function['arity']['variadic'] ? 2 : 0);
    if ($argc > 64) wiringFail("{$name} exceeds the audited native frame capacity");
    $rows[] = '    {' . json_encode($function['name']) . ', ' . $argc . 'u, ' . ($routes[$name] === 'intentional-native-fault' ? '1' : '0') .
        ', ' . $function['arity']['required'] . 'u, ' . $function['arity']['total'] . 'u, ' . (int)$function['arity']['variadic'] . '},';
}
$harness = <<<'C'
#include <stdio.h>
#include <string.h>
#include "jinx_builtin_dispatch.h"
#include "jinx_php_manual_manifest.h"
struct WiringRow { const char *name; unsigned argc; int must_fault; unsigned required; unsigned total; int variadic; };
static const struct WiringRow rows[] = {
C;
$harness .= "\n" . implode("\n", $rows) . "\n};\n";
$harness .= <<<'C'
int main(void) {
    JinxValue args[65];
    size_t rejected = 0;
    for (unsigned i = 0; i < 65; ++i) args[i] = jinx_value_string("audit", 5);
    for (size_t i = 0; i < sizeof(rows) / sizeof(rows[0]); ++i) {
        JinxOracleWrapper wrapper = jinx_lookup_oracle_wrapper(rows[i].name);
        if (wrapper == NULL) { fprintf(stderr, "missing wrapper: %s\n", rows[i].name); return 1; }
        const JinxPhpManualHandlerSpec *manual = jinx_php_manual_lookup(rows[i].name);
        if (rows[i].must_fault && manual != NULL &&
            (manual->state == JINX_PHP_MANUAL_EXACT || manual->state == JINX_PHP_MANUAL_PARTIAL)) {
            fprintf(stderr, "manual claims native behavior without named implementation: %s (%s)\n", rows[i].name, manual->pattern); return 1;
        }
        if (rows[i].required > 0) {
            int ok = 1;
            (void)jinx_call_builtin_through_oracle_checked(rows[i].name, args, rows[i].required - 1, &ok);
            if (ok) { fprintf(stderr, "accepted too few arguments: %s\n", rows[i].name); return 1; }
        }
        if (!rows[i].variadic) {
            int ok = 1;
            (void)jinx_call_builtin_through_oracle_checked(rows[i].name, args, rows[i].total + 1, &ok);
            if (ok) { fprintf(stderr, "accepted too many arguments: %s\n", rows[i].name); return 1; }
        }
        if (rows[i].must_fault) {
            JinxOracleAsmContext ctx;
            jinx_ora_context_init(&ctx, args, rows[i].argc);
            (void)wrapper(&ctx);
            if (ctx.fault == NULL || strcmp(ctx.fault, "No exact native Oracle ASM handler for builtin") != 0) {
                fprintf(stderr, "unsupported wrapper did not reach intentional terminal fault: %s (%s)\n", rows[i].name, ctx.fault ? ctx.fault : "success"); return 1;
            }
            if (ctx.call_argc != rows[i].argc) {
                fprintf(stderr, "wrapper argument loss: %s (%u versus %u)\n", rows[i].name, ctx.call_argc, rows[i].argc); return 1;
            }
            int ok = 1;
            (void)jinx_call_builtin_through_oracle_checked(rows[i].name, args, rows[i].argc, &ok);
            if (ok) { fprintf(stderr, "checked dispatcher accepted unsupported: %s\n", rows[i].name); return 1; }
            ++rejected;
        }
    }
    JinxValue variadic_args[] = {jinx_value_int(1), jinx_value_int(2), jinx_value_int(3)};
    int variadic_ok = 0;
    JinxValue variadic_result = jinx_call_builtin_through_oracle_checked("max", variadic_args, 3, &variadic_ok);
    if (!variadic_ok || variadic_result.type != 1u || variadic_result.as.i64 != 3) {
        fprintf(stderr, "valid variadic invocation did not preserve all arguments\n"); return 1;
    }
    JinxOracleAsmContext overflow;
    jinx_ora_context_init(&overflow, args, 65);
    (void)jinx_lookup_oracle_wrapper("max")(&overflow);
    if (overflow.fault == NULL || overflow.registers[JINX_ORA_RET].type != 0u) {
        fprintf(stderr, "variadic frame overflow was lost or returned fabricated value\n"); return 1;
    }
    JinxOracleAsmContext refs;
    jinx_ora_context_init(&refs, args, 4);
    (void)jinx_lookup_oracle_wrapper("sscanf")(&refs);
    if (refs.call_argc != 4 || refs.call_arg_kinds[2] != 1 || refs.call_arg_kinds[3] != 1 ||
        refs.call_arg_source_index[2] != 2 || refs.call_arg_source_index[3] != 3) {
        fprintf(stderr, "reference variadic tail lost values, reference kind, or caller slot\n"); return 1;
    }
    const char *unknown[] = {"__jinx_unknown_builtin", "ctype_unknown", "cos_unknown", "gz_unknown"};
    for (size_t i = 0; i < sizeof(unknown) / sizeof(unknown[0]); ++i) {
        JinxOracleAsmContext ctx;
        jinx_ora_context_init(&ctx, args, 1);
        ctx.call_args[0] = args[0]; ctx.call_argc = 1;
        (void)jinx_oracle_asm_call_builtin(&ctx, unknown[i], 1);
        if (ctx.fault == NULL) { fprintf(stderr, "implicit catch-all accepted %s\n", unknown[i]); return 1; }
        int ok = 1;
        (void)jinx_call_builtin_through_oracle_checked(unknown[i], args, 1, &ok);
        if (ok) { fprintf(stderr, "unknown dispatcher name succeeded: %s\n", unknown[i]); return 1; }
    }
    printf("PASS: %zu registered wrappers resolve; %zu intentional native faults execute\n", sizeof(rows) / sizeof(rows[0]), rejected);
    return 0;
}
C;
$source = $build . '/native-oracle-wiring-audit.c';
$binary = $build . '/native-oracle-wiring-audit';
file_put_contents($source, $harness);
$command = escapeshellarg(getenv('CC') ?: 'gcc') . ' -std=c11 -O0 -I' . escapeshellarg($root . '/runtime') .
    ' ' . escapeshellarg($source) . ' ' . escapeshellarg($root . '/runtime/jinx_builtin_dispatch.generated.c') .
    ' ' . escapeshellarg($root . '/runtime/jinx_oracle_asm_context.c') . ' ' . escapeshellarg($root . '/runtime/jinx_zend_engine.c') .
    ' ' . escapeshellarg($root . '/runtime/jinx_oracle_frame_context.c') .
    ' -lm -lz -o ' . escapeshellarg($binary);
$output = [];
exec($command . ' 2>&1', $output, $status);
if ($status !== 0) wiringFail("audit build failed\n" . implode("\n", $output));
$output = [];
exec(escapeshellarg($binary) . ' 2>&1', $output, $status);
if ($status !== 0) wiringFail("audit execution failed\n" . implode("\n", $output));
echo implode("\n", $output) . "\n";
echo 'PASS: native Oracle wiring inventory and intentional faults verified ' . json_encode($counts) . "\n";
