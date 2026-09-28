<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$webTools = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/jinx-web-tools.php');

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run(string $cmd, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($cmd . ' 2>&1', $out, $status);
    $code = $status;

    return implode(PHP_EOL, $out) . (count($out) ? PHP_EOL : '');
}

$cacheRoot = $root . '/build/web-cache-bin-test';

$out = run(sprintf(
    '%s web-cache %s %s',
    $webTools,
    escapeshellarg($root . '/fixtures/simple-web-api-validated.php'),
    escapeshellarg($cacheRoot)
), $code);

if ($code !== 0) {
    fail("JINX web helper web-cache failed:\n{$out}");
}

$data = json_decode($out, true);

if (!is_array($data)) {
    fail("JINX web helper web-cache did not output JSON:\n{$out}");
}

if (empty($data['compiled']) || !is_file($data['compiled'])) {
    fail("JINX web helper web-cache did not create compiled file:\n{$out}");
}

echo "PASS: JINX web helper web-cache creates cached compiled endpoint\n";
