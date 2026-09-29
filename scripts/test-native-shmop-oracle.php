<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failShmop(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failShmop('repository-root native ./jinx missing or not executable');
}

foreach ([
    'shmop_open',
    'shmop_read',
    'shmop_write',
    'shmop_size',
    'shmop_delete',
    'shmop_close',
] as $function) {
    if (!function_exists($function)) {
        failShmop("PHP runtime missing required {$function}() contract");
    }
}

$shm = @shmop_open(0, 'n', 0600, 64);
if ($shm === false) {
    failShmop('PHP shmop_open fixture failed');
}

try {
    if (shmop_size($shm) !== 64) {
        failShmop('PHP shmop_size fixture did not return 64');
    }
    if (shmop_write($shm, 'JINX', 4) !== 4) {
        failShmop('PHP shmop_write fixture did not write four bytes');
    }
    if (shmop_read($shm, 4, 4) !== 'JINX') {
        failShmop('PHP shmop_read fixture did not return written bytes');
    }
    if (!shmop_delete($shm)) {
        failShmop('PHP shmop_delete fixture failed');
    }
    @shmop_close($shm);
} catch (Throwable $e) {
    @shmop_delete($shm);
    @shmop_close($shm);
    failShmop('PHP shmop lifecycle threw: ' . $e->getMessage());
}

$out = [];
$code = 0;
exec(escapeshellarg($jinx) . ' shmop-smoke 2>&1', $out, $code);
$text = rtrim(implode(PHP_EOL, $out), "\r\n");

$expected = 'PASS: native shmop open/write/read/size/delete/close lifecycle';
if ($code !== 0 || $text !== $expected) {
    failShmop("native shmop lifecycle mismatch\nExpected: {$expected}\nJINX: {$text}");
}

echo 'PASS: native shmop shared-memory lifecycle matches PHP' . PHP_EOL;
