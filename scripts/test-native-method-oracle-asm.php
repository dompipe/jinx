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

$throwableClasses = [
    ArgumentCountError::class,
    ArithmeticError::class,
    AssertionError::class,
    BadFunctionCallException::class,
    BadMethodCallException::class,
    ClosedGeneratorException::class,
    CompileError::class,
    DateError::class,
    DateException::class,
    DateInvalidOperationException::class,
    DateInvalidTimeZoneException::class,
    DateMalformedIntervalStringException::class,
    DateMalformedPeriodStringException::class,
    DateMalformedStringException::class,
    DateObjectError::class,
    DateRangeError::class,
    DivisionByZeroError::class,
    DomainException::class,
    Error::class,
    ErrorException::class,
    Exception::class,
    InvalidArgumentException::class,
    JsonException::class,
    LengthException::class,
    LogicException::class,
    OutOfBoundsException::class,
    OutOfRangeException::class,
    OverflowException::class,
    ParseError::class,
    PDOException::class,
    Random\BrokenRandomEngineError::class,
    Random\RandomError::class,
    Random\RandomException::class,
    RangeException::class,
    ReflectionException::class,
    RuntimeException::class,
    TypeError::class,
    UnderflowException::class,
    UnexpectedValueException::class,
    UnhandledMatchError::class,
    ValueError::class,
];

$throwableMethods = [
    'getMessage',
    'getCode',
    'getPrevious',
    'getTrace',
    'getTraceAsString',
    'getFile',
    'getLine',
    '__toString',
];

foreach ($throwableClasses as $class) {
    if (!class_exists($class)) {
        failMethod("required Throwable class missing from PHP runtime: {$class}");
    }
    if (!is_a($class, Throwable::class, true)) {
        failMethod("expected Throwable class is not Throwable: {$class}");
    }

    $phpThrowable = new $class('jinx-message', 73);
    (new ReflectionProperty($class, 'file'))->setValue(
        $phpThrowable,
        'jinx-fixture.php'
    );
    (new ReflectionProperty($class, 'line'))->setValue(
        $phpThrowable,
        123
    );
    $fixture = 'ex:' . $class;

    foreach ($throwableMethods as $method) {
        if (!method_exists($phpThrowable, $method)) {
            failMethod("required Throwable method missing: {$class}::{$method}");
        }

        $expected = match ($method) {
            'getMessage' => 'string:' . $phpThrowable->getMessage(),
            'getCode' => 'int:' . $phpThrowable->getCode(),
            'getPrevious' => $phpThrowable->getPrevious() === null
                ? 'null'
                : failMethod("unexpected previous throwable for {$class}"),
            'getTrace' => 'zend-array:' . count($phpThrowable->getTrace()),
            'getTraceAsString' => 'string:' . $phpThrowable->getTraceAsString(),
            'getFile' => 'string:' . $phpThrowable->getFile(),
            'getLine' => 'int:' . $phpThrowable->getLine(),
            '__toString' => 'string:' . $phpThrowable->__toString(),
        };

        checkMethod(
            $jinx,
            $class . '::' . $method,
            $fixture,
            [],
            $expected
        );
    }
}

echo "PASS: native Oracle method receiver preserves DateTime and 328 Throwable route probes\n";
