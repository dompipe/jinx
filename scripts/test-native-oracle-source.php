<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$binary = $root . '/jinx';
$fixture = $root . '/fixtures/oracle-native-source.php';

function native_source_run(array $command, bool $withoutPhp): array
{
    $environment = getenv();
    if ($withoutPhp) {
        $environment['PATH'] = '/jinx-test-no-executables';
        $environment['JINX_NATIVE_ONLY'] = '1';
        $environment['JINX_ORACLE_SCRIPT_RUNNER'] = '/jinx-test-bridge-must-not-run';
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) throw new RuntimeException('could not start test process');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}

$php = native_source_run([PHP_BINARY, $fixture], false);
if ($php !== [0, "oracle:6:6:9:1:3\n23:7\n", '']) {
    throw new RuntimeException('unexpected PHP baseline: ' . json_encode($php));
}
foreach ([[$binary, '--native-php', $fixture], [$binary, $fixture]] as $command) {
    $native = native_source_run($command, true);
    if ($native !== $php) throw new RuntimeException('PHP-independent execution differs: ' . json_encode($native));
}

$temporary = tempnam(sys_get_temp_dir(), 'jinx-native-reject-');
if ($temporary === false) throw new RuntimeException('could not create rejection fixture');
try {
    file_put_contents($temporary, '<?php echo "MUST_NOT_RUN"; new class {};');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '' || !str_contains($rejected[2], 'refusing PHP fallback')) {
        throw new RuntimeException('unsupported input was not rejected before execution: ' . json_encode($rejected));
    }
} finally {
    unlink($temporary);
}
echo "PASS: native Oracle source execution matches PHP with no PHP on PATH; includes share variables and return values; unsupported syntax fails before execution\n";
