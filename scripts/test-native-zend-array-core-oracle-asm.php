<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$build = $root . '/scripts/build-oracle-dispatch-zend-array-smoke.sh';
$binary = $root . '/build/native/jinx-oracle-dispatch-zend-array-smoke';

$out = [];
$code = 0;
exec('sh ' . escapeshellarg($build) . ' 2>&1', $out, $code);

if ($code !== 0) {
    fwrite(STDERR, "FAIL: Zend-array native core build failed\n" . implode(PHP_EOL, $out) . PHP_EOL);
    exit(1);
}

$out = [];
exec(escapeshellarg($binary) . ' 2>&1', $out, $code);
$text = implode(PHP_EOL, $out);

if ($code !== 0 || !str_contains($text, 'PASS: Oracle generated dispatch Zend-array native core passed')) {
    fwrite(STDERR, "FAIL: Zend-array native core smoke failed\n{$text}\n");
    exit(1);
}

$pairs = static function (array $value): string {
    $out = [];
    foreach ($value as $key => $item) {
        $out[] = $key . ':' . $item;
    }
    return implode(',', $out);
};

$base = [10, 20, 'name' => 30, 'keep' => 40];
$expectedParity = 'PARITY:'
    . 'sum=' . array_sum($base)
    . ';product=' . array_product($base)
    . ';implode=' . implode(',', $base)
    . ';range=' . implode(',', range(1, 5))
    . ';fill=' . $pairs(array_fill(2, 3, 9))
    . ';combine=' . $pairs(array_combine([2, 'x'], [70, 80]))
    . ';count_values=' . $pairs(array_count_values([2, 2, 'x', 'x', 'x']));

if (!str_contains($text, $expectedParity)) {
    fwrite(STDERR, "FAIL: PHP-vs-JINX Zend-array parity mismatch\nPHP: {$expectedParity}\nJINX:\n{$text}\n");
    exit(1);
}

echo 'PASS: native Oracle ASM Zend-array core matches PHP for covered carried-array semantics' . PHP_EOL;
