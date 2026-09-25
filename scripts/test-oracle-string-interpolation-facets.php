<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleStraightLineExecutor.php';

use jinx\oracle\OracleStraightLineExecutor;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (RuntimeException) {
        return;
    }

    fail($label . ': expected RuntimeException');
}

/** @param array<string,mixed> $locals */
function eval_oracle_expr(string $expr, array $locals): mixed
{
    $method = new ReflectionMethod(OracleStraightLineExecutor::class, 'evaluateExpression');
    $method->setAccessible(true);

    return $method->invokeArgs(null, [$expr, &$locals]);
}

$locals = [
    'name' => 'JINX',
    'verb' => 'flies',
    'key' => 'name',
    'countKey' => 'count',
    'row' => [
        'name' => 'Oracle',
        'count' => 7,
        2 => 'two',
    ],
    'nested' => [
        'outer' => ['inner' => 'deep'],
    ],
];

$cases = [
    'plain double string' => ['"plain"', 'plain'],
    'simple variable' => ['"Hello $name"', 'Hello JINX'],
    'two simple variables' => ['"$name $verb"', 'JINX flies'],
    'braced simple variable' => ['"Hello {$name}"', 'Hello JINX'],
    'unbraced array bare key' => ['"Simple $row[name]"', 'Simple Oracle'],
    'unbraced array numeric key' => ['"Number $row[2]"', 'Number two'],
    'braced array quoted key' => ['"Quoted {$row[\'name\']}"', 'Quoted Oracle'],
    'braced array double quoted key' => ['"Double quoted {$row[\"name\"]}"', 'Double quoted Oracle'],
    'braced array dynamic key' => ['"Dynamic {$row[$key]}"', 'Dynamic Oracle'],
    'braced array dynamic count key' => ['"Count {$row[$countKey]}"', 'Count 7'],
    'nested array path' => ['"Nested {$nested[\'outer\'][\'inner\']}"', 'Nested deep'],
    'escaped dollar' => ['"Dollar stays: \\$name"', 'Dollar stays: $name'],
    'newline escape' => ['"Line\\n$name"', "Line\nJINX"],
    'tab escape' => ['"Tab\\t$name"', "Tab\tJINX"],
    'concat interpolation' => ['"Hello $name" . " {$row[\'name\']}"', 'Hello JINX Oracle'],
    'strlen interpolation' => ['strlen("Hello $name")', 10],
];

foreach ($cases as $label => [$expr, $expected]) {
    same(eval_oracle_expr($expr, $locals), $expected, $label);
}

throws(static fn() => eval_oracle_expr('"Missing $missing"', $locals), 'missing interpolated local');
throws(static fn() => eval_oracle_expr('"Missing {$row[\'missing\']}"', $locals), 'missing interpolated array dimension');
throws(static fn() => eval_oracle_expr('"Bad {$name[\'x\']}"', $locals), 'non-array interpolated dimension');

echo "PASS: Oracle string interpolation facets resolve every tested PHP form" . PHP_EOL;
