<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-third-unit-cases';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function run_process(array $command): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        fail('could not start process: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($process);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/** @param array<string,string> $cases */
function add_case(array &$cases, string $name, string $body): void
{
    if (isset($cases[$name])) {
        fail('duplicate unit case: ' . $name);
    }

    $cases[$name] = "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n";
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

if (!is_dir($caseDir) && !mkdir($caseDir, 0777, true) && !is_dir($caseDir)) {
    fail('could not create third 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $left = $i * 3;
    $right = $i + 11;
    $divisor = ($i % 6) + 2;
    $body = '$left = ' . $left . ";\n"
        . '$right = ' . $right . ";\n"
        . '$divisor = ' . $divisor . ";\n"
        . '$out = [' . "\n"
        . "    'gt' => \$left > \$right,\n"
        . "    'gte' => \$left >= \$right,\n"
        . "    'lt' => \$left < \$right,\n"
        . "    'eq' => \$left === \$right,\n"
        . "    'rem' => \$left % \$divisor,\n"
        . "    'mix' => ((\$left > \$right) && ((\$left % \$divisor) >= 0)) ? 'yes' : 'no',\n"
        . "];\n"
        . "echo json_encode(\$out) . \"\\n\";";
    add_case($cases, sprintf('comparison-mix-%03d', $i), $body);
}

for ($i = 1; $i <= 25; $i++) {
    $seed = ' third-unit-' . $i . ' ';
    $pad = ($i % 4) + 2;
    $body = '$seed = ' . var_export($seed, true) . ";\n"
        . '$trimmed = trim($seed);' . "\n"
        . '$out = [' . "\n"
        . "    'trimmed' => \$trimmed,\n"
        . "    'upper' => strtoupper(\$trimmed),\n"
        . "    'rev' => strrev(\$trimmed),\n"
        . "    'repeat' => str_repeat(substr(\$trimmed, 0, 2), " . $pad . "),\n"
        . "    'pad' => str_pad((string)" . $i . ", 4, '0', STR_PAD_LEFT),\n"
        . "];\n"
        . "echo json_encode(\$out) . \"\\n\";";
    add_case($cases, sprintf('string-composition-%03d', $i), $body);
}

for ($i = 1; $i <= 25; $i++) {
    $a = $i;
    $b = $i + 10;
    $c = $i + 20;
    $body = '$rows = [' . "\n"
        . "    ['name' => 'a" . $i . "', 'value' => " . $a . "],\n"
        . "    ['name' => 'b" . $i . "', 'value' => " . $b . "],\n"
        . "    ['name' => 'c" . $i . "', 'value' => " . $c . "],\n"
        . "];\n"
        . '$sum = 0;' . "\n"
        . '$names = [];' . "\n"
        . 'for ($idx = 0; $idx < count($rows); $idx++) {' . "\n"
        . '    $sum += $rows[$idx][\'value\'];' . "\n"
        . '    $names[] = $rows[$idx][\'name\'];' . "\n"
        . "}\n"
        . '$out = [' . "\n"
        . "    'sum' => \$sum,\n"
        . "    'names' => implode(',', \$names),\n"
        . "    'first' => \$rows[0]['value'],\n"
        . "    'last' => \$rows[2]['value'],\n"
        . "];\n"
        . "echo json_encode(\$out) . \"\\n\";";
    add_case($cases, sprintf('nested-array-loop-%03d', $i), $body);
}

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 5) + 4;
    $body = 'function jinx_third_unit_' . $i . '(int $limit): array' . "\n"
        . "{\n"
        . '    $total = 0;' . "\n"
        . '    $labels = [];' . "\n"
        . '    for ($n = 1; $n <= $limit; $n++) {' . "\n"
        . '        if ($n % 3 === 0) {' . "\n"
        . "            \$labels[] = 'tri:' . \$n;\n"
        . "        } elseif (\$n % 2 === 0) {\n"
        . "            \$labels[] = 'even:' . \$n;\n"
        . "        } else {\n"
        . "            \$labels[] = 'odd:' . \$n;\n"
        . "        }\n"
        . '        $total += $n;' . "\n"
        . "    }\n"
        . "\n"
        . "    return ['total' => \$total, 'labels' => implode('|', \$labels)];\n"
        . "}\n"
        . '$out = jinx_third_unit_' . $i . '(' . $limit . ');' . "\n"
        . '$out[\'case\'] = ' . $i . ';' . "\n"
        . "echo json_encode(\$out) . \"\\n\";";
    add_case($cases, sprintf('function-branch-array-%03d', $i), $body);
}

if (count($cases) !== 100) {
    fail('expected exactly 100 third unit cases, found ' . count($cases));
}

$checked = 0;
foreach ($cases as $name => $source) {
    $fixture = $caseDir . '/' . $name . '.php';
    file_put_contents($fixture, $source);

    $phpResult = run_process([$php, $fixture]);
    $jinxResult = run_process([$jinx, $fixture]);

    if ($jinxResult['exit'] !== $phpResult['exit']) {
        fail("{$name} exit mismatch: PHP={$phpResult['exit']} JINX={$jinxResult['exit']}\nPHP stdout:\n{$phpResult['stdout']}\nJINX stdout:\n{$jinxResult['stdout']}\nPHP stderr:\n{$phpResult['stderr']}\nJINX stderr:\n{$jinxResult['stderr']}");
    }

    if ($jinxResult['stdout'] !== $phpResult['stdout']) {
        fail("{$name} stdout mismatch\nPHP stdout:\n{$phpResult['stdout']}\nJINX stdout:\n{$jinxResult['stdout']}\nPHP stderr:\n{$phpResult['stderr']}\nJINX stderr:\n{$jinxResult['stderr']}");
    }

    $checked++;
}

if ($checked !== 100) {
    fail('expected to check exactly 100 third unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX third 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
