<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebNativeFunctions.php';

use jinx\web\WebNativeFunctions;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function assertThrows(string $label, callable $fn): void
{
    try {
        $fn();
    } catch (RuntimeException) {
        return;
    }

    fail("{$label} should have thrown RuntimeException");
}

$cases = [
    'boolval' => [[1], true],
    'floatval' => [['3.5'], 3.5],
    'intval' => [['42'], 42],
    'strval' => [[42], '42'],

    'is_array' => [[[1]], true],
    'is_bool' => [[true], true],
    'is_float' => [[1.2], true],
    'is_int' => [[7], true],
    'is_numeric' => [['12'], true],
    'is_string' => [['x'], true],
    'is_null' => [[null], true],

    'strlen' => [['Anthony'], 7],
    'trim' => [['  Anthony  '], 'Anthony'],
    'ltrim' => [['  Anthony'], 'Anthony'],
    'rtrim' => [['Anthony  '], 'Anthony'],
    'strtolower' => [['ANTHONY'], 'anthony'],
    'strtoupper' => [['anthony'], 'ANTHONY'],
    'ucfirst' => [['anthony'], 'Anthony'],
    'lcfirst' => [['Anthony'], 'anthony'],
    'ucwords' => [['hello world'], 'Hello World'],

    'substr' => [['Anthony', 0, 3], 'Ant'],
    'strpos' => [['Anthony', 't'], 2],
    'strrpos' => [['banana', 'a'], 5],
    'str_contains' => [['Anthony', 'tho'], true],
    'str_starts_with' => [['Anthony', 'Ant'], true],
    'str_ends_with' => [['Anthony', 'ony'], true],
    'str_replace' => [['x', 'y', 'xoxo'], 'yoyo'],

    'explode' => [[',', 'a,b'], ['a', 'b']],
    'implode' => [[',', ['a', 'b']], 'a,b'],
    'htmlspecialchars' => [['<b>'], '&lt;b&gt;'],
    'htmlentities' => [['<b>'], '&lt;b&gt;'],
    'nl2br' => [["a\nb", false], "a<br>\nb"],

    'preg_match' => [['/Ant/', 'Anthony'], 1],
    'preg_replace' => [['/Ant/', 'X', 'Anthony'], 'Xhony'],

    'json_encode' => [[[ 'ok' => true ]], '{"ok":true}'],
    'json_decode' => [['{"ok":true}', true], ['ok' => true]],

    'count' => [[[1, 2, 3]], 3],
    'in_array' => [['b', ['a', 'b'], true], true],
    'array_key_exists' => [['name', ['name' => 'Anthony']], true],
    'array_values' => [[['a' => 1, 'b' => 2]], [1, 2]],
    'array_keys' => [[['a' => 1, 'b' => 2]], ['a', 'b']],
    'array_reverse' => [[[1, 2, 3]], [3, 2, 1]],
    'array_unique' => [[['a', 'a', 'b']], ['a', 'b']],

    'abs' => [[-3], 3],
    'ceil' => [[1.2], 2.0],
    'floor' => [[1.8], 1.0],
    'max' => [[1, 5, 2], 5],
    'min' => [[1, 5, 2], 1],
    'round' => [[1.55, 1], 1.6],
    'sqrt' => [[9], 3.0],
    'pow' => [[2, 3], 8],
];

$allowed = WebNativeFunctions::allowedNames();
sort($allowed);

if (count($allowed) !== 3527) {
    fail('expected 3527 generated worker wrappers, got ' . count($allowed));
}

foreach (['strlen', 'json_encode', 'acos', 'AppendIterator::append'] as $required) {
    if (!in_array($required, $allowed, true)) {
        fail("missing generated worker wrapper: {$required}");
    }
}

foreach ($cases as $name => [$args, $expected]) {
    $actual = WebNativeFunctions::call($name, $args);

    if ($actual !== $expected) {
        fail(
            "wrapper {$name} failed\nexpected: "
            . var_export($expected, true)
            . "\nactual:   "
            . var_export($actual, true)
        );
    }
}

$acos = WebNativeFunctions::call('acos', [1.0]);
if ($acos !== 0.0) {
    fail('generated direct-call wrapper acos failed');
}

assertThrows(
    'method metadata wrapper',
    static fn () => WebNativeFunctions::call('AppendIterator::append', [new ArrayIterator([])])
);

assertThrows(
    'by-reference metadata wrapper',
    static fn () => WebNativeFunctions::call('array_push', [[], 'x'])
);

assertThrows(
    'worker-unsafe generated wrapper',
    static fn () => WebNativeFunctions::call('file_get_contents', [__FILE__])
);

echo "PASS: 3527 worker-style native wrappers are registered; callable wrappers execute and unsafe wrappers fail closed\n";
