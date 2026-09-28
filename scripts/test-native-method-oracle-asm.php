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
    FFI\Exception::class,
    FFI\ParserException::class,
    FiberError::class,
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
    SodiumException::class,
    PharException::class,
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
    '__wakeup',
];

foreach ($throwableClasses as $class) {
    if (!class_exists($class)) {
        failMethod("required Throwable class missing from PHP runtime: {$class}");
    }
    if (!is_a($class, Throwable::class, true)) {
        failMethod("expected Throwable class is not Throwable: {$class}");
    }

    if ($class === FiberError::class) {
        $fiber = new Fiber(static function (): void {});
        $fiber->start();
        try {
            $fiber->start();
            failMethod('expected engine-thrown FiberError was not produced');
        } catch (FiberError $error) {
            $phpThrowable = $error;
        }

        foreach ([
            'message' => 'jinx-message',
            'code' => 73,
            'file' => 'jinx-fixture.php',
            'line' => 123,
        ] as $property => $value) {
            (new ReflectionProperty(Error::class, $property))->setValue(
                $phpThrowable,
                $value
            );
        }
        (new ReflectionProperty(Error::class, 'trace'))->setValue(
            $phpThrowable,
            []
        );
        (new ReflectionProperty(Error::class, 'previous'))->setValue(
            $phpThrowable,
            null
        );
    } else {
        $phpThrowable = new $class('jinx-message', 73);
        (new ReflectionProperty($class, 'file'))->setValue(
            $phpThrowable,
            'jinx-fixture.php'
        );
        (new ReflectionProperty($class, 'line'))->setValue(
            $phpThrowable,
            123
        );
    }
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
            '__wakeup' => $phpThrowable->__wakeup() === null
                ? 'null'
                : failMethod("unexpected wakeup return for {$class}"),
        };

        checkMethod(
            $jinx,
            $class . '::' . $method,
            $fixture,
            [],
            $expected
        );
    }

    if ($class !== FiberError::class) {
        $phpConstructed = new $class('jinx-message', 73);
        $phpConstructReturn = $phpConstructed->__construct(
            'reset-message',
            91
        );
        $constructExpected = implode(PHP_EOL, [
            'return=' . ($phpConstructReturn === null ? 'null' : 'non-null'),
            'message=string:' . $phpConstructed->getMessage(),
            'code=int:' . $phpConstructed->getCode(),
        ]);
        $constructActual = runMethod(
            escapeshellarg($jinx)
            . ' oracle-throwable-construct-smoke '
            . escapeshellarg($class),
            $constructCode
        );
        if ($constructCode !== 0 ||
            $constructActual !== $constructExpected) {
            failMethod(
                "{$class}::__construct state parity mismatch\n"
                . "PHP:\n{$constructExpected}\n"
                . "JINX:\n{$constructActual}"
            );
        }
    }
}

/* Prove the Throwable interface routes against a concrete Exception receiver. */
$phpInterfaceThrowable = new Exception('jinx-message', 73);
(new ReflectionProperty(Exception::class, 'file'))->setValue(
    $phpInterfaceThrowable,
    'jinx-fixture.php'
);
(new ReflectionProperty(Exception::class, 'line'))->setValue(
    $phpInterfaceThrowable,
    123
);
$throwableInterfaceMethods = [
    'getMessage',
    'getCode',
    'getPrevious',
    'getTrace',
    'getTraceAsString',
    'getFile',
    'getLine',
    '__toString',
];
foreach ($throwableInterfaceMethods as $method) {
    $expected = match ($method) {
        'getMessage' => 'string:' . $phpInterfaceThrowable->getMessage(),
        'getCode' => 'int:' . $phpInterfaceThrowable->getCode(),
        'getPrevious' => 'null',
        'getTrace' => 'zend-array:' . count($phpInterfaceThrowable->getTrace()),
        'getTraceAsString' => 'string:' . $phpInterfaceThrowable->getTraceAsString(),
        'getFile' => 'string:' . $phpInterfaceThrowable->getFile(),
        'getLine' => 'int:' . $phpInterfaceThrowable->getLine(),
        '__toString' => 'string:' . $phpInterfaceThrowable->__toString(),
    };
    checkMethod(
        $jinx,
        Throwable::class . '::' . $method,
        'ex:' . Exception::class,
        [],
        $expected
    );
}

$phpErrorException = new ErrorException('jinx-message', 73);
checkMethod(
    $jinx,
    ErrorException::class . '::getSeverity',
    'ex:' . ErrorException::class,
    [],
    'int:' . $phpErrorException->getSeverity()
);

/*
 * Stateful DateTime method parity.  These probes verify the returned object's
 * value, the receiver's value after the call, and mutable-vs-immutable object
 * identity.  The native smoke uses the same deterministic fixtures/arguments.
 */
$dateTimeStateMethods = [
    'add',
    'sub',
    'modify',
    'setDate',
    'setISODate',
    'setTime',
    'setTimestamp',
    'setTimezone',
    'diff',
    'getTimezone',
];

