<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function opcacheFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function opcacheRun(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function opcacheCall(string $jinx, string $name, array $args, ?int &$code = null): string
{
    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($name);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string)$arg);
    }
    return opcacheRun($command, $code);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    opcacheFail('repository-root native ./jinx missing or not executable');
}

foreach ([
    'opcache_get_status',
    'opcache_reset',
    'opcache_invalidate',
    'opcache_is_script_cached',
    'opcache_compile_file',
] as $name) {
    if (!function_exists($name)) {
        opcacheFail("PHP {$name} is required");
    }
}

$enableCli = ini_get('opcache.enable_cli');
if ($enableCli !== '0' && strcasecmp((string)$enableCli, 'off') !== 0) {
    opcacheFail(
        'parity fixture requires PHP CLI OPcache disabled; got ' .
        var_export($enableCli, true)
    );
}

$file = tempnam(sys_get_temp_dir(), 'jinx-opcache-');
if ($file === false) opcacheFail('could not create OPcache parity file');
file_put_contents($file, "<?php return 1;\n");

$php = [
    'status' => opcache_get_status(false),
    'reset' => opcache_reset(),
    'invalidate' => opcache_invalidate($file, true),
    'cached' => opcache_is_script_cached($file),
    'compile' => @opcache_compile_file($file),
];

$native = [
    'status' => opcacheCall($jinx, 'opcache_get_status', ['b:false'], $statusCode),
    'reset' => opcacheCall($jinx, 'opcache_reset', [], $resetCode),
    'invalidate' => opcacheCall(
        $jinx,
        'opcache_invalidate',
        ['s:' . $file, 'b:true'],
        $invalidateCode
    ),
    'cached' => opcacheCall(
        $jinx,
        'opcache_is_script_cached',
        ['s:' . $file],
        $cachedCode
    ),
    'compile' => opcacheCall(
        $jinx,
        'opcache_compile_file',
        ['s:' . $file],
        $compileCode
    ),
];

@unlink($file);

$codes = [
    'status' => $statusCode,
    'reset' => $resetCode,
    'invalidate' => $invalidateCode,
    'cached' => $cachedCode,
    'compile' => $compileCode,
];

foreach ($php as $label => $value) {
    $expected = 'bool:' . ($value ? 'true' : 'false');
    if ($codes[$label] !== 0 || $native[$label] !== $expected) {
        opcacheFail(
            "{$label} disabled-CLI parity mismatch\n" .
            "PHP/expected: {$expected}\n" .
            "JINX: {$native[$label]}"
        );
    }
}

echo "PASS: native disabled CLI OPcache functions match PHP", PHP_EOL;
