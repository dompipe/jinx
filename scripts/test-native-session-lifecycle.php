<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function sessionLifecycleFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function sessionLifecycleRun(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function sessionLifecycleValue(mixed $value): string
{
    if (is_bool($value)) return 'bool:' . ($value ? 'true' : 'false');
    if (is_int($value)) return 'int:' . $value;
    if (is_string($value)) return 'string:' . $value;
    if ($value === null) return 'null';
    sessionLifecycleFail('unsupported PHP lifecycle value: ' . get_debug_type($value));
}

if (!is_file($jinx) || !is_executable($jinx)) {
    sessionLifecycleFail('repository-root native ./jinx missing or not executable');
}

foreach ([
    'session_start',
    'session_write_close',
    'session_commit',
    'session_abort',
    'session_status',
    'session_id',
] as $name) {
    if (!function_exists($name)) {
        sessionLifecycleFail("PHP {$name} is required");
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

$originalSavePath = session_save_path();
$originalUseCookies = ini_get('session.use_cookies');
$originalCacheLimiter = session_cache_limiter();
$originalName = session_name();
$originalId = session_id();

$sessionId = 'jinxlifecycle' . getmypid();
$savePath = sys_get_temp_dir();

session_save_path($savePath);
ini_set('session.use_cookies', '0');
session_cache_limiter('');
session_name('JINXLIFE');
$idPrevious = session_id($sessionId);

$statusInitial = session_status();
$start = @session_start();
$statusActive = session_status();
$idActive = session_id();
$writeClose = session_write_close();
$statusAfterWrite = session_status();

$restartAbort = @session_start();
$abort = session_abort();
$statusAfterAbort = session_status();

$restartCommit = @session_start();
$commit = session_commit();
$statusAfterCommit = session_status();

$restartActiveOps = @session_start();
$beforeRegenerate = session_id();
$regenerateId = session_regenerate_id();
$afterRegenerate = session_id();
$idChanged = $afterRegenerate !== $beforeRegenerate;
$unset = session_unset();
$reset = session_reset();
$encode = session_encode();
$decodeEmpty = session_decode('');
$destroy = session_destroy();
$statusAfterDestroy = session_status();
$finalWriteClose = session_write_close();
$statusFinal = session_status();

$expected = implode(PHP_EOL, [
    'status_initial=' . sessionLifecycleValue($statusInitial),
    'id_previous=' . sessionLifecycleValue($idPrevious),
    'start=' . sessionLifecycleValue($start),
    'status_active=' . sessionLifecycleValue($statusActive),
    'id_active=' . sessionLifecycleValue($idActive),
    'write_close=' . sessionLifecycleValue($writeClose),
    'status_after_write=' . sessionLifecycleValue($statusAfterWrite),
    'restart_abort=' . sessionLifecycleValue($restartAbort),
    'abort=' . sessionLifecycleValue($abort),
    'status_after_abort=' . sessionLifecycleValue($statusAfterAbort),
    'restart_commit=' . sessionLifecycleValue($restartCommit),
    'commit=' . sessionLifecycleValue($commit),
    'status_after_commit=' . sessionLifecycleValue($statusAfterCommit),
    'restart_active_ops=' . sessionLifecycleValue($restartActiveOps),
    'regenerate_id=' . sessionLifecycleValue($regenerateId),
    'id_changed=' . sessionLifecycleValue($idChanged),
    'unset=' . sessionLifecycleValue($unset),
    'reset=' . sessionLifecycleValue($reset),
    'encode=' . sessionLifecycleValue($encode),
    'decode_empty=' . sessionLifecycleValue($decodeEmpty),
    'destroy=' . sessionLifecycleValue($destroy),
    'status_after_destroy=' . sessionLifecycleValue($statusAfterDestroy),
    'final_write_close=' . sessionLifecycleValue($finalWriteClose),
    'status_final=' . sessionLifecycleValue($statusFinal),
]);

$actual = sessionLifecycleRun(
    escapeshellarg($jinx)
        . ' oracle-session-lifecycle-smoke '
        . escapeshellarg($sessionId),
    $code
);

@unlink($savePath . DIRECTORY_SEPARATOR . 'sess_' . $sessionId);
session_id($originalId);
session_name($originalName);
session_cache_limiter($originalCacheLimiter);
session_save_path($originalSavePath);
if ($originalUseCookies !== false) {
    ini_set('session.use_cookies', (string)$originalUseCookies);
}

if ($code !== 0 || $actual !== $expected) {
    sessionLifecycleFail(
        "native session lifecycle parity mismatch\n" .
        "PHP/expected:\n{$expected}\n" .
        "JINX:\n{$actual}"
    );
}

echo "PASS: native session lifecycle start, close, abort, and commit match PHP", PHP_EOL;
