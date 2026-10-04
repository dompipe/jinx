<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$binary = $root . '/jinx';

function big_family_run(array $command, bool $withoutPhp): array
{
    $root = dirname(__DIR__);
    $environment = getenv();
    if ($withoutPhp) {
        $environment['PATH'] = '/jinx-test-no-executables';
        $environment['JINX_NATIVE_ONLY'] = '1';
        $environment['JINX_ORACLE_SCRIPT_RUNNER'] = '/jinx-test-bridge-must-not-run';
    }

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start big-family parity process');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

$fixtures = [
    'call-arguments' => 'oracle-native-call-arguments.php',
    'nullsafe' => 'oracle-native-nullsafe.php',
    'late-static' => 'oracle-native-late-static.php',
    'late-static-properties' => 'oracle-native-late-static-properties.php',
    'late-static-parent' => 'oracle-native-late-static-parent.php',
    'readonly' => 'oracle-native-readonly.php',
    'magic' => 'oracle-native-magic.php',
    'enums-match' => 'oracle-native-enums-match.php',
    'namespaces' => 'oracle-native-namespaces.php',
    'generators' => 'oracle-native-generators.php',
];

$failed = 0;
$passed = 0;

foreach ($fixtures as $family => $file) {
    $path = $root . '/fixtures/' . $file;
    $php = big_family_run([PHP_BINARY, $path], false);
    if ($php[0] !== 0) {
        fwrite(STDERR, "BASELINE FAIL {$family}: " . json_encode($php) . PHP_EOL);
        $failed++;
        continue;
    }

    $native = big_family_run([$binary, $path], true);
    if ($native !== $php) {
        fwrite(STDERR, "NATIVE FAIL {$family}" . PHP_EOL);
        fwrite(STDERR, "PHP:   " . json_encode($php) . PHP_EOL);
        fwrite(STDERR, "JINX:  " . json_encode($native) . PHP_EOL);
        $failed++;
        continue;
    }

    echo "PASS: {$family}\n";
    $passed++;
}

echo "BIG-FAMILY STRICT NATIVE PARITY: {$passed}/" . count($fixtures) . "\n";
exit($failed === 0 ? 0 : 1);
