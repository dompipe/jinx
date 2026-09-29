<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function sodiumFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function sodiumRun(string $cmd, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($cmd . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function sodiumJinx(
    string $jinx,
    string $name,
    array $args = [],
    bool $hex = false,
    ?int &$code = null
): string {
    $cmd = escapeshellarg($jinx)
        . ' ' . ($hex ? 'oracle-call-hex' : 'oracle-call')
        . ' ' . escapeshellarg($name);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    return sodiumRun($cmd, $code);
}

function sodiumTypedString(string $bytes): string
{
    return 'h:' . bin2hex($bytes);
}

function sodiumExpect(
    string $jinx,
    string $name,
    array $args,
    string $expected,
    bool $hex = false
): void {
    $actual = sodiumJinx($jinx, $name, $args, $hex, $code);
    if ($code !== 0 || $actual !== $expected) {
        sodiumFail("{$name} parity mismatch\nPHP/expected: {$expected}\nJINX: {$actual}");
    }
}

function sodiumExpectHex(
    string $jinx,
    string $name,
    array $args,
    string $expected
): void {
    sodiumExpect($jinx, $name, $args, 'hex:' . bin2hex($expected), true);
}

function sodiumExpectHexLength(
    string $jinx,
    string $name,
    array $args,
    int $bytes
): void {
    $actual = sodiumJinx($jinx, $name, $args, true, $code);
    if ($code !== 0 ||
        !preg_match('/^hex:([0-9a-f]*)$/', $actual, $m) ||
        strlen($m[1]) !== $bytes * 2) {
        sodiumFail("{$name} native length mismatch\nExpected bytes: {$bytes}\nJINX: {$actual}");
    }
}

if (!is_file($jinx) || !is_executable($jinx)) {
    sodiumFail('repository-root native ./jinx missing or not executable');
}

if (!extension_loaded('sodium') || !function_exists('sodium_bin2hex')) {
    sodiumFail('PHP sodium extension is required for native parity');
}

$binary = "\x00JiNx\xff";
sodiumExpect(
    $jinx,
    'sodium_bin2hex',
    [sodiumTypedString($binary)],
    'string:' . sodium_bin2hex($binary)
);

$hexText = '00:4a:69:4e:78:ff';
sodiumExpectHex(
    $jinx,
    'sodium_hex2bin',
    ['s:' . $hexText, 's::'],
    sodium_hex2bin($hexText, ':')
);

foreach ([SODIUM_BASE64_VARIANT_ORIGINAL, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING] as $variant) {
    $encoded = sodium_bin2base64($binary, $variant);
    sodiumExpect(
        $jinx,
        'sodium_bin2base64',
        [sodiumTypedString($binary), 'i:' . $variant],
        'string:' . $encoded
    );
    sodiumExpectHex(
        $jinx,
        'sodium_base642bin',
        ['s:' . $encoded, 'i:' . $variant],
        sodium_base642bin($encoded, $variant)
    );
}

$left = "\x01\x02\x03\x04";
$right = "\x01\x02\x03\x05";
sodiumExpect(
    $jinx,
    'sodium_compare',
    [sodiumTypedString($left), sodiumTypedString($right)],
    'int:' . sodium_compare($left, $right)
);
sodiumExpect(
    $jinx,
    'sodium_memcmp',
    [sodiumTypedString($left), sodiumTypedString($right)],
    'int:' . sodium_memcmp($left, $right)
);
sodiumExpect(
    $jinx,
    'sodium_memcmp',
    [sodiumTypedString($left), sodiumTypedString($left)],
    'int:' . sodium_memcmp($left, $left)
);

$padded = sodium_pad("Jinx\0bin", 16);
sodiumExpectHex(
    $jinx,
    'sodium_pad',
    [sodiumTypedString("Jinx\0bin"), 'i:16'],
    $padded
);
sodiumExpectHex(
    $jinx,
    'sodium_unpad',
    [sodiumTypedString($padded), 'i:16'],
    sodium_unpad($padded, 16)
);

$authKey = '';
for ($i = 0; $i < SODIUM_CRYPTO_AUTH_KEYBYTES; $i++) {
    $authKey .= chr($i & 0xff);
}
$message = "native\0sodium";
$auth = sodium_crypto_auth($message, $authKey);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_auth',
    [sodiumTypedString($message), sodiumTypedString($authKey)],
    $auth
);
sodiumExpect(
    $jinx,
    'sodium_crypto_auth_verify',
    [sodiumTypedString($auth), sodiumTypedString($message), sodiumTypedString($authKey)],
    'bool:' . (sodium_crypto_auth_verify($auth, $message, $authKey) ? 'true' : 'false')
);
sodiumExpectHexLength($jinx, 'sodium_crypto_auth_keygen', [], SODIUM_CRYPTO_AUTH_KEYBYTES);

$genericKey = substr(hash('sha256', 'jinx-generichash-key', true), 0, SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
$generic = sodium_crypto_generichash($message, $genericKey, 32);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_generichash',
    [sodiumTypedString($message), sodiumTypedString($genericKey), 'i:32'],
    $generic
);
sodiumExpectHexLength(
    $jinx,
    'sodium_crypto_generichash_keygen',
    [],
    SODIUM_CRYPTO_GENERICHASH_KEYBYTES
);

$secretKey = substr(hash('sha256', 'jinx-secretbox-key', true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$nonce = substr(hash('sha256', 'jinx-secretbox-nonce', true), 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$ciphertext = sodium_crypto_secretbox($message, $nonce, $secretKey);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_secretbox',
    [sodiumTypedString($message), sodiumTypedString($nonce), sodiumTypedString($secretKey)],
    $ciphertext
);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_secretbox_open',
    [sodiumTypedString($ciphertext), sodiumTypedString($nonce), sodiumTypedString($secretKey)],
    (string)sodium_crypto_secretbox_open($ciphertext, $nonce, $secretKey)
);
sodiumExpectHexLength(
    $jinx,
    'sodium_crypto_secretbox_keygen',
    [],
    SODIUM_CRYPTO_SECRETBOX_KEYBYTES
);

$shortKey = substr(hash('sha256', 'jinx-short-key', true), 0, SODIUM_CRYPTO_SHORTHASH_KEYBYTES);
$shortHash = sodium_crypto_shorthash($message, $shortKey);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_shorthash',
    [sodiumTypedString($message), sodiumTypedString($shortKey)],
    $shortHash
);
sodiumExpectHexLength(
    $jinx,
    'sodium_crypto_shorthash_keygen',
    [],
    SODIUM_CRYPTO_SHORTHASH_KEYBYTES
);

$seed = substr(hash('sha256', 'jinx-sign-seed', true), 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
$keypair = sodium_crypto_sign_seed_keypair($seed);
$signSecret = sodium_crypto_sign_secretkey($keypair);
$signPublic = sodium_crypto_sign_publickey($keypair);

sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign_seed_keypair',
    [sodiumTypedString($seed)],
    $keypair
);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign_secretkey',
    [sodiumTypedString($keypair)],
    $signSecret
);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign_publickey',
    [sodiumTypedString($keypair)],
    $signPublic
);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign_publickey_from_secretkey',
    [sodiumTypedString($signSecret)],
    sodium_crypto_sign_publickey_from_secretkey($signSecret)
);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign_keypair_from_secretkey_and_publickey',
    [sodiumTypedString($signSecret), sodiumTypedString($signPublic)],
    sodium_crypto_sign_keypair_from_secretkey_and_publickey($signSecret, $signPublic)
);

