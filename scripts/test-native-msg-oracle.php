<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failMsg(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failMsg('repository-root native ./jinx missing or not executable');
}

$required = [
    'msg_get_queue',
    'msg_queue_exists',
    'msg_send',
    'msg_receive',
    'msg_remove_queue',
    'msg_set_queue',
    'msg_stat_queue',
];
foreach ($required as $function) {
    if (!function_exists($function)) {
        failMsg("PHP runtime missing required {$function}() contract");
    }
}

$key = 0x4A000000 | (getmypid() & 0xffff);
if (msg_queue_exists($key)) {
    $stale = msg_get_queue($key, 0600);
    @msg_remove_queue($stale);
}

$queue = msg_get_queue($key, 0600);
if (!$queue instanceof SysvMessageQueue) {
    failMsg('PHP msg_get_queue fixture failed');
}
if (!msg_queue_exists($key)) {
    @msg_remove_queue($queue);
    failMsg('PHP msg_queue_exists did not see created queue');
}

if (!msg_set_queue($queue, ['msg_perm.mode' => 0600])) {
    @msg_remove_queue($queue);
    failMsg('PHP msg_set_queue fixture failed');
}

$stats = msg_stat_queue($queue);
if (!is_array($stats) ||
    (($stats['msg_perm.mode'] ?? 0) & 0777) !== 0600 ||
    ($stats['msg_qnum'] ?? -1) !== 0) {
    @msg_remove_queue($queue);
    failMsg('PHP initial msg_stat_queue metadata mismatch');
}

$error = 0;
if (!msg_send($queue, 7, 'hello', true, true, $error)) {
    @msg_remove_queue($queue);
    failMsg("PHP msg_send fixture failed with error {$error}");
}

$stats = msg_stat_queue($queue);
if (!is_array($stats) || ($stats['msg_qnum'] ?? -1) !== 1) {
    @msg_remove_queue($queue);
    failMsg('PHP queue depth did not become one');
}

$receivedType = 0;
$message = null;
$error = 0;
if (!msg_receive(
        $queue,
        7,
        $receivedType,
        1024,
        $message,
        true,
        0,
        $error
    ) ||
    $receivedType !== 7 ||
    $message !== 'hello') {
    @msg_remove_queue($queue);
    failMsg("PHP msg_receive fixture mismatch; type={$receivedType} error={$error}");
}

$stats = msg_stat_queue($queue);
if (!is_array($stats) || ($stats['msg_qnum'] ?? -1) !== 0) {
    @msg_remove_queue($queue);
    failMsg('PHP queue depth did not return to zero');
}

if (!msg_remove_queue($queue)) {
    failMsg('PHP msg_remove_queue fixture failed');
}
if (msg_queue_exists($key)) {
    failMsg('PHP removed queue still exists');
}

$out = [];
$code = 0;
exec(escapeshellarg($jinx) . ' msg-smoke 2>&1', $out, $code);
$text = rtrim(implode(PHP_EOL, $out), "\r\n");

$expected = 'PASS: native SysV message queue send/receive/stat/set/remove lifecycle';
if ($code !== 0 || $text !== $expected) {
    failMsg("native message queue lifecycle mismatch\nExpected: {$expected}\nJINX: {$text}");
}

echo 'PASS: native SysV message queue lifecycle matches PHP' . PHP_EOL;
