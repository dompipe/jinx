<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-fourth-unit-cases';

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
    fail('could not create fourth 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 300;
    $left = $seed * 2;
    $right = ($i % 9) + 1;
    add_case(
        $cases,
        sprintf('numeric-normalization-%03d', $i),
        "\$left = {$left};\n\$right = {$right};\n\$out = [\n    'case' => {$seed},\n    'div' => intdiv(\$left, \$right),\n    'mod' => \$left % \$right,\n    'abs' => abs(\$right - \$left),\n    'round' => round((\$left / \$right), 3),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 325;
    $prefix = 'unit' . $seed;
    $pad = ($i % 4) + 2;
    add_case(
        $cases,
        sprintf('string-shape-%03d', $i),
        "\$prefix = '{$prefix}';\n\$value = '  ' . \$prefix . '-JINX-oracle  ';\n\$trimmed = trim(\$value);\n\$out = [\n    'case' => {$seed},\n    'trimmed' => \$trimmed,\n    'upper' => strtoupper(\$trimmed),\n    'lower' => strtolower(\$trimmed),\n    'padded' => str_pad(\$prefix, strlen(\$prefix) + {$pad}, '_'),\n    'repeat' => str_repeat(substr(\$prefix, 0, 2), 3),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 350;
    $a = $i;
    $b = $i + 10;
    $c = $i + 20;
    add_case(
        $cases,
        sprintf('array-map-shape-%03d', $i),
        "\$rows = [\n    ['k' => 'a', 'v' => {$a}],\n    ['k' => 'b', 'v' => {$b}],\n    ['k' => 'c', 'v' => {$c}],\n];\n\$sum = 0;\n\$labels = [];\nforeach (\$rows as \$row) {\n    \$sum += \$row['v'];\n    \$labels[] = \$row['k'] . ':' . \$row['v'];\n}\n\$out = ['case' => {$seed}, 'sum' => \$sum, 'labels' => implode('|', \$labels), 'count' => count(\$rows)];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 375;
    $limit = ($i % 6) + 4;
    add_case(
        $cases,
        sprintf('function-accumulator-%03d', $i),
        "function jinx_fourth_{$i}(int \$limit): array\n{\n    \$total = 0;\n    \$trace = [];\n    for (\$n = 1; \$n <= \$limit; \$n++) {\n        \$total += \$n * {$i};\n        \$trace[] = (\$total % 2 === 0 ? 'e' : 'o') . \$total;\n    }\n    return ['total' => \$total, 'trace' => implode(',', \$trace)];\n}\necho json_encode(['case' => {$seed}, 'result' => jinx_fourth_{$i}({$limit})]) . \"\\n\";"
    );
}

if (count($cases) !== 100) {
    fail('expected exactly 100 fourth unit cases, found ' . count($cases));
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
    fail('expected to check exactly 100 fourth unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX fourth 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
