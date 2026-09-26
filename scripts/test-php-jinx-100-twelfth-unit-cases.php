<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-twelfth-unit-cases';

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
    fail('could not create twelfth 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $a = $i + 41;
    $b = ($i % 9) + 3;
    $c = ($i % 4) + 2;
    add_case(
        $cases,
        sprintf('twelfth-numeric-fold-%03d', $i),
        "\$a = {$a};\n\$b = {$b};\n\$c = {$c};\n\$out = [];\nfor (\$n = 0; \$n < \$c; \$n++) {\n    \$out[] = (\$a + \$n) * \$b - (\$n % 2);\n}\necho json_encode(['case' => {$i}, 'sum' => array_sum(\$out), 'last' => \$out[count(\$out) - 1]]) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $seed = 'jinx-final-window-' . $i;
    $pad = ($i % 4) + 16;
    add_case(
        $cases,
        sprintf('twelfth-string-window-%03d', $i),
        "\$seed = '{$seed}';\n\$upper = strtoupper(\$seed);\n\$padded = str_pad(\$upper, {$pad}, '.');\n\$out = [\n    'head' => substr(\$padded, 0, 6),\n    'tail' => substr(\$padded, -4),\n    'rev' => substr(strrev(\$seed), 0, 5),\n    'hash' => substr(md5(\$seed), 0, 8),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $base = $i + 5;
    add_case(
        $cases,
        sprintf('twelfth-array-fold-%03d', $i),
        "\$rows = [\n    ['name' => 'a{$i}', 'value' => {$base}],\n    ['name' => 'b{$i}', 'value' => {$base} + 3],\n    ['name' => 'c{$i}', 'value' => {$base} + 6],\n];\n\$total = 0;\n\$names = [];\nforeach (\$rows as \$row) {\n    \$total += \$row['value'];\n    \$names[] = \$row['name'];\n}\necho json_encode(['case' => {$i}, 'total' => \$total, 'names' => implode(':', \$names)]) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 6) + 4;
    add_case(
        $cases,
        sprintf('twelfth-function-fold-%03d', $i),
        "function jinx_twelfth_{$i}(int \$limit): array\n{\n    \$acc = 0;\n    \$trace = [];\n    for (\$n = 1; \$n <= \$limit; \$n++) {\n        \$acc += \$n * {$i};\n        \$trace[] = (\$acc % 2 === 0 ? 'e' : 'o') . \$n;\n    }\n    return ['acc' => \$acc, 'trace' => implode('|', \$trace)];\n}\necho json_encode(['case' => {$i}, 'result' => jinx_twelfth_{$i}({$limit})]) . \"\\n\";"
    );
}

if (count($cases) !== 100) {
    fail('expected exactly 100 twelfth unit cases, found ' . count($cases));
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
    fail('expected to check exactly 100 twelfth unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX twelfth 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
