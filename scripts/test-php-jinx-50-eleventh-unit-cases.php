<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-50-eleventh-unit-cases';

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
    fail('could not create eleventh 50-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 7) + 5;
    add_case(
        $cases,
        sprintf('eleventh-fold-%03d', $i),
        "\$total = 0;\n\$trace = [];\nfor (\$n = 1; \$n <= {$limit}; \$n++) {\n    \$total += \$n + {$i};\n    \$trace[] = \$total;\n}\n\$out = ['case' => {$i}, 'limit' => {$limit}, 'total' => \$total, 'trace' => implode(':', \$trace)];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $word = 'eleventh-' . $i;
    add_case(
        $cases,
        sprintf('eleventh-function-%03d', $i),
        "function jinx_eleventh_{$i}(string \$word): array\n{\n    \$upper = strtoupper(\$word);\n    \$lower = strtolower(\$upper);\n    \$parts = [\$upper, \$lower, strrev(\$word), strlen(\$word)];\n    return ['word' => \$word, 'parts' => \$parts, 'joined' => implode('|', \$parts)];\n}\necho json_encode(['case' => {$i}, 'result' => jinx_eleventh_{$i}('{$word}')]) . \"\\n\";"
    );
}

if (count($cases) !== 50) {
    fail('expected exactly 50 eleventh unit cases, found ' . count($cases));
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

if ($checked !== 50) {
    fail('expected to check exactly 50 eleventh unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX eleventh 50 unit cases match PHP stdout/exit behavior' . PHP_EOL;
