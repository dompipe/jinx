<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failFilterInput(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runFilterInput(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function encodePhpFilterValue(mixed $value): string
{
    if ($value === null) return 'null';
    if (is_bool($value)) return 'bool:' . ($value ? 'true' : 'false');
    if (is_int($value)) return 'int:' . $value;
    if (is_string($value)) return 'string:' . $value;
    if (is_array($value)) return 'zend-array:' . count($value);
    failFilterInput('unsupported PHP comparison type: ' . get_debug_type($value));
}

function jinxFilterCall(string $jinx, string $name, array $typedArgs, ?int &$code = null): string
{
    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($name);
    foreach ($typedArgs as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    return runFilterInput($command, $code);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failFilterInput('repository-root native ./jinx missing or not executable');
}

$missing = '__jinx_missing_filter_input__';
$sources = [
    'INPUT_GET' => INPUT_GET,
    'INPUT_POST' => INPUT_POST,
    'INPUT_COOKIE' => INPUT_COOKIE,
];

foreach ($sources as $label => $type) {
    $cases = [
        [
            'filter_has_var',
            ['i:' . $type, 's:' . $missing],
            filter_has_var($type, $missing),
        ],
        [
            'filter_input',
            ['i:' . $type, 's:' . $missing],
            filter_input($type, $missing),
        ],
        [
            'filter_input_array',
            ['i:' . $type],
            filter_input_array($type),
        ],
    ];

    foreach ($cases as [$name, $typedArgs, $phpValue]) {
        $expected = encodePhpFilterValue($phpValue);
        $actual = jinxFilterCall($jinx, $name, $typedArgs, $code);
        if ($code !== 0 || $actual !== $expected) {
            failFilterInput(
                "{$name} {$label} parity mismatch\n" .
                "PHP/expected: {$expected}\n" .
                "JINX: {$actual}"
            );
        }
    }
}

echo "PASS: native filter input empty-context semantics match PHP CLI", PHP_EOL;
