<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-ninth-unit-cases';

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
    fail('could not create ninth 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $a = $i * 4 + 11;
    $b = ($i % 9) + 3;
    $c = $a - $b;
    add_case(
        $cases,
        sprintf('numeric-window-%03d', $i),
        "\$a = {$a};\n\$b = {$b};\n\$c = {$c};\n\$out = [\n    'case' => {$i},\n    'sum' => \$a + \$b + \$c,\n    'diff' => \$a - \$b - \$c,\n    'mix' => (\$a * 2) + (\$b * 3) - \$c,\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $word = 'ninth-window-' . $i;
    $pad = ($i % 5) + 18;
    add_case(
        $cases,
        sprintf('string-window-%03d', $i),
        "\$value = '{$word}';\n\$out = [\n    'case' => {$i},\n    'head' => substr(\$value, 0, 5),\n    'tail' => substr(\$value, -2),\n    'pad' => str_pad(\$value, {$pad}, '.', STR_PAD_RIGHT),\n    'repeat' => str_repeat((string) ({$i} % 4), 3),\n    'hash' => substr(md5(\$value), 0, 8),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $x = $i + 1;
    $y = $i + 3;
    $z = $i + 5;
    add_case(
        $cases,
        sprintf('array-window-%03d', $i),
        "\$rows = [\n    ['k' => 'a', 'v' => {$x}],\n    ['k' => 'b', 'v' => {$y}],\n    ['k' => 'c', 'v' => {$z}],\n];\n\$sum = 0;\n\$labels = [];\nforeach (\$rows as \$row) {\n    \$sum += \$row['v'];\n    \$labels[] = \$row['k'] . ':' . \$row['v'];\n}\n\$out = ['case' => {$i}, 'sum' => \$sum, 'labels' => implode(',', \$labels), 'count' => count(\$rows)];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 6) + 4;
    add_case(
        $cases,
        sprintf('function-window-%03d', $i),
        "function jinx_ninth_{$i}(int \$limit): array\n{\n    \$total = 0;\n    \$trace = [];\n    for (\$n = 1; \$n <= \$limit; \$n++) {\n        \$total += \$n * {$i};\n        \$trace[] = \$n . ':' . \$total;\n    }\n    return ['limit' => \$limit, 'total' => \$total, 'trace' => implode('|', \$trace)];\n}\necho json_encode(['case' => {$i}, 'result' => jinx_ninth_{$i}({$limit})]) . \"\\n\";"
    );
}

if (count($cases) !== 100) {
    fail('expected exactly 100 ninth unit cases, found ' . count($cases));
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
    fail('expected to check exactly 100 ninth unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX ninth 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
