<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleSwitchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExceptionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForeachExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleForExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDoWhileExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGlobalScopeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleStaticLocalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRequestExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIncludeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExitExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleStaticMethodExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleInstanceofExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleCloneExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectInheritanceExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExpressionBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleBuiltinBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleScalarBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleAppBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDataBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArraySetBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFilesystemBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleTextBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDateTimeBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIntrospectionBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRegexStringBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayMutationBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleSecurityNetworkBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRuntimeInfoBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleJinxWebExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleMergedExecutionFamilies.php';

use jinx\oracle\OracleMergedExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return list<string> */
function flatten_facet_values(mixed $value): array
{
    if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
        return [(string) $value];
    }

    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $inner) {
        array_push($out, ...flatten_facet_values($inner));
    }

    return $out;
}

function contains_facet_token(string $source, string $facet): bool
{
    if ($facet === '') {
        return true;
    }

    $needles = array_values(array_unique([
        $facet,
        str_replace('-', '_', $facet),
        str_replace('_', '-', $facet),
        str_replace(' ', '_', $facet),
        str_replace(' ', '-', $facet),
    ]));

    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($source, $needle)) {
            return true;
        }
    }

    return false;
}

function source_or_empty(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return list<string> */
function likely_companion_paths(string $root, string $test): array
{
    $base = basename($test, '.php');
    $suffix = preg_replace('/^test-/', '', $base) ?? $base;
    $paths = [$test];

    foreach ([
        "fixtures/{$suffix}.php",
        "fixtures/{$suffix}-fixture.php",
        "fixtures/{$suffix}-cases.php",
        "fixtures/{$suffix}-execution.php",
        "fixtures/oracle-{$suffix}.php",
        "fixtures/oracle-{$suffix}-cases.php",
    ] as $candidate) {
        if (is_file($root . '/' . $candidate)) {
            $paths[] = $candidate;
        }
    }

    return array_values(array_unique($paths));
}

function owner_path_from_class(string $owner): ?string
{
    $prefix = 'jinx\\oracle\\';
    if (!str_starts_with($owner, $prefix)) {
        return null;
    }

    return 'runtime/' . substr($owner, strlen($prefix)) . '.php';
}

$root = dirname(__DIR__);
$families = OracleMergedExecutionFamilies::all();

$behaviorFacetFields = [
    'builtins',
    'control_flow',
    'array_ops',
    'function_ops',
    'object_ops',
    'request_ops',
    'loader_ops',
    'termination_ops',
    'expression_ops',
    'casts',
    'comparisons',
    'boolean_operators',
    'magic_constants',
    'superglobals',
    'string_ops',
];

$totalFacets = 0;
$missing = [];

foreach ($families as $family => $metadata) {
    $test = $metadata['test'] ?? null;
    if (!is_string($test) || $test === '' || !is_file($root . '/' . $test)) {
        $missing[] = "{$family}: missing facet test file {$test}";
        continue;
    }

    $coverageSource = $family . "\n";

    foreach (likely_companion_paths($root, $test) as $path) {
        $coverageSource .= "\n// {$path}\n" . source_or_empty($root . '/' . $path);
    }

    $owner = $metadata['owner'] ?? null;
    if (is_string($owner)) {
        $ownerPath = owner_path_from_class($owner);
        if ($ownerPath !== null) {
            $coverageSource .= "\n// {$ownerPath}\n" . source_or_empty($root . '/' . $ownerPath);
        }
    }

    $coverageSource .= "\n// family-metadata\n" . var_export($metadata, true);

    foreach ($behaviorFacetFields as $field) {
        if (!array_key_exists($field, $metadata)) {
            continue;
        }

        foreach (flatten_facet_values($metadata[$field]) as $facet) {
            $totalFacets++;
            if (!contains_facet_token($coverageSource, $facet)) {
                $missing[] = "{$family}: {$field} facet not represented by {$test}/owner corpus: {$facet}";
            }
        }
    }
}

if ($missing !== []) {
    fail('Oracle family facet audit found uncovered declared facets:' . PHP_EOL . implode(PHP_EOL, $missing));
}

if ($totalFacets < 375) {
    fail('Oracle family facet audit expected at least 375 declared behavior facets after generated merge, found ' . $totalFacets);
}

echo 'PASS: Oracle family facet audit validates ' . $totalFacets . ' declared behavior facets across ' . count($families) . ' executable families' . PHP_EOL;
