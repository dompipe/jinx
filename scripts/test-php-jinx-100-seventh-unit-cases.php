<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-seventh-unit-cases';

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
    fail('could not create php-vs-jinx-100-seventh-unit-cases differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $a = $i + 41;
    $b = ($i % 7) + 5;
    $c = ($i * 3) + 1;
    add_case(
        $cases,
        sprintf('numeric-fold-%03d', $i),
        '$values = [' . $a . ', ' . $b . ', ' . $c . '];' . "\n" .
        '$total = array_sum($values);' . "\n" .
        '$out = ["case" => ' . $i . ', "total" => $total, "rounded" => round($total / ' . $b . ', 2)];' . "\n" .
        'echo json_encode($out) . "\n";'
    );
}

for ($i = 1; $i <= 25; $i++) {
    $word = 'seventh-jinx-' . $i;
    $pad = ($i % 4) + 14;
    add_case(
        $cases,
        sprintf('string-fold-%03d', $i),
        '$word = "' . $word . '";' . "\n" .
        '$out = [' . "\n" .
        '    "pad" => str_pad($word, ' . $pad . ', "."),' . "\n" .
        '    "rev" => strrev($word),' . "\n" .
        '    "first" => ucfirst($word),' . "\n" .
        '    "hash" => substr(md5($word), 0, 8),' . "\n" .
        '];' . "\n" .
        'echo json_encode($out) . "\n";'
    );
}

for ($i = 1; $i <= 25; $i++) {
    $x = $i + 2;
    $y = $i + 5;
    $z = $i + 8;
    add_case(
        $cases,
        sprintf('array-fold-%03d', $i),
        '$values = ["x" => ' . $x . ', "y" => ' . $y . ', "z" => ' . $z . '];' . "\n" .
        '$keys = [];' . "\n" .
        '$weighted = 0;' . "\n" .
        '$n = 1;' . "\n" .
        'foreach ($values as $key => $value) {' . "\n" .
        '    $keys[] = $key;' . "\n" .
        '    $weighted += $value * $n;' . "\n" .
        '    $n++;' . "\n" .
        '}' . "\n" .
        'echo json_encode(["keys" => implode("-", $keys), "weighted" => $weighted]) . "\n";'
    );
}

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 8) + 2;
    add_case(
        $cases,
        sprintf('function-fold-%03d', $i),
        'function jinx_seventh_case_' . $i . '(int $limit): string' . "\n" .
        '{' . "\n" .
        '    $total = 0;' . "\n" .
        '    for ($n = 1; $n <= $limit; $n++) {' . "\n" .
        '        $total += ($n % 2 === 0) ? ($n * 2) : $n;' . "\n" .
        '    }' . "\n" .
        '    return "limit:" . $limit . ":total:" . $total;' . "\n" .
        '}' . "\n" .
        'echo json_encode(["case" => ' . $i . ', "result" => jinx_seventh_case_' . $i . '(' . $limit . ')]) . "\n";'
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

echo 'PASS: PHP vs JINX seventh 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
