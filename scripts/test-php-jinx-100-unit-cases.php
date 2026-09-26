<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-unit-cases';

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
    fail('could not create 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $a = $i + 7;
    $b = ($i % 5) + 2;
    add_case(
        $cases,
        sprintf('numeric-arithmetic-%03d', $i),
        "\$a = {$a};\n\$b = {$b};\necho json_encode(['case' => {$i}, 'value' => ((\$a * \$b) + (\$a % \$b) - \$b)]) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $word = 'jinx-oracle-' . $i;
    $start = $i % 6;
    $length = ($i % 5) + 3;
    add_case(
        $cases,
        sprintf('string-builtins-%03d', $i),
        "\$value = '{$word}';\n\$out = [\n    'upper' => strtoupper(\$value),\n    'lower' => strtolower('JINX-ORACLE-{$i}'),\n    'slice' => substr(\$value, {$start}, {$length}),\n    'replaced' => str_replace('oracle', 'php', \$value),\n    'len' => strlen(\$value),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $x = $i;
    $y = $i + 2;
    $z = $i + 4;
    add_case(
        $cases,
        sprintf('array-builtins-%03d', $i),
        "\$values = [{$x}, {$y}, {$z}];\n\$labels = ['u{$i}', 'v{$i}', 'w{$i}'];\n\$out = [\n    'count' => count(\$values),\n    'sum' => array_sum(\$values),\n    'product' => array_product(\$values),\n    'joined' => implode('|', \$labels),\n    'max' => max(\$values),\n    'min' => min(\$values),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    add_case(
        $cases,
        sprintf('control-function-%03d', $i),
        "function jinx_unit_{$i}(int \$limit): string\n{\n    \$parts = [];\n    for (\$n = 1; \$n <= \$limit; \$n++) {\n        \$parts[] = (\$n % 2 === 0 ? 'even' : 'odd') . ':' . \$n;\n    }\n    return implode(',', \$parts);\n}\necho json_encode(['case' => {$i}, 'trace' => jinx_unit_{$i}({$i % 7 + 3})]) . \"\\n\";"
    );
}

if (count($cases) !== 100) {
    fail('expected exactly 100 unit cases, found ' . count($cases));
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
    fail('expected to check exactly 100 unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
