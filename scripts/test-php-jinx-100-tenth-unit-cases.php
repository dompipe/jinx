<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-tenth-unit-cases';

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
    fail('could not create tenth 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $left = $i + 10;
    $right = ($i * 2) + 1;
    add_case(
        $cases,
        sprintf('compare-grid-%03d', $i),
        "\$left = {$left};\n\$right = {$right};\n\$out = [\n    'case' => {$i},\n    'gt' => \$left > \$right,\n    'lte' => \$left <= \$right,\n    'same' => \$left === \$right,\n    'choice' => (\$left > \$right ? 'left' : 'right'),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $text = 'tenth-case-' . $i . '-oracle';
    add_case(
        $cases,
        sprintf('string-grid-%03d', $i),
        "\$text = '{$text}';\n\$pieces = [strtoupper(substr(\$text, 0, 5)), strtolower(substr(\$text, -6)), strrev((string) {$i})];\n\$out = [\n    'case' => {$i},\n    'pieces' => \$pieces,\n    'joined' => implode(':', \$pieces),\n    'encoded' => base64_encode(implode('|', \$pieces)),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $base = $i * 3;
    add_case(
        $cases,
        sprintf('array-grid-%03d', $i),
        "\$map = [\n    'alpha' => {$base},\n    'beta' => {$base} + 2,\n    'gamma' => {$base} + 4,\n];\n\$keys = [];\n\$values = [];\nforeach (\$map as \$key => \$value) {\n    \$keys[] = \$key;\n    \$values[] = \$value;\n}\n\$out = ['case' => {$i}, 'keys' => implode(',', \$keys), 'sum' => array_sum(\$values), 'max' => max(\$values), 'min' => min(\$values)];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $limit = ($i % 8) + 2;
    add_case(
        $cases,
        sprintf('function-grid-%03d', $i),
        "function jinx_tenth_{$i}(int \$limit): string\n{\n    \$parts = [];\n    \$state = {$i};\n    for (\$n = 0; \$n < \$limit; \$n++) {\n        \$state += \$n + {$i};\n        \$parts[] = (\$state % 2 === 0 ? 'e' : 'o') . \$state;\n    }\n    return implode('-', \$parts);\n}\necho json_encode(['case' => {$i}, 'trace' => jinx_tenth_{$i}({$limit})]) . \"\\n\";"
    );
}

if (count($cases) !== 100) {
    fail('expected exactly 100 tenth unit cases, found ' . count($cases));
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
    fail('expected to check exactly 100 tenth unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX tenth 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
