<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-sixth-unit-cases';

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
    fail('could not create php-vs-jinx-100-sixth-unit-cases differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $a = $i + 11;
    $b = ($i % 9) + 3;
    $c = ($i * 2) + 5;
    add_case(
        $cases,
        sprintf('numeric-state-%03d', $i),
        '$values = [' . $a . ', ' . $b . ', ' . $c . '];' . "\n" .
        '$total = 0;' . "\n" .
        'foreach ($values as $idx => $value) {' . "\n" .
        '    $total += ($value * ($idx + 1));' . "\n" .
        '}' . "\n" .
        'echo json_encode(["case" => ' . $i . ', "total" => $total, "mod" => ($total % ' . $b . ')]) . "\n";'
    );
}

for ($i = 1; $i <= 25; $i++) {
    $needle = ($i % 2 === 0) ? 'oracle' : 'jinx';
    $suffix = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    add_case(
        $cases,
        sprintf('string-state-%03d', $i),
        '$text = "jinx-oracle-runtime-' . $suffix . '";' . "\n" .
        '$needle = "' . $needle . '";' . "\n" .
        '$out = [' . "\n" .
        '    "needle" => $needle,' . "\n" .
        '    "has" => str_contains($text, $needle),' . "\n" .
        '    "prefix" => substr($text, 0, 4),' . "\n" .
        '    "tail" => substr($text, -3),' . "\n" .
        '    "swap" => str_replace("-", "_", $text),' . "\n" .
        '];' . "\n" .
        'echo json_encode($out) . "\n";'
    );
}

for ($i = 1; $i <= 25; $i++) {
    $base = $i + 20;
    add_case(
        $cases,
        sprintf('array-state-%03d', $i),
        '$rows = [' . "\n" .
        '    ["name" => "a", "value" => ' . $base . '],' . "\n" .
        '    ["name" => "b", "value" => ' . ($base + 3) . '],' . "\n" .
        '    ["name" => "c", "value" => ' . ($base + 6) . '],' . "\n" .
        '];' . "\n" .
        '$names = [];' . "\n" .
        '$sum = 0;' . "\n" .
        'foreach ($rows as $row) {' . "\n" .
        '    $names[] = $row["name"];' . "\n" .
        '    $sum += $row["value"];' . "\n" .
        '}' . "\n" .
        'echo json_encode(["names" => implode("", $names), "sum" => $sum, "count" => count($rows)]) . "\n";'
    );
}

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 6) + 4;
    add_case(
        $cases,
        sprintf('function-state-%03d', $i),
        'function jinx_unit_case_' . $i . '(int $limit): array' . "\n" .
        '{' . "\n" .
        '    $acc = [];' . "\n" .
        '    for ($n = 1; $n <= $limit; $n++) {' . "\n" .
        '        $acc[] = ["n" => $n, "kind" => ($n % 3 === 0 ? "tri" : "plain")];' . "\n" .
        '    }' . "\n" .
        '    return $acc;' . "\n" .
        '}' . "\n" .
        '$rows = jinx_unit_case_' . $i . '(' . $limit . ');' . "\n" .
        '$last = $rows[count($rows) - 1];' . "\n" .
        'echo json_encode(["case" => ' . $i . ', "count" => count($rows), "last" => $last]) . "\n";'
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

echo 'PASS: PHP vs JINX sixth 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
