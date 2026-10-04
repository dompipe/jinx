<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$gate = in_array('--gate', $argv, true);
$ledger = json_decode(file_get_contents($root . '/spec/php-replacement-proof-ledger.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = [
    'operators' => [
        'integer-comparison' => 'echo json_encode([2 < 3, 3 <= 3, 4 > 3, 4 >= 4, 3 === 3, 3 !== "3"]), "\n";',
        'logical-short-circuit' => 'function side(): bool { echo "BAD"; return true; } echo json_encode([false && side(), true || side(), !false]), "\n";',
        'loose-comparison' => 'echo json_encode([0 == "0", 0 == "x", null == false, 1 != "1"]), "\n";',
        'division-power' => 'echo json_encode([7 / 2, 2 ** 5]), "\n";',
        'bitwise' => 'echo json_encode([5 & 3, 5 | 2, 5 ^ 1, 1 << 3]), "\n";',
        'power-precedence' => 'echo json_encode([-2 ** 2, 2 ** 3 ** 2, !2 ** 0]);',
        'large-integer-comparison' => 'echo json_encode([9007199254740993 == "9007199254740992", "9007199254740993" > "9007199254740992"]);',
        'large-power' => 'echo json_encode([3 ** 34, gettype(3 ** 34)]);',
        'large-exact-division' => 'echo json_encode([9007199254740993 / 1, gettype(9007199254740993 / 1)]);',
        'float-container-roundtrip' => '$values = [7 / 2]; echo json_encode([$values[0], $values[0] > 3]);',
        'shift-errors' => 'try { $value = 1 << -1; } catch (ArithmeticError $e) { echo $e::class; }',
    ],
    'control-flow' => [
        'if-else' => '$a = 3; if ($a > 2) { echo "yes"; } else { echo "no"; }',
        'elseif' => '$a = 2; if ($a === 1) { echo "one"; } elseif ($a === 2) { echo "two"; } else { echo "other"; }',
        'while' => '$i = 0; $sum = 0; while ($i < 4) { $sum += $i; $i++; } echo $sum;',
        'for' => '$sum = 0; for ($i = 0; $i < 4; $i++) { $sum += $i; } echo $sum;',
        'break-continue' => '$i = 0; $sum = 0; while ($i < 8) { $i++; if ($i === 2) { continue; } if ($i === 5) { break; } $sum += $i; } echo $sum;',
        'do-while' => '$i = 0; do { $i++; } while ($i < 3); echo $i;',
        'switch' => '$a = 2; switch ($a) { case 1: echo "one"; break; case 2: echo "two"; break; default: echo "other"; }',
        'application-array-filter' => '$rows = [2, 5, 1, 8]; $result = []; foreach ($rows as $row) { if ($row > 3) { $result[] = $row * 2; } } echo json_encode($result);',
        'nested-loop-control' => '$i = 0; $sum = 0; while ($i < 3) { $i++; foreach ([1, 2, 3] as $n) { if ($n === 2) { continue; } if ($n === 3) { break; } $sum += $n; } } echo $sum;',
        'finally-break' => '$i = 0; while (true) { try { break; } finally { $i++; } } echo $i;',
        'function-return-in-loop' => 'function value(): int { $i = 0; while ($i < 5) { $i++; if ($i === 3) { return $i; } } return 0; } echo value();',
        'lazy-branches' => 'function side(): bool { echo "BAD"; return true; } if (true) { echo "ok"; } elseif (side()) { echo "BAD"; } else { echo "BAD"; }',
        'switch-fallthrough' => '$n = 2; switch ($n) { default: echo "d"; case 2: echo "two"; case 3: echo "three"; break; }',
        'do-break' => '$i = 0; do { $i++; break; } while (true); echo $i;',
    ],
    'builtins' => [
        'string-replace' => 'echo str_replace("a", "x", "banana");',
        'array-keys' => 'echo json_encode(array_keys(["a" => 1, "b" => 2]));',
        'json-decode' => '$value = json_decode("{\"a\":7}", true); echo $value["a"];',
        'sorting' => '$values = [3, 1, 2]; sort($values); echo json_encode($values);',
    ],
    'objects' => [
        'interfaces' => 'interface Label { public function label(): string; } class Box implements Label { public function label(): string { return "box"; } } echo (new Box())->label();',
        'traits' => 'trait Label { public function label(): string { return "box"; } } class Box { use Label; } echo (new Box())->label();',
        'destructors' => 'class Box { public function __destruct() { echo "done"; } } $box = new Box(); unset($box);',
        'instanceof' => 'class Box {} $box = new Box(); echo json_encode($box instanceof Box);',
    ],
    'generators' => [
        'nested-loop' => 'function values(): Generator { foreach ([1, 2] as $value) { yield $value; } } echo json_encode(iterator_to_array(values()));',
        'finally' => 'function values(): Generator { try { yield 1; } finally { echo "done"; } } echo json_encode(iterator_to_array(values()));',
    ],
    'namespaces' => [
        'group-import' => 'namespace A { class Box {} function value(): int { return 7; } } namespace B { use A\{Box, function value}; echo value(); }',
        'global-constant-fallback' => 'namespace { const LABEL = "global"; } namespace A { echo LABEL; }',
    ],
    'errors' => [
        'division-zero' => 'try { $value = 1 / 0; } catch (DivisionByZeroError $e) { echo $e::class; }',
        'throw-variable' => '$error = new RuntimeException("failure"); try { throw $error; } catch (RuntimeException $e) { echo $e::class; }',
    ],
    'weak-typing' => [
        'scalar-coercion' => 'function value(int $number): int { return $number + 1; } echo value("4");',
        'untyped-functions' => 'function value($number) { return $number + 1; } echo value(4);',
    ],
];

function replacementRun(array $command, string $root, ?array $environment = null): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start audit process');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = $stderr = '';
    $deadline = microtime(true) + 5;
    $timedOut = false;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (microtime(true) >= $deadline || strlen($stdout) + strlen($stderr) > 1048576) {
            $timedOut = true;
            proc_terminate($process, 9);
            break;
        }
        usleep(1000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    return ['exit' => $timedOut ? 124 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $closed), 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
}

$directory = $root . '/build/differential/php-replacement';
if (!is_dir($directory) && !mkdir($directory, 0777, true)) throw new RuntimeException('Could not create fixtures');
$environment = getenv();
$environment['PATH'] = '/jinx-test-no-executables';
$environment['JINX_NATIVE_ONLY'] = '1';
$environment['JINX_ORACLE_SCRIPT_RUNNER'] = '/jinx-test-bridge-must-not-run';
$report = ['contract' => 'Bounded replacement audit; not an estimate of total PHP compatibility.', 'php_version' => PHP_VERSION, 'families' => [], 'passed' => 0, 'total' => 0];
foreach ($cases as $family => $fixtures) {
    $summary = ['passed' => 0, 'total' => count($fixtures), 'cases' => []];
    foreach ($fixtures as $name => $source) {
        $path = $directory . '/' . $family . '-' . $name . '.php';
        file_put_contents($path, "<?php\n" . ($family === 'weak-typing' ? '' : "declare(strict_types=1);\n") . $source . "\n");
        $php = replacementRun([PHP_BINARY, $path], $root);
        $native = replacementRun([$root . '/jinx', '--native-php', $path], $root, $environment);
        $status = $php['exit'] !== 0 ? 'baseline-error' : ($php === $native ? 'pass' : 'native-failure');
        $summary['cases'][$name] = ['status' => $status, 'php' => $php, 'native' => $native];
        if ($status === 'pass') $summary['passed']++;
        else echo $status . ': ' . $family . '/' . $name . PHP_EOL;
    }
    $report['families'][$family] = $summary;
    $report['passed'] += $summary['passed'];
    $report['total'] += $summary['total'];
    echo $family . ': ' . $summary['passed'] . '/' . $summary['total'] . PHP_EOL;
}
if (!is_dir($root . '/build/audits')) mkdir($root . '/build/audits', 0777, true);
file_put_contents($root . '/build/audits/php-replacement.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
echo 'REPLACEMENT AUDIT: ' . $report['passed'] . '/' . $report['total'] . PHP_EOL;
$required = 0;
$regressions = 0;
foreach ($ledger['required'] as $family => $names) {
    foreach ($names as $name) {
        $required++;
        if (($report['families'][$family]['cases'][$name]['status'] ?? null) !== 'pass') {
            $regressions++;
            fwrite(STDERR, 'REGRESSION: ' . $family . '/' . $name . PHP_EOL);
        }
    }
}
echo 'REPLACEMENT REGRESSION GATE: ' . ($required - $regressions) . '/' . $required . PHP_EOL;
if ($gate) exit($regressions === 0 ? 0 : 1);
exit($report['passed'] === $report['total'] ? 0 : 1);
