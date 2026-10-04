<?php

declare(strict_types=1);

/**
 * Native ./jinx script bridge.
 *
 * IMPORTANT: this does not require/execute the target PHP file with PHP.
 * PHP is only hosting the Oracle/Jinx interpreter classes. The target file is
 * recorded into an Oracle program and executed by a registered Oracle family.
 */

$root = dirname(__DIR__);

foreach (glob($root . '/runtime/Oracle*Executor.php') ?: [] as $file) {
    require_once $file;
}
require_once $root . '/runtime/OracleProgramCompiler.php';
require_once $root . '/runtime/OracleExecutionFamilies.php';
require_once $root . '/runtime/OracleGeneratedExecutionFamilies.php';
require_once $root . '/runtime/OracleMergedExecutionFamilies.php';

use jinx\oracle\OracleMergedExecutionFamilies;
use jinx\oracle\OracleProgramCompiler;
use jinx\oracle\OracleModernObjectExecutor;

function jinx_fail(string $message): never
{
    fwrite(STDERR, "JINX SCRIPT ERROR: {$message}" . PHP_EOL);
    exit(1);
}

/** @param list<string> $supported @param list<string> $actual */
function jinx_ops_supported(array $supported, array $actual): bool
{
    foreach ($actual as $op) {
        if ($op === '') {
            continue;
        }
        if (!in_array($op, $supported, true)) {
            return false;
        }
    }
    return true;
}

/** @param array<string,mixed> $program */
function jinx_execute_family(string $family, array $metadata, array $program): ?array
{
    $owner = $metadata['owner'] ?? null;
    if (!is_string($owner) || !class_exists($owner)) {
        return null;
    }

    try {
        if ($owner === OracleModernObjectExecutor::class) {
            return match ($family) {
                'late-static-binding' => OracleModernObjectExecutor::executeLateStaticBinding($program),
                'nullsafe-objects' => OracleModernObjectExecutor::executeNullsafe($program),
                'readonly-properties' => OracleModernObjectExecutor::executeReadonly($program),
                default => null,
            };
        }

        if (method_exists($owner, 'execute')) {
            $method = new ReflectionMethod($owner, 'execute');
            /** @var array<string,mixed> $result */
            $result = $method->getNumberOfParameters() >= 2
                ? $owner::execute($program, $family)
                : $owner::execute($program);
            return $result;
        }
    } catch (Throwable) {
        return null;
    }

    return null;
}

if ($argc < 2) {
    jinx_fail('missing PHP input file');
}

$path = $argv[1];
if (!is_file($path)) {
    jinx_fail("input file not found: {$path}");
}

$program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($path);

// Model harmless runtime-configuration calls that affect PHP execution context
// but do not themselves produce target output. Keep this narrow and explicit.
$filteredStatements = [];
foreach ($program['statements'] ?? [] as $statement) {
    if (
        is_array($statement) &&
        (($statement['op'] ?? null) === 'O_CALL') &&
        preg_match('/^error_reporting\s*\(\s*E_ALL\s*\)\s*;?$/i', trim((string) ($statement['source'] ?? '')))
    ) {
        error_reporting(E_ALL);
        continue;
    }

    $filteredStatements[] = $statement;
}
$program['statements'] = $filteredStatements;

$ops = array_values(array_unique(array_map(
    static fn(array $statement): string => (string) ($statement['op'] ?? ''),
    $program['statements'] ?? []
)));
$source = (string) file_get_contents($path);

$families = OracleMergedExecutionFamilies::all();

// Prefer distinctive modern/object families before generic op-subset matches.
$preferred = [];

$calledBuiltins = [];
if (preg_match_all('/\b([A-Za-z_]\w*)\s*\(/', $source, $builtinMatches)) {
    $languageConstructs = [
        'array', 'echo', 'print', 'isset', 'empty', 'unset', 'include', 'include_once',
        'require', 'require_once', 'if', 'elseif', 'while', 'for', 'foreach', 'switch',
        'match', 'return', 'function', 'fn', 'new', 'clone', 'catch', 'throw', 'exit', 'die',
    ];

    foreach ($builtinMatches[1] as $callName) {
        $lower = strtolower((string) $callName);
        if (!in_array($lower, $languageConstructs, true)) {
            $calledBuiltins[$lower] = true;
        }
    }
}

foreach ($families as $familyName => $metadata) {
    if (!is_array($metadata)) {
        continue;
    }

    $declaredBuiltins = $metadata['builtins'] ?? [];
    if (!is_array($declaredBuiltins) || $declaredBuiltins === []) {
        continue;
    }

    foreach ($declaredBuiltins as $builtin) {
        if (is_string($builtin) && isset($calledBuiltins[strtolower($builtin)])) {
            $preferred[] = $familyName;
            break;
        }
    }
}

$modernPatterns = [
    'static::' => 'late-static-binding',
    'new static()' => 'late-static-binding',
    '?->' => 'nullsafe-objects',
    'readonly' => 'readonly-properties',
    '__get' => 'magic-methods',
    '__call' => 'magic-methods',
    'yield from' => 'generators',
    'yield ' => 'generators',
    'clone ' => 'object-clone',
    'instanceof' => 'instanceof-checks',
];

foreach ($modernPatterns as $pattern => $family) {
    if (str_contains($source, $pattern)) {
        $preferred[] = $family;
    }
}

$preferred = array_values(array_unique($preferred));
$candidates = array_values(array_unique(array_merge($preferred, array_keys($families))));

foreach ($candidates as $family) {
    $metadata = $families[$family] ?? null;
    if (!is_array($metadata)) {
        continue;
    }

    $supported = $metadata['ops'] ?? [];
    if (!is_array($supported) || $supported === [] || !jinx_ops_supported($supported, $ops)) {
        continue;
    }

    $result = jinx_execute_family($family, $metadata, $program);
    if (!is_array($result)) {
        continue;
    }

    fwrite(STDOUT, (string) ($result['output'] ?? ''));
    exit(0);
}

jinx_fail(
    'no registered Oracle execution family accepted this script; refusing PHP fallback: ' .
    $path . ' ops=' . implode(',', $ops)
);