foreach ([DateTime::class, DateTimeImmutable::class] as $dateClass) {
    foreach ($dateTimeStateMethods as $method) {
        $phpObject = new $dateClass('2024-01-02 03:04:05');

        $phpResult = match ($method) {
            'add' => $phpObject->add(new DateInterval('P1D')),
            'sub' => $phpObject->sub(new DateInterval('P1D')),
            'modify' => $phpObject->modify('+2 days'),
            'setDate' => $phpObject->setDate(2025, 6, 7),
            'setISODate' => $phpObject->setISODate(2025, 10, 3),
            'setTime' => $phpObject->setTime(11, 22, 33),
            'setTimestamp' => $phpObject->setTimestamp(1704067200),
            'setTimezone' => $phpObject->setTimezone(
                new DateTimeZone('America/New_York')
            ),
            'diff' => $phpObject->diff(
                new DateTime('2024-01-05 05:06:07')
            ),
            'getTimezone' => $phpObject->getTimezone(),
        };

        if ($method === 'getTimezone') {
            $expected = 'result=string:' . $phpResult->getName();
        } elseif ($method === 'diff') {
            $expected = 'result=string:'
                . $phpResult->format('%R%a %H:%I:%S');
        } else {
            $expected = implode(PHP_EOL, [
                'result=string:' . $phpResult->format('Y-m-d H:i:s'),
                'original=string:' . $phpObject->format('Y-m-d H:i:s'),
                'same=bool:' . (
                    $phpResult === $phpObject ? 'true' : 'false'
                ),
            ]);
        }

        $actual = runMethod(
            escapeshellarg($jinx)
            . ' oracle-datetime-method-smoke '
            . escapeshellarg($dateClass)
            . ' '
            . escapeshellarg($method),
            $dateStateCode
        );
        if ($dateStateCode !== 0 || $actual !== $expected) {
            failMethod(
                "{$dateClass}::{$method} state parity mismatch\n"
                . "PHP:\n{$expected}\n"
                . "JINX:\n{$actual}"
            );
        }
    }
}


/* Ten replacement routes available on the CI PHP runtime. */
$dateTimeExtraExpected = [];

$dateTimeExtraExpected['DateTime::__construct'] =
    'result=string:' . (new DateTime('2025-06-07 11:22:33'))
        ->format('Y-m-d H:i:s');

$dateTimeExtraExpected['DateTimeImmutable::__construct'] =
    'result=string:' . (new DateTimeImmutable('2025-06-07 11:22:33'))
        ->format('Y-m-d H:i:s');

$dateTimeExtraExpected['DateTimeZone::__construct'] =
    'result=string:' . (new DateTimeZone('America/New_York'))->getName();

$dateTimeExtraExpected['DateInterval::__construct'] =
    'result=string:' . (new DateInterval('P2Y3M4DT5H6M7S'))
        ->format('%Y-%M-%D %H:%I:%S');

$dateTimeExtraExpected['DateTime::createFromFormat'] =
    'result=string:' . DateTime::createFromFormat(
        '!Y-m-d H:i:s',
        '2025-06-07 11:22:33'
    )->format('Y-m-d H:i:s');

$dateTimeExtraExpected['DateTimeImmutable::createFromFormat'] =
    'result=string:' . DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        '2025-06-07 11:22:33'
    )->format('Y-m-d H:i:s');

/* Successful parses make getLastErrors() return false. */
DateTime::createFromFormat('!Y-m-d', '2025-06-07');
$dateTimeExtraExpected['DateTime::getLastErrors'] =
    'result=' . (DateTime::getLastErrors() === false
        ? 'bool:false'
        : 'non-false');

DateTimeImmutable::createFromFormat('!Y-m-d', '2025-06-07');
$dateTimeExtraExpected['DateTimeImmutable::getLastErrors'] =
    'result=' . (DateTimeImmutable::getLastErrors() === false
        ? 'bool:false'
        : 'non-false');

$dateTimeExtraExpected['DateInterval::createFromDateString'] =
    'result=string:' . DateInterval::createFromDateString('2 days')
        ->format('%Y-%M-%D %H:%I:%S');

$dateInterfaceSource = new DateTimeImmutable('2024-02-03 04:05:06');
$dateTimeExtraExpected['DateTime::createFromInterface'] =
    'result=string:' . DateTime::createFromInterface($dateInterfaceSource)
        ->format('Y-m-d H:i:s');

$mutableInterfaceSource = new DateTime('2024-02-03 04:05:06');
$dateTimeExtraExpected['DateTimeImmutable::createFromInterface'] =
    'result=string:'
    . DateTimeImmutable::createFromInterface($mutableInterfaceSource)
        ->format('Y-m-d H:i:s');

$dateTimeExtraExpected['DateTime::createFromImmutable'] =
    'result=string:' . DateTime::createFromImmutable($dateInterfaceSource)
        ->format('Y-m-d H:i:s');

foreach ($dateTimeExtraExpected as $route => $expected) {
    if (!method_exists(strtok($route, ':'), substr($route, strpos($route, '::') + 2))) {
        failMethod("required replacement method missing from PHP runtime: {$route}");
    }

    $actual = runMethod(
        escapeshellarg($jinx)
        . ' oracle-datetime-extra-smoke '
        . escapeshellarg($route),
        $extraCode
    );
    if ($extraCode !== 0 || $actual !== $expected) {
        failMethod(
            "{$route} replacement parity mismatch\n"
            . "PHP:\n{$expected}\n"
            . "JINX:\n{$actual}"
        );
    }
}

echo "PASS: native Oracle method receiver proves 500 newly added callable routes\n";
