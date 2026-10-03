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

use jinx\oracle\OracleExecutionFamilies;
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
            /** @var array<string,mixed> $result */
            $result = $owner::execute($program);
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
$ops = array_values(array_unique(array_map(
    static fn(array $statement): string => (string) ($statement['op'] ?? ''),
    $program['statements'] ?? []
)));
$source = (string) file_get_contents($path);

$families = OracleExecutionFamilies::all();

// Prefer distinctive modern/object families before generic op-subset matches.
$preferred = [];
if (str_contains($source, '__get') || str_contains($source, '__call')) {
    $preferred[] = 'magic-methods';
}
if (str_contains($source, 'yield from') || preg_match('/\byield\b/', $source)) {
    $preferred[] = 'generators';
}
if (str_contains($source, 'static::') || str_contains($source, 'new static')) {
    $preferred[] = 'late-static-binding';
}
if (str_contains($source, '?->')) {
    $preferred[] = 'nullsafe-objects';
}
if (str_contains($source, 'readonly')) {
    $preferred[] = 'readonly-properties';
}
if (str_contains($source, 'clone ')) {
    $preferred[] = 'object-clone';
}
if (str_contains($source, 'instanceof')) {
    $preferred[] = 'instanceof-checks';
}

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
