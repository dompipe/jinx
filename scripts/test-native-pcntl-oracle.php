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
    'pcntl_alarm',
    'pcntl_async_signals',
    'pcntl_getcpuaffinity',
    'pcntl_setcpuaffinity',
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

$phpAlarmSet = pcntl_alarm(3);
$phpAlarmCancel = pcntl_alarm(0);
if ($phpAlarmSet !== 0 || $phpAlarmCancel < 1 || $phpAlarmCancel > 3) {
    pcntlFail(
        "PHP pcntl_alarm fixture failed\n" .
        "set={$phpAlarmSet} cancel={$phpAlarmCancel}"
    );
}

$jinxAlarm = pcntlRun(
    escapeshellarg($jinx) . ' oracle-pcntl-alarm-smoke',
    $code
);
if ($code !== 0 ||
    !preg_match('/^set=int:0\ncancel=int:([1-3])$/', $jinxAlarm, $alarmMatch)) {
    pcntlFail(
        "pcntl_alarm scheduling contract mismatch\nJINX:\n{$jinxAlarm}"
    );
}

$phpAsyncInitial = pcntl_async_signals();
$phpAsyncEnablePrevious = pcntl_async_signals(true);
$phpAsyncEnabled = pcntl_async_signals();
$phpAsyncDisablePrevious = pcntl_async_signals(false);
$phpAsyncFinal = pcntl_async_signals();
pcntl_async_signals($phpAsyncInitial);

if ($phpAsyncInitial !== false ||
    $phpAsyncEnablePrevious !== false ||
    $phpAsyncEnabled !== true ||
    $phpAsyncDisablePrevious !== true ||
    $phpAsyncFinal !== false) {
    pcntlFail(
        "PHP pcntl_async_signals state fixture failed\n" .
        "initial=" . var_export($phpAsyncInitial, true) .
        " enable_previous=" . var_export($phpAsyncEnablePrevious, true) .
        " enabled=" . var_export($phpAsyncEnabled, true) .
        " disable_previous=" . var_export($phpAsyncDisablePrevious, true) .
        " final=" . var_export($phpAsyncFinal, true)
    );
}

$jinxAsync = pcntlRun(
    escapeshellarg($jinx) . ' oracle-pcntl-async-smoke',
    $code
);
$expectedAsync =
    "initial=bool:false\n" .
    "enable_previous=bool:false\n" .
    "enabled=bool:true\n" .
    "disable_previous=bool:true\n" .
    "final=bool:false";
if ($code !== 0 || $jinxAsync !== $expectedAsync) {
    pcntlFail(
        "pcntl_async_signals state parity mismatch\n" .
        "PHP/expected:\n{$expectedAsync}\nJINX:\n{$jinxAsync}"
    );
}

$phpAffinity = pcntl_getcpuaffinity(null);
if (!is_array($phpAffinity) || $phpAffinity === []) {
    pcntlFail('PHP pcntl_getcpuaffinity returned no CPUs');
}

$jinxAffinity = pcntlRun(
    escapeshellarg($jinx)
        . ' oracle-call-serialize-hex pcntl_getcpuaffinity',
    $code
);
$expectedAffinity = 'hex:' . bin2hex(serialize($phpAffinity));
if ($code !== 0 || $jinxAffinity !== $expectedAffinity) {
    pcntlFail(
        "pcntl_getcpuaffinity parity mismatch\n" .
        "PHP/expected: {$expectedAffinity}\nJINX: {$jinxAffinity}"
    );
}

if (pcntl_setcpuaffinity(null, $phpAffinity) !== true) {
    pcntlFail('PHP pcntl_setcpuaffinity no-op failed');
}

$jinxAffinitySet = pcntlRun(
    escapeshellarg($jinx) . ' oracle-pcntl-affinity-smoke',
    $code
);
$expectedAffinitySet =
    'cpus=' . count($phpAffinity) . PHP_EOL . 'set=bool:true';
if ($code !== 0 || $jinxAffinitySet !== $expectedAffinitySet) {
    pcntlFail(
        "pcntl_setcpuaffinity no-op parity mismatch\n" .
        "PHP/expected:\n{$expectedAffinitySet}\n" .
        "JINX:\n{$jinxAffinitySet}"
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

echo "PASS: native PCNTL alarm, async-signals, affinity, priority, CPU, and error helpers match PHP\n";