$signature = sodium_crypto_sign_detached($message, $signSecret);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign_detached',
    [sodiumTypedString($message), sodiumTypedString($signSecret)],
    $signature
);
sodiumExpect(
    $jinx,
    'sodium_crypto_sign_verify_detached',
    [sodiumTypedString($signature), sodiumTypedString($message), sodiumTypedString($signPublic)],
    'bool:' . (sodium_crypto_sign_verify_detached($signature, $message, $signPublic) ? 'true' : 'false')
);
$signed = sodium_crypto_sign($message, $signSecret);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign',
    [sodiumTypedString($message), sodiumTypedString($signSecret)],
    $signed
);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_sign_open',
    [sodiumTypedString($signed), sodiumTypedString($signPublic)],
    (string)sodium_crypto_sign_open($signed, $signPublic)
);
sodiumExpectHexLength(
    $jinx,
    'sodium_crypto_sign_keypair',
    [],
    SODIUM_CRYPTO_SIGN_SECRETKEYBYTES + SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
);

$scalarA = substr(hash('sha256', 'jinx-scalar-a', true), 0, SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
$scalarB = substr(hash('sha256', 'jinx-scalar-b', true), 0, SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
$pointB = sodium_crypto_scalarmult_base($scalarB);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_scalarmult_base',
    [sodiumTypedString($scalarA)],
    sodium_crypto_scalarmult_base($scalarA)
);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_scalarmult',
    [sodiumTypedString($scalarA), sodiumTypedString($pointB)],
    (string)sodium_crypto_scalarmult($scalarA, $pointB)
);

$kdfKey = substr(hash('sha256', 'jinx-kdf-key', true), 0, SODIUM_CRYPTO_KDF_KEYBYTES);
$context = 'JINXTEST';
$derived = sodium_crypto_kdf_derive_from_key(32, 7, $context, $kdfKey);
sodiumExpectHex(
    $jinx,
    'sodium_crypto_kdf_derive_from_key',
    ['i:32', 'i:7', sodiumTypedString($context), sodiumTypedString($kdfKey)],
    $derived
);
sodiumExpectHexLength(
    $jinx,
    'sodium_crypto_kdf_keygen',
    [],
    SODIUM_CRYPTO_KDF_KEYBYTES
);

echo "PASS: native libsodium core functions match PHP sodium byte-for-byte\n";
