<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$binary = $root . '/jinx';
$directories = [
    'build/differential/php-vs-jinx-high-value-edge-fixtures',
    'build/differential/php-vs-jinx-high-value-edge-fixtures-two',
];

function sourceAuditRun(array $command, string $cwd, ?array $environment = null): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start audit child');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$environment = getenv();
$environment['PATH'] = '/jinx-audit-no-executables';
$environment['JINX_NATIVE_ONLY'] = '1';
$environment['JINX_ORACLE_SCRIPT_RUNNER'] = '/jinx-audit-bridge-must-not-run';
$report = ['contract' => 'Strict native source parity; PHP baseline is independent of the target process.', 'php_version' => PHP_VERSION, 'waves' => []];
$failed = false;
foreach ($directories as $directory) {
    $files = glob($root . '/' . $directory . '/*.php');
    if (!$files) throw new RuntimeException('Generate edge fixtures first: ' . $directory);
    $wave = ['native_parity' => 0, 'native_mismatch' => 0, 'nonzero_php_baseline' => 0, 'cases' => []];
    foreach ($files as $file) {
        $php = sourceAuditRun([PHP_BINARY, $file], $root);
        $native = sourceAuditRun([$binary, '--native-php', $file], $root, $environment);
        $status = $php['exit'] !== 0 ? 'nonzero_php_baseline'
            : ($php === $native ? 'native_parity' : 'native_mismatch');
        $wave[$status]++;
        $failed = $failed || $status !== 'native_parity';
        $wave['cases'][basename($file)] = ['status' => $status, 'php' => $php, 'native' => $native];
        if ($status !== 'native_parity') echo $status . ': ' . basename($file) . PHP_EOL;
    }
    $report['waves'][$directory] = $wave;
    printf("%s: native parity=%d, mismatch=%d, nonzero PHP baseline=%d, total=%d\n", basename($directory), $wave['native_parity'], $wave['native_mismatch'], $wave['nonzero_php_baseline'], count($files));
}
$outputDirectory = $root . '/build/audits';
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) throw new RuntimeException('Could not create audit report directory');
$path = $outputDirectory . '/native-source-parity.json';
if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL) === false) throw new RuntimeException('Could not write audit report');
echo 'Report: ' . $path . PHP_EOL;
exit($failed ? 1 : 0);
