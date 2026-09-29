<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failPfs(string $m): never {
    fwrite(STDERR, "FAIL: {$m}
");
    exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    failPfs("could not create local TCP server: {$errno} {$errstr}");
}

$name = stream_socket_get_name($server, false);
if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
    fclose($server);
    failPfs('could not determine local TCP port');
}
$port = (int)$m[1];

$cmd = escapeshellarg($jinx)
    . ' oracle-call pfsockopen '
    . escapeshellarg('s:127.0.0.1')
    . ' '
    . escapeshellarg('i:' . $port);

$out = [];
$code = 0;
exec($cmd . ' 2>&1', $out, $code);
fclose($server);

$actual = trim(implode("
", $out));
if ($code !== 0 || $actual !== 'zend-object:stream:1') {
    failPfs("pfsockopen local-connect parity mismatch
JINX: {$actual}");
}

$php = @pfsockopen('127.0.0.1', 1, $eno, $estr, 0.05);
if (is_resource($php)) fclose($php);
$expectedClosed = $php === false ? 'bool:false' : 'zend-object:stream:1';

$cmd = escapeshellarg($jinx)
    . ' oracle-call pfsockopen '
    . escapeshellarg('s:127.0.0.1')
    . ' '
    . escapeshellarg('i:1');
$out = [];
$code = 0;
exec($cmd . ' 2>&1', $out, $code);
$actualClosed = trim(implode("
", $out));

if ($code !== 0 || $actualClosed !== $expectedClosed) {
    failPfs("pfsockopen closed-port parity mismatch
PHP/expected: {$expectedClosed}
JINX: {$actualClosed}");
}

echo "PASS: native pfsockopen matches bounded PHP socket behavior
";
