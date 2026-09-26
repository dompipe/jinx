<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-44-final-unit-cases';

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
    fail('could not create final 44-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 11; $i++) {
    $left = $i + 64;
    $right = ($i % 7) + 5;
    add_case(
        $cases,
        sprintf('final-numeric-close-%03d', $i),
        "\$left = {$left};\n\$right = {$right};\n\$values = [];\nfor (\$n = 1; \$n <= 4; \$n++) {\n    \$values[] = (\$left - \$n) + (\$right * \$n);\n}\necho json_encode(['case' => {$i}, 'max' => max(\$values), 'min' => min(\$values), 'sum' => array_sum(\$values)]) . \"\\n\";"
    );
}

for ($i = 1; $i <= 11; $i++) {
    $seed = 'closing-jinx-case-' . $i;
    $tailLength = ($i % 5) + 3;
    add_case(
        $cases,
        sprintf('final-string-close-%03d', $i),
        "\$seed = '{$seed}';\n\$parts = [\n    substr(\$seed, 0, 7),\n    strtoupper(substr(\$seed, -{$tailLength})),\n    strrev(substr(\$seed, 2, 6)),\n];\necho json_encode(['case' => {$i}, 'text' => implode('|', \$parts), 'len' => strlen(implode('', \$parts))]) . \"\\n\";"
    );
}

for ($i = 1; $i <= 11; $i++) {
    $base = $i * 2;
    add_case(
        $cases,
        sprintf('final-array-close-%03d', $i),
        "\$items = [\n    'first' => {$base},\n    'second' => {$base} + 5,\n    'third' => {$base} + 10,\n];\n\$trace = [];\nforeach (\$items as \$key => \$value) {\n    \$trace[] = \$key . '=' . (\$value % 3);\n}\necho json_encode(['case' => {$i}, 'count' => count(\$items), 'trace' => implode(',', \$trace), 'total' => array_sum(\$items)]) . \"\\n\";"
    );
}

for ($i = 1; $i <= 11; $i++) {
    $limit = ($i % 5) + 5;
    add_case(
        $cases,
        sprintf('final-function-close-%03d', $i),
        "function jinx_final_{$i}(int \$limit): string\n{\n    \$out = [];\n    for (\$n = 1; \$n <= \$limit; \$n++) {\n        \$label = \$n % 3 === 0 ? 'tri' : (\$n % 2 === 0 ? 'even' : 'odd');\n        \$out[] = \$label . ':' . ({$i} + \$n);\n    }\n    return implode(';', \$out);\n}\necho json_encode(['case' => {$i}, 'trace' => jinx_final_{$i}({$limit})]) . \"\\n\";"
    );
}

if (count($cases) !== 44) {
    fail('expected exactly 44 final unit cases, found ' . count($cases));
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

if ($checked !== 44) {
    fail('expected to check exactly 44 final unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX final 44 unit cases match PHP stdout/exit behavior' . PHP_EOL;
