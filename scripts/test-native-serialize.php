<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failSerialize(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runSerialize(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function oracleSerialize(
    string $jinx,
    string $name,
    array $args,
    string $mode = 'oracle-call',
    ?int &$code = null
): string {
    $cmd = escapeshellarg($jinx) . ' ' . $mode . ' ' . escapeshellarg($name);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    return runSerialize($cmd, $code);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failSerialize('repository-root native ./jinx missing');
}

$serializeCases = [
    [null, 'null'],
    [true, 'b:true'],
    [-42, 'i:-42'],
    [1.5, 'f:1.5'],
    ['jinx', 's:jinx'],
    [[10, 20, 'name' => 30, 'keep' => 40], 'za:sample'],
    [['inner' => [1, 2], 'name' => 'jinx'], 'za:nested'],
];

foreach ($serializeCases as [$phpValue, $typed]) {
    $expected = 'string:' . serialize($phpValue);
    $actual = oracleSerialize($jinx, 'serialize', [$typed], 'oracle-call', $code);
    if ($code !== 0 || $actual !== $expected) {
        failSerialize(
            "serialize parity mismatch\n"
            . "typed={$typed}\nPHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

$binary = "a\0b";
$binaryExpected = 'hex:' . bin2hex(serialize($binary));
$binaryActual = oracleSerialize(
    $jinx,
    'serialize',
    ['h:' . bin2hex($binary)],
    'oracle-call-hex',
    $code
);
if ($code !== 0 || $binaryActual !== $binaryExpected) {
    failSerialize(
        "binary serialize parity mismatch\n"
        . "PHP/expected: {$binaryExpected}\nJINX: {$binaryActual}"
    );
}

$scalarUnserializeCases = [
    [null, 'null'],
    [true, 'bool:true'],
    [-42, 'int:-42'],
    [1.5, 'float:1.5'],
    ['jinx', 'string:jinx'],
];

foreach ($scalarUnserializeCases as [$phpValue, $expected]) {
    $payload = serialize($phpValue);
    $actual = oracleSerialize(
        $jinx,
        'unserialize',
        ['s:' . $payload],
        'oracle-call',
        $code
    );
    if ($code !== 0 || $actual !== $expected) {
        failSerialize(
            "unserialize scalar parity mismatch\n"
            . "payload={$payload}\nPHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

$arrayUnserializeCases = [
    [10, 20, 'name' => 30, 'keep' => 40],
    ['inner' => [1, 2], 'name' => 'jinx'],
    ['x' => ['y' => ['z' => 7]], 4 => 'tail'],
];

foreach ($arrayUnserializeCases as $phpValue) {
    $payload = serialize($phpValue);
    $expected = 'string:' . json_encode($phpValue, JSON_THROW_ON_ERROR);
    $actual = oracleSerialize(
        $jinx,
        'unserialize',
        ['s:' . $payload],
        'oracle-call-json',
        $code
    );
    if ($code !== 0 || $actual !== $expected) {
        failSerialize(
            "unserialize array JSON parity mismatch\n"
            . "payload={$payload}\nPHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

$binaryPayload = serialize($binary);
$binaryUnserialized = oracleSerialize(
    $jinx,
    'unserialize',
    ['h:' . bin2hex($binaryPayload)],
    'oracle-call-hex',
    $code
);
$binaryUnserializedExpected = 'hex:' . bin2hex($binary);
if ($code !== 0 || $binaryUnserialized !== $binaryUnserializedExpected) {
    failSerialize(
        "binary unserialize parity mismatch\n"
        . "PHP/expected: {$binaryUnserializedExpected}\nJINX: {$binaryUnserialized}"
    );
}

$malformed = 'a:1:{i:0;s:3:"xx";}';
$malformedActual = oracleSerialize(
    $jinx,
    'unserialize',
    ['s:' . $malformed],
    'oracle-call',
    $code
);
if ($code !== 0 || $malformedActual !== 'bool:false') {
    failSerialize(
        "malformed unserialize contract mismatch\n"
        . "Expected: bool:false\nJINX: {$malformedActual}"
    );
}

$objectPayload = serialize((object)['a' => 1]);
$objectActual = oracleSerialize(
    $jinx,
    'unserialize',
    ['s:' . $objectPayload],
    'oracle-call',
    $objectCode
);
if ($objectCode === 0 || !str_contains($objectActual, 'null/fault: unserialize')) {
    failSerialize(
        "object unserialize must remain an explicit native fault\n"
        . "JINX: {$objectActual}"
    );
}

$objectSerialize = oracleSerialize(
    $jinx,
    'serialize',
    ['obj:stdClass'],
    'oracle-call',
    $objectSerializeCode
);
if ($objectSerializeCode === 0 || !str_contains($objectSerialize, 'null/fault: serialize')) {
    failSerialize(
        "object serialize must remain an explicit native fault\n"
        . "JINX: {$objectSerialize}"
    );
}

echo "PASS: native serialize/unserialize scalar, binary-string, mixed-array, and nested-array core matches PHP" . PHP_EOL;
