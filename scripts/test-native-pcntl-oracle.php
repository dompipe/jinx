<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function pcntlFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function pcntlRun(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function pcntlJinx(string $jinx, string $name, array $args = [], ?int &$code = null): string
{
    $cmd = escapeshellarg($jinx)
        . ' oracle-call '
        . escapeshellarg($name);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    return pcntlRun($cmd, $code);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    pcntlFail('repository-root native ./jinx missing or not executable');
}

foreach ([
    'pcntl_getpriority',
    'pcntl_setpriority',
    'pcntl_getcpu',
    'pcntl_get_last_error',
    'pcntl_errno',
    'pcntl_strerror',
] as $name) {
    if (!function_exists($name)) {
        pcntlFail("PHP {$name} is required for native parity");
    }
}

$phpPriority = pcntl_getpriority(null, PRIO_PROCESS);
if ($phpPriority === false) {
    pcntlFail('PHP pcntl_getpriority failed for current process');
}

$jinxPriority = pcntlJinx(
    $jinx,
    'pcntl_getpriority',
    ['null', 'i:' . PRIO_PROCESS],
    $code
);
if ($code !== 0 || $jinxPriority !== 'int:' . $phpPriority) {
    pcntlFail(
        "pcntl_getpriority parity mismatch\n" .
        "PHP/expected: int:{$phpPriority}\nJINX: {$jinxPriority}"
    );
}

$phpSet = pcntl_setpriority($phpPriority, null, PRIO_PROCESS);
if ($phpSet !== true) {
    pcntlFail('PHP pcntl_setpriority no-op failed');
}

$jinxSet = pcntlJinx(
    $jinx,
    'pcntl_setpriority',
    ['i:' . $phpPriority, 'null', 'i:' . PRIO_PROCESS],
    $code
);
if ($code !== 0 || $jinxSet !== 'bool:true') {
    pcntlFail(
        "pcntl_setpriority no-op parity mismatch\nJINX: {$jinxSet}"
    );
}

$phpCpu = pcntl_getcpu();
$jinxCpu = pcntlJinx($jinx, 'pcntl_getcpu', [], $code);
if (!is_int($phpCpu) || $phpCpu < 0 ||
    $code !== 0 ||
    !preg_match('/^int:([0-9]+)$/', $jinxCpu, $cpuMatch) ||
    (int)$cpuMatch[1] < 0) {
    pcntlFail(
        "pcntl_getcpu return-contract mismatch\n" .
        "PHP: " . var_export($phpCpu, true) . "\nJINX: {$jinxCpu}"
    );
}

$phpInvalid = @pcntl_getpriority(2147483647, PRIO_PROCESS);
$phpLast = pcntl_get_last_error();
$phpErrno = pcntl_errno();
if ($phpInvalid !== false || $phpLast <= 0 || $phpErrno !== $phpLast) {
    pcntlFail(
        "PHP PCNTL error-state fixture failed\n" .
        "invalid=" . var_export($phpInvalid, true) .
        " last={$phpLast} errno={$phpErrno}"
    );
}

$state = pcntlRun(
    escapeshellarg($jinx) . ' oracle-pcntl-error-smoke',
    $code
);
$expectedState = "priority=bool:false\nlast=int:{$phpLast}\nerrno=int:{$phpErrno}";
if ($code !== 0 || $state !== $expectedState) {
    pcntlFail(
        "PCNTL error-state parity mismatch\n" .
        "PHP/expected:\n{$expectedState}\nJINX:\n{$state}"
    );
}

$jinxErrorText = pcntlJinx(
    $jinx,
    'pcntl_strerror',
    ['i:' . $phpLast],
    $code
);
$phpErrorText = pcntl_strerror($phpLast);
if ($code !== 0 || $jinxErrorText !== 'string:' . $phpErrorText) {
    pcntlFail(
        "pcntl_strerror parity mismatch\n" .
        "PHP/expected: string:{$phpErrorText}\nJINX: {$jinxErrorText}"
    );
}

echo "PASS: native PCNTL priority, CPU, and error helpers match PHP\n";
