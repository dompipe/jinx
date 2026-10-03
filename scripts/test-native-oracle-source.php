<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$binary = $root . '/jinx';
$fixture = $root . '/fixtures/oracle-native-source.php';

function native_source_run(array $command, bool $withoutPhp, bool $forceNative = true): array
{
    $environment = getenv();
    if ($withoutPhp) {
        $environment['PATH'] = '/jinx-test-no-executables';
        if ($forceNative) $environment['JINX_NATIVE_ONLY'] = '1';
        else unset($environment['JINX_NATIVE_ONLY']);
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

$edgeFixture = $root . '/fixtures/oracle-native-edge-source.php';
$edgePhp = native_source_run([PHP_BINARY, $edgeFixture], false);
$edgeExpected = "{\"local\":7,\"result\":21}\n[1,true,1]\n{\"data\":\"abc\",\"exists\":false}\n{\"type\":\"boolean\",\"value\":false}\n";
if ($edgePhp !== [0, $edgeExpected, '']) throw new RuntimeException('unexpected edge baseline: ' . json_encode($edgePhp));
foreach ([[$binary, '--native-php', $edgeFixture], [$binary, $edgeFixture]] as $command) {
    $native = native_source_run($command, true);
    if ($native !== $edgePhp) throw new RuntimeException('native include/filesystem edge parity differs: ' . json_encode($native));
}
$defaultNative = native_source_run([$binary, $edgeFixture], true, false);
if ($defaultNative !== $edgePhp) throw new RuntimeException('default native routing edge parity differs: ' . json_encode($defaultNative));

$referenceFixture = $root . '/fixtures/oracle-native-references.php';
$referencePhp = native_source_run([PHP_BINARY, $referenceFixture], false);
$referenceExpected = "{\"a\":{\"n\":6,\"m\":6},\"b\":6}\n[2,4,6]\n[5,20]\n[{\"n\":1},{\"n\":9}]\n[8,31,90]\n{\"n\":12,\"a\":3,\"b\":4,\"c\":5,\"d\":6}\n{\"1\":\"bool\",\"\":\"empty-string\"}\n";
if ($referencePhp !== [0, $referenceExpected, '']) throw new RuntimeException('unexpected reference baseline: ' . json_encode($referencePhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $referenceFixture], true, $forceNative);
    if ($native !== $referencePhp) throw new RuntimeException('native reference parity differs: ' . json_encode($native));
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
