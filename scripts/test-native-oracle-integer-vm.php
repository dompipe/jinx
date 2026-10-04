<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$directory = $root . '/build/oracle-integer';
if (!is_dir($directory)) mkdir($directory, 0777, true);
$binary = $root . '/build/native/jinx-oracle-int';
function integerVmRun(array $command, bool $native = false): array
{
    $environment = getenv();
    if ($native) {
        $environment['PATH'] = '/jinx-no-php';
        $environment['JINX_NATIVE_ONLY'] = '1';
        $environment['JINX_ORACLE_SCRIPT_RUNNER'] = '/no-bridge';
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Process failed');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}
$source = $directory . '/dynamic.php';
$artifact = $directory . '/dynamic.jxo';
file_put_contents($source, '<?php $sum = $x + $y; return $sum * $y;');
$compiled = integerVmRun([PHP_BINARY, $root . '/scripts/compile-oracle-native.php', $source, $artifact]);
if ($compiled[0]) throw new RuntimeException(json_encode($compiled));
foreach ([[20, 7], [3, 9], [-5, 2], [9007199254740993, 1]] as [$x, $y]) {
    $reference = $directory . '/reference.php';
    file_put_contents($reference, '<?php $x = ' . $x . '; $y = ' . $y . '; echo json_encode(require ' . var_export($source, true) . ');');
    $php = integerVmRun([PHP_BINARY, $reference]);
    $native = integerVmRun([$binary, $artifact, "x=$x", "y=$y"], true);
    $data = json_decode($native[1], true, 512, JSON_THROW_ON_ERROR);
    if ($php[0] || $native[0] || $native[2] !== '' || $data['return'] !== json_decode($php[1], true))
        throw new RuntimeException('Dynamic native parity failed: ' . json_encode([$php, $native]));
}
if (integerVmRun([$binary, $artifact, 'x=1'], true)[0] === 0) throw new RuntimeException('Missing input accepted');
if (integerVmRun([$binary, $artifact, 'x=9223372036854775807', 'y=2'], true)[0] === 0) throw new RuntimeException('Overflow accepted');
foreach (['<?php echo "BAD"; return 1;', '<?php require "other.php"; return 1;', '<?php return strlen($text);'] as $unsupported) {
    file_put_contents($directory . '/unsupported.php', $unsupported);
    $rejected = integerVmRun([PHP_BINARY, $root . '/scripts/compile-oracle-native.php', $directory . '/unsupported.php', $directory . '/unsupported.jxo']);
    if (!$rejected[0]) throw new RuntimeException('Unsupported source accepted');
}
file_put_contents($directory . '/invalid.jxo', "JXOR_INT_1\n1 0 1\nRETURN_ADD 0 0\n");
if (!integerVmRun([$binary, $directory . '/invalid.jxo'], true)[0]) throw new RuntimeException('Uninitialized operand accepted');
echo "PASS: compile once, vary integer runtime inputs, execute without PHP; invalid source/artifacts and overflow reject\n";
