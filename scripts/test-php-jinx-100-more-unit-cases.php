<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-more-unit-cases';

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
    fail('could not create second 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $a = $i + 11;
    $b = ($i % 6) + 3;
    $c = $i * 2;
    add_case(
        $cases,
        sprintf('comparison-boolean-%03d', $i),
        "\$a = {$a};\n\$b = {$b};\n\$c = {$c};\n\$out = [\n    'gt' => \$a > \$b,\n    'lt' => \$b < \$c,\n    'eq' => (\$a - {$a}) === 0,\n    'and' => (\$a > \$b) && (\$c >= \$b),\n    'or' => (\$a < \$b) || (\$c > \$a),\n    'ternary' => \$a > \$c ? 'a' : 'c',\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $left = 'alpha-' . $i;
    $right = 'omega-' . ($i + 50);
    $pad = ($i % 4) + 1;
    add_case(
        $cases,
        sprintf('string-transform-%03d', $i),
        "\$left = '{$left}';\n\$right = '{$right}';\n\$joined = \$left . ':' . \$right;\n\$out = [\n    'trimmed' => trim('  ' . \$joined . '  '),\n    'first' => ucfirst('case{$i}'),\n    'lower_first' => lcfirst('Case{$i}'),\n    'rev' => strrev(\$left),\n    'repeat' => str_repeat('x', {$pad}),\n    'padded' => str_pad((string) {$i}, 4, '0', STR_PAD_LEFT),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $one = $i;
    $two = $i + 10;
    $three = $i + 20;
    add_case(
        $cases,
        sprintf('nested-array-%03d', $i),
        "\$matrix = [\n    ['id' => 'a{$i}', 'value' => {$one}],\n    ['id' => 'b{$i}', 'value' => {$two}],\n    ['id' => 'c{$i}', 'value' => {$three}],\n];\n\$values = [\$matrix[0]['value'], \$matrix[1]['value'], \$matrix[2]['value']];\n\$out = [\n    'first' => \$matrix[0]['id'],\n    'last' => \$matrix[2]['id'],\n    'sum' => array_sum(\$values),\n    'count' => count(\$matrix),\n    'joined' => implode(',', [\$matrix[0]['id'], \$matrix[1]['id'], \$matrix[2]['id']]),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 5) + 4;
    $multiplier = ($i % 4) + 2;
    add_case(
        $cases,
        sprintf('function-branch-%03d', $i),
        "function jinx_more_unit_{$i}(int \$limit, int \$multiplier): array\n{\n    \$sum = 0;\n    \$trace = [];\n    for (\$n = 1; \$n <= \$limit; \$n++) {\n        \$value = \$n * \$multiplier;\n        \$sum += \$value;\n        if (\$value % 3 === 0) {\n            \$trace[] = 'three:' . \$value;\n        } else {\n            \$trace[] = 'other:' . \$value;\n        }\n    }\n    return ['sum' => \$sum, 'trace' => implode('|', \$trace)];\n}\necho json_encode(['case' => {$i}, 'result' => jinx_more_unit_{$i}({$limit}, {$multiplier})]) . \"\\n\";"
    );
}

if (count($cases) !== 100) {
    fail('expected exactly 100 additional unit cases, found ' . count($cases));
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
    fail('expected to check exactly 100 additional unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX second 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
