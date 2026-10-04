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

$nestedFixture = $root . '/fixtures/oracle-native-nested-arrays.php';
$nestedPhp = native_source_run([PHP_BINARY, $nestedFixture], false);
$nestedExpected = "{\"a\":{\"x\":{\"n\":1}},\"b\":{\"x\":{\"n\":7}}}\n[{\"x\":{\"y\":[3,4]}},{\"x\":{\"y\":[8,10]}},10]\n";
if ($nestedPhp !== [0, $nestedExpected, '']) throw new RuntimeException('unexpected nested array baseline: ' . json_encode($nestedPhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $nestedFixture], true, $forceNative);
    if ($native !== $nestedPhp) throw new RuntimeException('native nested array parity differs: ' . json_encode($native));
}

$unionFixture = $root . '/fixtures/oracle-native-union-types.php';
$unionPhp = native_source_run([PHP_BINARY, $unionFixture], false);
$unionExpected = "[7,\"x\",\"yes\",null]\n[null,\"ok\"]\nARG:TypeError\nRETURN:TypeError\nNULLABLE:TypeError\n[3,4,6,\"empty\",8,9]\n[2,\"two\"]\n";
$unionExpected .= "YES:TypeError\nNO:TypeError\n";
if ($unionPhp !== [0, $unionExpected, '']) throw new RuntimeException('unexpected union baseline: ' . json_encode($unionPhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $unionFixture], true, $forceNative);
    if ($native !== $unionPhp) throw new RuntimeException('native union/ternary parity differs: ' . json_encode($native));
}

$spreadFixture = $root . '/fixtures/oracle-native-array-spread.php';
$spreadPhp = native_source_run([PHP_BINARY, $spreadFixture], false);
$spreadExpected = "{\"0\":\"zero\",\"a\":2,\"b\":3,\"1\":\"nine\",\"c\":4}\n[8,9,10,11]\n[{\"n\":[1,2]},{\"n\":[9]}]\nERR:Error\n";
if ($spreadPhp !== [0, $spreadExpected, '']) throw new RuntimeException('unexpected spread baseline: ' . json_encode($spreadPhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $spreadFixture], true, $forceNative);
    if ($native !== $spreadPhp) throw new RuntimeException('native array spread parity differs: ' . json_encode($native));
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

$propertyFixture = $root . '/fixtures/oracle-native-properties.php';
$propertyPhp = native_source_run([PHP_BINARY, $propertyFixture], false);
$propertyExpected = "[3,13]\n[8,8,13]\nERR:TypeError\n8\nERR:Error\n[7,20,\"ready\"]\nERR:TypeError\n7\nOUTER:TypeError\nCALLBACK:TypeError\n8\n1\n";
if ($propertyPhp !== [0, $propertyExpected, '']) throw new RuntimeException('unexpected property baseline: ' . json_encode($propertyPhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $propertyFixture], true, $forceNative);
    if ($native !== $propertyPhp) throw new RuntimeException('native property/exception parity differs: ' . json_encode($native));
}

$functionFixture = $root . '/fixtures/oracle-native-functions.php';
$functionPhp = native_source_run([PHP_BINARY, $functionFixture], false);
$functionExpected = "[4,35,90,12]\nARG:TypeError\nCOUNT:ArgumentCountError\nRETURN:TypeError\nNESTED:TypeError\n[9,90,12]\n[\"YES\",true,8,2]\n";
if ($functionPhp !== [0, $functionExpected, '']) throw new RuntimeException('unexpected function baseline: ' . json_encode($functionPhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $functionFixture], true, $forceNative);
    if ($native !== $functionPhp) throw new RuntimeException('native function frame parity differs: ' . json_encode($native));
}

$closureFixture = $root . '/fixtures/oracle-native-closures.php';
$closurePhp = native_source_run([PHP_BINARY, $closureFixture], false);
$closureExpected = "[11,11,12]\n[13,13,30]\n31\nTypeError\n10\n[10,11]\n[7,20]\n";
if ($closurePhp !== [0, $closureExpected, '']) throw new RuntimeException('unexpected closure baseline: ' . json_encode($closurePhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $closureFixture], true, $forceNative);
    if ($native !== $closurePhp) throw new RuntimeException('native closure parity differs: ' . json_encode($native));
}

$cloneFixture = $root . '/fixtures/oracle-native-clone-methods.php';
$clonePhp = native_source_run([PHP_BINARY, $cloneFixture], false);
$cloneExpected = "[3,3,4,4]\n7\nPRIVATE:Error\nMETHOD:Error\nTYPE:TypeError\nMISSING:Error\nNESTED:TypeError\nSTATIC:Error\n9\n[3,13]\n5\n";
if ($clonePhp !== [0, $cloneExpected, '']) throw new RuntimeException('unexpected clone/method baseline: ' . json_encode($clonePhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $cloneFixture], true, $forceNative);
    if ($native !== $clonePhp) throw new RuntimeException('native clone/method parity differs: ' . json_encode($native));
}

