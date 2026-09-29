<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failPasswordHash(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runPasswordHash(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failPasswordHash('repository-root native ./jinx missing');
}
if (!function_exists('password_hash') || !defined('PASSWORD_BCRYPT')) {
    failPasswordHash('PHP bcrypt password API unavailable');
}

$password = 'jinx-password-42';
$wrong = 'jinx-password-wrong';
$algorithm = constant('PASSWORD_BCRYPT');
$typedAlgorithm = is_int($algorithm)
    ? 'i:' . $algorithm
    : 's:' . (string)$algorithm;

$hashOut = runPasswordHash(
    escapeshellarg($jinx)
    . ' oracle-call password_hash '
    . escapeshellarg('s:' . $password)
    . ' ' . escapeshellarg($typedAlgorithm),
    $code
);

if ($code !== 0 || !str_starts_with($hashOut, 'string:')) {
    failPasswordHash("native password_hash failed:\n{$hashOut}");
}

$hash = substr($hashOut, strlen('string:'));
if (!preg_match('/^\$2[abyx]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) {
    failPasswordHash("native password_hash returned malformed bcrypt hash:\n{$hashOut}");
}

if (!password_verify($password, $hash)) {
    failPasswordHash('PHP password_verify rejected native bcrypt hash');
}
if (password_verify($wrong, $hash)) {
    failPasswordHash('PHP password_verify accepted wrong password for native hash');
}

$verifyOut = runPasswordHash(
    escapeshellarg($jinx)
    . ' oracle-call password_verify '
    . escapeshellarg('s:' . $password)
    . ' ' . escapeshellarg('s:' . $hash),
    $verifyCode
);
if ($verifyCode !== 0 || trim($verifyOut) !== 'bool:true') {
    failPasswordHash("native password_verify rejected native password_hash output:\n{$verifyOut}");
}

$wrongOut = runPasswordHash(
    escapeshellarg($jinx)
    . ' oracle-call password_verify '
    . escapeshellarg('s:' . $wrong)
    . ' ' . escapeshellarg('s:' . $hash),
    $wrongCode
);
if ($wrongCode !== 0 || trim($wrongOut) !== 'bool:false') {
    failPasswordHash("native password_verify accepted wrong password:\n{$wrongOut}");
}

$phpHash = password_hash($password, PASSWORD_BCRYPT);
if (!is_string($phpHash)) {
    failPasswordHash('PHP password_hash did not return bcrypt string');
}
$nativePhpHashVerify = runPasswordHash(
    escapeshellarg($jinx)
    . ' oracle-call password_verify '
    . escapeshellarg('s:' . $password)
    . ' ' . escapeshellarg('s:' . $phpHash),
    $phpVerifyCode
);
if ($phpVerifyCode !== 0 || trim($nativePhpHashVerify) !== 'bool:true') {
    failPasswordHash("native password_verify rejected PHP bcrypt hash:\n{$nativePhpHashVerify}");
}

echo "PASS: native password_hash bcrypt output verifies in PHP and Jinx" . PHP_EOL;
