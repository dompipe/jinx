<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function decodeTypedArg(string $arg): mixed
{
    if (strlen($arg) < 2 || $arg[1] !== ':') {
        fail("invalid typed argument {$arg}");
    }

    $tag = $arg[0];
    $value = substr($arg, 2);

    return match ($tag) {
        's' => $value,
        'i' => (int) $value,
        'f' => (float) $value,
        'b' => $value === '1' || $value === 'true',
        'n' => null,
        default => fail("unsupported typed argument {$arg}"),
    };
}

function encodePhpValue(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }

    if (is_bool($value)) {
        return 'bool:' . ($value ? 'true' : 'false');
    }

    if (is_int($value)) {
        return 'int:' . $value;
    }

    if (is_float($value)) {
        return 'float:' . sprintf('%.12g', $value);
    }

    if (is_string($value)) {
        return 'string:' . $value;
    }

    fail('unsupported PHP return type ' . get_debug_type($value));
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

$cases = [
    ['strtolower', ['s:JiNx']],
    ['strtoupper', ['s:JiNx']],
    ['lcfirst', ['s:JINX']],
    ['ucfirst', ['s:jinx']],
    ['strrev', ['s:oracle']],
    ['trim', ["s:\t jinx  "]],
    ['ltrim', ["s:\t jinx  x"]],
    ['rtrim', ["s:x  jinx \t"]],
    ['chop', ["s:x  jinx \t"]],
    ['chr', ['i:65']],
    ['ord', ['s:A']],
    ['substr', ['s:oracle', 'i:1', 'i:3']],
    ['substr', ['s:oracle', 'i:-3', 'i:2']],
    ['strpos', ['s:oracle', 's:ac', 'i:0']],
    ['strpos', ['s:oracle', 's:ac', 'i:-5']],
    ['strpos', ['s:oracle', 's:zz', 'i:0']],
    ['stripos', ['s:Oracle', 's:AC', 'i:0']],
    ['stripos', ['s:xxOracle', 's:OR', 'i:-6']],
    ['strrpos', ['s:abcabcabc', 's:abc']],
    ['strrpos', ['s:abcabcabc', 's:abc', 'i:4']],
    ['strrpos', ['s:0123456789a123456789b123456789c', 's:7', 'i:-5']],
    ['strripos', ['s:aBcxxABCxxabC', 's:abc']],
    ['strripos', ['s:aBcxxABCxxabC', 's:abc', 'i:-4']],
    ['strstr', ['s:name@example.com', 's:@']],
    ['strstr', ['s:name@example.com', 's:@', 'b:true']],
    ['strstr', ['s:abc', 's:']],
    ['stristr', ['s:USER@EXAMPLE.com', 's:e']],
    ['stristr', ['s:USER@EXAMPLE.com', 's:e', 'b:true']],
    ['strchr', ['s:abc:def', 's::']],
    ['str_repeat', ['s:ab', 'i:3']],
    ['str_repeat', ['s:ab', 'i:3000']],
    ['bin2hex', ['s:Hello!']],
    ['hex2bin', ['s:48656c6c6f21']],
    ['str_rot13', ['s:PHP 4.3.0']],
    ['addslashes', ["s:O'Reilly \"x\" \\end"]],
    ['strcmp', ['s:abc', 's:abd']],
    ['strcmp', ['s:a', 's:z']],
    ['strcasecmp', ['s:AbC', 's:abc']],
    ['strcasecmp', ['s:B', 's:a']],
    ['strncmp', ['s:abcdef', 's:abcxyz', 'i:3']],
    ['strncasecmp', ['s:AbCd', 's:abcZ', 'i:3']],
    ['substr_count', ['s:banana', 's:na']],
    ['substr_count', ['s:aaaa', 's:aa']],
    ['substr_count', ['s:banana', 's:na', 'i:3']],
    ['substr_count', ['s:banana', 's:na', 'i:0', 'i:4']],
];

foreach ($cases as [$function, $args]) {
    $phpArgs = array_map('decodeTypedArg', $args);
    $expected = encodePhpValue($function(...$phpArgs));

    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($function);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $actual = rtrim(implode(PHP_EOL, $output), "\r\n");

    if ($code !== 0) {
        fail("oracle-call {$function} failed: {$actual}");
    }

    if ($actual !== $expected) {
        fail("oracle-call {$function} parity mismatch: PHP={$expected}, JINX={$actual}");
    }
}

echo 'PASS: native Oracle ASM string byte transforms, searches, comparisons, and counts match PHP for covered scalar cases' . PHP_EOL;
