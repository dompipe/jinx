<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failShm(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failShm('repository-root native ./jinx missing or not executable');
}

$required = [
    'shm_attach',
    'shm_detach',
    'shm_get_var',
    'shm_has_var',
    'shm_put_var',
    'shm_remove',
    'shm_remove_var',
];
foreach ($required as $function) {
    if (!function_exists($function)) {
        failShm("PHP runtime missing required {$function}() contract");
    }
}

$memory = shm_attach(0, 8192, 0600);
if (!$memory instanceof SysvSharedMemory) {
    failShm('PHP shm_attach fixture failed');
}

if (!shm_put_var($memory, 11, 'hello')) {
    @shm_remove($memory);
    @shm_detach($memory);
    failShm('PHP shm_put_var string fixture failed');
}
if (!shm_has_var($memory, 11)) {
    @shm_remove($memory);
    @shm_detach($memory);
    failShm('PHP shm_has_var did not find written key');
}
if (shm_get_var($memory, 11) !== 'hello') {
    @shm_remove($memory);
    @shm_detach($memory);
    failShm('PHP shm_get_var string roundtrip mismatch');
}
if (!shm_remove_var($memory, 11) || shm_has_var($memory, 11)) {
    @shm_remove($memory);
    @shm_detach($memory);
    failShm('PHP shm_remove_var lifecycle mismatch');
}

$payload = [7, 9];
if (!shm_put_var($memory, 22, $payload)) {
    @shm_remove($memory);
    @shm_detach($memory);
    failShm('PHP shm_put_var array fixture failed');
}
if (shm_get_var($memory, 22) !== $payload) {
    @shm_remove($memory);
    @shm_detach($memory);
    failShm('PHP shm_get_var array roundtrip mismatch');
}

if (!shm_remove($memory)) {
    @shm_detach($memory);
    failShm('PHP shm_remove fixture failed');
}
if (!shm_detach($memory)) {
    failShm('PHP shm_detach fixture failed');
}

$out = [];
$code = 0;
exec(escapeshellarg($jinx) . ' shm-smoke 2>&1', $out, $code);
$text = rtrim(implode(PHP_EOL, $out), "\r\n");

$expected = 'PASS: native SysV shared-memory attach/put/get/has/remove/detach lifecycle';
if ($code !== 0 || $text !== $expected) {
    failShm("native shared-memory lifecycle mismatch\nExpected: {$expected}\nJINX: {$text}");
}

echo 'PASS: native SysV shared-memory variable lifecycle matches PHP' . PHP_EOL;
