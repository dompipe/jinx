<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failSem(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failSem('repository-root native ./jinx missing or not executable');
}

foreach (['sem_get', 'sem_acquire', 'sem_release', 'sem_remove'] as $function) {
    if (!function_exists($function)) {
        failSem("PHP runtime missing required {$function}() contract");
    }
}

$sem = @sem_get(0, 1, 0600, false);
if ($sem === false) {
    failSem('PHP sem_get fixture failed');
}

try {
    if (!sem_acquire($sem)) {
        failSem('PHP sem_acquire fixture failed');
    }
    if (sem_acquire($sem, true) !== false) {
        failSem('PHP nonblocking sem_acquire did not report contention');
    }
    if (!sem_release($sem)) {
        failSem('PHP sem_release fixture failed');
    }
    if (!sem_acquire($sem, true)) {
        failSem('PHP nonblocking sem_acquire did not reacquire released semaphore');
    }
    if (!sem_release($sem)) {
        failSem('PHP second sem_release fixture failed');
    }
    if (!sem_remove($sem)) {
        failSem('PHP sem_remove fixture failed');
    }
} catch (Throwable $e) {
    @sem_remove($sem);
    failSem('PHP semaphore lifecycle threw: ' . $e->getMessage());
}

$out = [];
$code = 0;
exec(escapeshellarg($jinx) . ' sem-smoke 2>&1', $out, $code);
$text = rtrim(implode(PHP_EOL, $out), "\r\n");

$expected = 'PASS: native sem get/acquire/nonblock/release/remove lifecycle';
if ($code !== 0 || $text !== $expected) {
    failSem("native semaphore lifecycle mismatch\nExpected: {$expected}\nJINX: {$text}");
}

echo 'PASS: native SysV semaphore lifecycle matches PHP' . PHP_EOL;
