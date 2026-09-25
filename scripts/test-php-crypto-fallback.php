<?php

declare(strict_types=1);

use jinx\web\WebNativeFunctions;

require_once dirname(__DIR__) . '/runtime/WebNativeFunctions.php';

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}\n");
        fwrite(STDERR, 'expected: ' . var_export($expected, true) . "\n");
        fwrite(STDERR, 'actual:   ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

assert_same(md5('jinx'), WebNativeFunctions::call('md5', ['jinx']), 'md5 fallback');
assert_same(sha1('jinx'), WebNativeFunctions::call('sha1', ['jinx']), 'sha1 fallback');
assert_same(hash('sha256', 'jinx'), WebNativeFunctions::call('hash', ['sha256', 'jinx']), 'hash fallback');
assert_same(hash_hmac('sha256', 'jinx', 'secret'), WebNativeFunctions::call('hash_hmac', ['sha256', 'jinx', 'secret']), 'hash_hmac fallback');
assert_same(crc32('jinx'), WebNativeFunctions::call('crc32', ['jinx']), 'crc32 fallback');

$passwordHash = WebNativeFunctions::call('password_hash', ['jinx-secret', PASSWORD_BCRYPT]);
if (!is_string($passwordHash) || !password_verify('jinx-secret', $passwordHash)) {
    fwrite(STDERR, "FAIL: password_hash fallback did not produce a PHP-verifiable hash\n");
    exit(1);
}

assert_same(true, WebNativeFunctions::call('password_verify', ['jinx-secret', $passwordHash]), 'password_verify fallback');

$bytes = WebNativeFunctions::call('random_bytes', [8]);
if (!is_string($bytes) || strlen($bytes) !== 8) {
    fwrite(STDERR, "FAIL: random_bytes fallback did not return 8 bytes\n");
    exit(1);
}

$int = WebNativeFunctions::call('random_int', [1, 3]);
if (!is_int($int) || $int < 1 || $int > 3) {
    fwrite(STDERR, "FAIL: random_int fallback returned an out-of-range value\n");
    exit(1);
}

printf("PASS: PHP crypto fallback matches original PHP behavior for core crypto cases.\n");
