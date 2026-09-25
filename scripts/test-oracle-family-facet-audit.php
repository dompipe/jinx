<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleConditionalExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleLoopExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleArrayExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleFunctionExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleRequestExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleIncludeExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExitExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleObjectInheritanceExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExpressionBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleBuiltinBatchExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleScalarBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleAppBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleMathBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleDataBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleTextBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';

use jinx\oracle\OracleExecutionFamilies;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/**
 * @return list<string>
 */
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

$root = dirname(__DIR__);
$families = OracleExecutionFamilies::all();
$facetFields = [
    'ops',
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

    $testSource = (string) file_get_contents($root . '/' . $test);
    $testSource .= "\n" . $family;

    foreach ($facetFields as $field) {
        if (!array_key_exists($field, $metadata)) {
            continue;
        }

        foreach (flatten_facet_values($metadata[$field]) as $facet) {
            $totalFacets++;
            if (!contains_facet_token($testSource, $facet)) {
                $missing[] = "{$family}: {$field} facet not represented by {$test}: {$facet}";
            }
        }
    }
}

if ($missing !== []) {
    fail('Oracle family facet audit found uncovered declared facets:' . PHP_EOL . implode(PHP_EOL, $missing));
}

if ($totalFacets < 350) {
    fail('Oracle family facet audit expected at least 350 declared facets, found ' . $totalFacets);
}

echo 'PASS: Oracle family facet audit validates ' . $totalFacets . ' declared facets across ' . count($families) . ' executable families' . PHP_EOL;
