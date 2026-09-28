<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$build = $root . '/scripts/build-oracle-zlib-smoke.sh';
$binary = $root . '/build/native/jinx-oracle-zlib-smoke';

$out = [];
$code = 0;
exec('sh ' . escapeshellarg($build) . ' 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "FAIL: zlib smoke build failed\n" . implode(PHP_EOL, $out) . PHP_EOL);
    exit(1);
}

$out = [];
exec(escapeshellarg($binary) . ' 2>&1', $out, $code);
$text = implode(PHP_EOL, $out);
if ($code !== 0 || !str_contains($text, 'PASS: native Oracle zlib helpers passed')) {
    fwrite(STDERR, "FAIL: native zlib smoke failed\n{$text}\n");
    exit(1);
}

$payload = 'JINX zlib parity 123123123';

$gzcompress = gzcompress($payload);
$gzdeflate = gzdeflate($payload, 6);
$gzencode = gzencode($payload, 6);
$zlib = zlib_encode($payload, ZLIB_ENCODING_DEFLATE, 6);

if (!is_string($gzcompress) || !is_string($gzdeflate) || !is_string($gzencode) || !is_string($zlib)) {
    fwrite(STDERR, "FAIL: PHP zlib fixture failed to encode\n");
    exit(1);
}

$expected = 'ZLIB:'
    . 'gzcompress=' . bin2hex($gzcompress)
    . ';gzuncompress=' . bin2hex((string)gzuncompress($gzcompress))
    . ';gzdeflate=' . bin2hex($gzdeflate)
    . ';gzinflate=' . bin2hex((string)gzinflate($gzdeflate))
    . ';gzencode=' . bin2hex($gzencode)
    . ';gzdecode=' . bin2hex((string)gzdecode($gzencode))
    . ';zlib=' . bin2hex($zlib)
    . ';zlibdecode_gzip=' . bin2hex((string)zlib_decode($gzencode))
    . ';zlibdecode_raw=' . bin2hex((string)zlib_decode($gzdeflate))
    . ';maxok=' . bin2hex((string)gzuncompress($gzcompress, strlen($payload)))
    . ';maxfail=false';

if (!str_contains($text, $expected)) {
    fwrite(STDERR, "FAIL: PHP-vs-JINX zlib parity mismatch\nPHP: {$expected}\nJINX:\n{$text}\n");
    exit(1);
}

$jinx = $root . '/jinx';
$rejectedOptions = [
    ['gzcompress', ['abc', 4294967295]],
    ['gzdeflate', ['abc', 4294967296]],
    ['gzencode', ['abc', -1, 4294967327]],
    ['zlib_encode', ['abc', 4294967311]],
    ['zlib_encode', ['abc', ZLIB_ENCODING_DEFLATE, 4294967295]],
];
foreach ($rejectedOptions as [$function, $args]) {
    $phpRejected = false;
    try {
        $function(...$args);
    } catch (ValueError) {
        $phpRejected = true;
    }
    if (!$phpRejected) {
        fwrite(STDERR, "FAIL: PHP accepted zlib rejection fixture {$function}\n");
        exit(1);
    }
    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($function);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((is_string($arg) ? 's:' : 'i:') . $arg);
    }
    $out = [];
    $code = 0;
    exec($command . ' 2>&1', $out, $code);
    if ($code === 0) {
        fwrite(STDERR, "FAIL: native {$function} accepted a PHP-rejected wide option\n");
        exit(1);
    }
}

echo "PASS: native Oracle zlib helpers match PHP for covered one-shot semantics and reject invalid wide options\n";
