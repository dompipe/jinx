<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-100-fifth-unit-cases';

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
    fail('could not create fifth 100-unit differential case directory: ' . $caseDir);
}

$cases = [];

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 400;
    $base = $i * 3;
    add_case(
        $cases,
        sprintf('ternary-switching-%03d', $i),
        "\$base = {$base};\n\$status = \$base > 40 ? 'large' : (\$base % 2 === 0 ? 'even' : 'odd');\n\$out = [\n    'case' => {$seed},\n    'status' => \$status,\n    'bools' => [\$base > 10, \$base < 90, \$base === {$base}],\n    'value' => \$base + (\$status === 'large' ? 100 : 10),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 425;
    $text = 'jinx fifth unit ' . $seed;
    $needle = ($i % 2 === 0) ? 'unit' : 'jinx';
    add_case(
        $cases,
        sprintf('string-search-shape-%03d', $i),
        "\$text = '{$text}';\n\$needle = '{$needle}';\n\$pos = strpos(\$text, \$needle);\n\$out = [\n    'case' => {$seed},\n    'pos' => \$pos,\n    'found' => \$pos !== false,\n    'prefix' => substr(\$text, 0, 4),\n    'reverse' => strrev(substr(\$text, -4)),\n    'hash' => substr(md5(\$text), 0, 8),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 450;
    $one = $i + 1;
    $two = $i + 2;
    $three = $i + 3;
    add_case(
        $cases,
        sprintf('array-filter-build-%03d', $i),
        "\$values = [{$one}, {$two}, {$three}, {$three} + {$two}];\n\$kept = [];\nforeach (\$values as \$value) {\n    if (\$value % 2 === {$i} % 2) {\n        \$kept[] = \$value;\n    }\n}\n\$out = [\n    'case' => {$seed},\n    'values' => \$values,\n    'kept' => \$kept,\n    'sum' => array_sum(\$kept),\n    'joined' => implode(',', \$kept),\n];\necho json_encode(\$out) . \"\\n\";"
    );
}

for ($i = 1; $i <= 25; $i++) {
    $seed = $i + 475;
    $limit = ($i % 5) + 5;
    add_case(
        $cases,
        sprintf('function-nested-branch-%03d', $i),
        "function jinx_fifth_{$i}(int \$limit): array\n{\n    \$items = [];\n    \$total = 0;\n    for (\$n = 1; \$n <= \$limit; \$n++) {\n        \$value = \$n * {$i};\n        \$kind = \$value % 3 === 0 ? 'tri' : (\$value % 2 === 0 ? 'even' : 'odd');\n        \$items[] = \$kind . ':' . \$value;\n        \$total += \$value;\n    }\n    return ['limit' => \$limit, 'items' => \$items, 'total' => \$total];\n}\necho json_encode(['case' => {$seed}, 'result' => jinx_fifth_{$i}({$limit})]) . \"\\n\";"
    );
}

if (count($cases) !== 100) {
    fail('expected exactly 100 fifth unit cases, found ' . count($cases));
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
    fail('expected to check exactly 100 fifth unit cases, checked ' . $checked);
}

echo 'PASS: PHP vs JINX fifth 100 unit cases match PHP stdout/exit behavior' . PHP_EOL;
