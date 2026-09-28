<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failMethod(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runMethod(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function checkMethod(
    string $jinx,
    string $method,
    string $receiver,
    array $args,
    string $expected
): void {
    $cmd = escapeshellarg($jinx)
        . ' oracle-method-call '
        . escapeshellarg($method)
        . ' '
        . escapeshellarg($receiver);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    $actual = runMethod($cmd, $code);
    if ($code !== 0 || $actual !== $expected) {
        failMethod(
            "{$method} parity mismatch\n"
            . "PHP: {$expected}\n"
            . "JINX: {$actual}"
        );
    }
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failMethod('repository-root native ./jinx missing or not executable');
}

$phpDate = new DateTime('2024-01-02 03:04:05');
checkMethod(
    $jinx,
    'DateTime::format',
    'dt:2024-01-02 03:04:05',
    ['s:Y-m-d'],
    'string:' . $phpDate->format('Y-m-d')
);

$phpImmutable = new DateTimeImmutable('2024-01-02 03:04:05');
checkMethod(
    $jinx,
    'DateTimeImmutable::format',
    'dti:2024-01-02 03:04:05',
    ['s:H:i:s'],
    'string:' . $phpImmutable->format('H:i:s')
);

echo "PASS: native Oracle method receiver preserves DateTime format parity per method\n";
