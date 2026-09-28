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

checkMethod(
    $jinx,
    'DateTime::getTimestamp',
    'dt:2024-01-02 03:04:05',
    [],
    'int:' . $phpDate->getTimestamp()
);
checkMethod(
    $jinx,
    'DateTimeImmutable::getTimestamp',
    'dti:2024-01-02 03:04:05',
    [],
    'int:' . $phpImmutable->getTimestamp()
);
checkMethod(
    $jinx,
    'DateTime::getOffset',
    'dt:2024-01-02 03:04:05',
    [],
    'int:' . $phpDate->getOffset()
);
checkMethod(
    $jinx,
    'DateTimeImmutable::getOffset',
    'dti:2024-01-02 03:04:05',
    [],
    'int:' . $phpImmutable->getOffset()
);

$phpZone = new DateTimeZone('UTC');
checkMethod(
    $jinx,
    'DateTimeZone::getName',
    'tz:UTC',
    [],
    'string:' . $phpZone->getName()
);
checkMethod(
    $jinx,
    'DateTimeZone::getOffset',
    'tz:UTC',
    ['dt:2024-01-02 03:04:05'],
    'int:' . $phpZone->getOffset($phpDate)
);

$phpInterval = new DateInterval('P1Y2M3DT4H5M6S');
checkMethod(
    $jinx,
    'DateInterval::format',
    'di:P1Y2M3DT4H5M6S',
    ['s:%Y-%M-%D %H:%I:%S'],
    'string:' . $phpInterval->format('%Y-%M-%D %H:%I:%S')
);

echo "PASS: native Oracle method receiver preserves DateTime scalar parity per method\n";