$callbackFixture = $root . '/fixtures/oracle-native-callbacks.php';
$callbackPhp = native_source_run([PHP_BINARY, $callbackFixture], false);
$callbackExpected = "{\"values\":[4,8,12],\"sum\":24}\n{\"x\":3,\"4\":6}\n[4,5]\n10\n7\n[\"A\",\"B\"]\n[[],9,null]\n[[2,4],2]\nEMPTY:TypeError\nPRIVATE:TypeError\nTYPE:TypeError\n3\n[4,2]\n[\"3\",\"\"]\n[false,false,true]\n{\"x\":3,\"4\":2}\nSTRICT:TypeError\n";
$callbackExpected .= "[4,-6,8]\nSPACE:TypeError\n[3,8]\n";
if ($callbackPhp !== [0, $callbackExpected, '']) throw new RuntimeException('unexpected callback baseline: ' . json_encode($callbackPhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $callbackFixture], true, $forceNative);
    if ($native !== $callbackPhp) throw new RuntimeException('native callback parity differs: ' . json_encode($native));
}

$constructorFixture = $root . '/fixtures/oracle-native-constructors.php';
$constructorPhp = native_source_run([PHP_BINARY, $constructorFixture], false);
$constructorExpected = "[3,14]\n[7,7]\nARG:TypeError\nCOUNT:ArgumentCountError\nOBJECT:TypeError\nPROPERTY:TypeError\n3\n5\nPRIVATE:Error\n";
$constructorExpected .= "7\nCLASS:TypeError\n";
if ($constructorPhp !== [0, $constructorExpected, '']) throw new RuntimeException('unexpected constructor baseline: ' . json_encode($constructorPhp));
foreach ([true, false] as $forceNative) {
    $native = native_source_run([$binary, $constructorFixture], true, $forceNative);
    if ($native !== $constructorPhp) throw new RuntimeException('native constructor parity differs: ' . json_encode($native));
}

$temporary = tempnam(sys_get_temp_dir(), 'jinx-native-reject-');
if ($temporary === false) throw new RuntimeException('could not create rejection fixture');
try {
    foreach (['int|int', '?int|string', 'int|array'] as $type) {
        file_put_contents($temporary, '<?php declare(strict_types=1); echo "MUST_NOT_RUN"; function nativeRejected(' . $type . ' $value) { return $value; }');
        $rejected = native_source_run([$binary, '--native-php', $temporary], true);
        if ($rejected[0] === 0 || $rejected[1] !== '') throw new RuntimeException('invalid or unsupported native union was not rejected before output');
    }
    file_put_contents($temporary, '<?php echo "MUST_NOT_RUN"; new class {};');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '' || !str_contains($rejected[2], 'refusing PHP fallback')) {
        throw new RuntimeException('unsupported input was not rejected before execution: ' . json_encode($rejected));
    }
    file_put_contents($temporary, '<?php echo "MUST_NOT_RUN"; nativeUnknownFunction();');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '') throw new RuntimeException('unknown function was not rejected before execution');
    file_put_contents($temporary, '<?php echo "MUST_NOT_RUN"; function nativeWeak(int $n): int { return $n; }');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '') throw new RuntimeException('weak function typing was not rejected before execution');
    file_put_contents($temporary, '<?php declare(strict_types=1); $fn = function (): int { return 1; }; $values = [$fn]; echo "MUST_NOT_RUN";');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '' || !str_contains($rejected[2], 'closures in Zend containers')) {
        throw new RuntimeException('unsupported closure container was not rejected: ' . json_encode($rejected));
    }
    file_put_contents($temporary, '<?php declare(strict_types=1); echo "MUST_NOT_RUN"; class NativeDestructor { public function __destruct() {} }');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '') throw new RuntimeException('unsupported magic method was not rejected before output');
    file_put_contents($temporary, '<?php declare(strict_types=1); class NativePrivateParent { private function hidden(): int { return 1; } } class NativePrivateChild extends NativePrivateParent {} echo "MUST_NOT_RUN";');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '' || !str_contains($rejected[2], 'private inheritance layout')) {
        throw new RuntimeException('unsupported private inheritance was not rejected: ' . json_encode($rejected));
    }
    file_put_contents($temporary, '<?php declare(strict_types=1); $values = array_map(fn (int $n): int => $n, ["2.5"]); echo "MUST_NOT_RUN";');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '' || !str_contains($rejected[2], 'decimal coercion')) {
        throw new RuntimeException('unsupported callback coercion was not rejected: ' . json_encode($rejected));
    }
    file_put_contents($temporary, '<?php declare(strict_types=1); $value = call_user_func("strrev", "abc"); echo "MUST_NOT_RUN";');
    $rejected = native_source_run([$binary, '--native-php', $temporary], true);
    if ($rejected[0] === 0 || $rejected[1] !== '' || !str_contains($rejected[2], 'callback builtin is not yet admitted')) {
        throw new RuntimeException('unsupported valid callback was incorrectly classified: ' . json_encode($rejected));
    }
} finally {
    unlink($temporary);
}
echo "PASS: native Oracle source execution matches PHP with no PHP on PATH; includes share variables and return values; unsupported syntax fails before execution\n";
