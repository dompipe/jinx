<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!function_exists('posix_mknod')) {
    fwrite(STDERR, "FAIL: posix_mknod PHP baseline unavailable\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/jinx-mknod-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create test directory');
$paths = [$directory . '/php', $directory . '/native'];
try {
    foreach ([2, 3, 4] as $arity) {
        $args = [$paths[0], 0010000 | 0600, 0, 0];
        $expected = posix_mknod(...array_slice($args, 0, $arity));
        $duplicate = @posix_mknod(...array_slice($args, 0, $arity));
        $command = [$root . '/jinx', 'oracle-call', 'posix_mknod', 's:' . $paths[1], 'i:' . $args[1]];
        if ($arity >= 3) $command[] = 'i:0';
        if ($arity >= 4) $command[] = 'i:0';
        // Create only a FIFO, never a privileged block or character device.
        foreach ([$expected, $duplicate] as $want) {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            if (!is_resource($process)) throw new RuntimeException('Cannot start native probe');
            fclose($pipes[0]);
            $stdout = trim(stream_get_contents($pipes[1]));
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0 || $stdout !== 'bool:' . ($want ? 'true' : 'false')) {
                throw new RuntimeException("arity={$arity} native mismatch: {$stdout} {$stderr}");
            }
        }
        if (!$expected || $duplicate !== false || filetype($paths[0]) !== 'fifo' || filetype($paths[1]) !== 'fifo') {
            throw new RuntimeException('FIFO creation/type mismatch');
        }
        foreach ($paths as $path) unlink($path);
        clearstatcache();
    }
    echo "PASS: native posix_mknod FIFO creation and existing-path failure, arities 2/3/4\n";
} finally {
    foreach ($paths as $path) if (file_exists($path)) unlink($path);
    rmdir($directory);
}
