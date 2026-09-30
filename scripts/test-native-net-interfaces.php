<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failNet(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!function_exists('net_get_interfaces')) {
    failNet('PHP runtime missing net_get_interfaces() contract');
}
if (!is_file($jinx) || !is_executable($jinx)) {
    failNet('repository-root native ./jinx missing or not executable');
}

$interfaces = net_get_interfaces();
if (!is_array($interfaces)) {
    failNet('PHP net_get_interfaces returned false');
}

$unicastCount = 0;
$upCount = 0;
foreach ($interfaces as $name => $interface) {
    if (!is_string($name) ||
        !is_array($interface) ||
        !array_key_exists('unicast', $interface) ||
        !is_array($interface['unicast']) ||
        !array_key_exists('up', $interface) ||
        !is_bool($interface['up'])) {
        failNet('PHP net_get_interfaces interface shape mismatch');
    }
    if ($interface['up']) {
        ++$upCount;
    }
    foreach ($interface['unicast'] as $unicast) {
        if (!is_array($unicast)) {
            failNet('PHP net_get_interfaces unicast entry is not an array');
        }
        ++$unicastCount;
    }
}

$expected = implode(PHP_EOL, [
    'interfaces=' . count($interfaces),
    'unicast=' . $unicastCount,
    'up=' . $upCount,
]);

$out = [];
$code = 0;
exec(escapeshellarg($jinx) . ' net-interfaces-smoke 2>&1', $out, $code);
$actual = rtrim(implode(PHP_EOL, $out), "\r\n");

if ($code !== 0 || $actual !== $expected) {
    failNet("net_get_interfaces parity mismatch\nPHP/expected:\n{$expected}\nJINX:\n{$actual}");
}

echo 'PASS: native net_get_interfaces shape and live interface counts match PHP' . PHP_EOL;
