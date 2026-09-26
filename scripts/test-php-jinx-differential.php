<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx';

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

function normalize_stderr(string $stderr): string
{
    // Keep stderr comparison conservative for now. Paths, native launcher prefixes,
    // and line-reporting can differ while stdout/exit parity is still meaningful.
    return trim(preg_replace('/\s+/', ' ', $stderr) ?? $stderr);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

if (!is_dir($caseDir) && !mkdir($caseDir, 0777, true) && !is_dir($caseDir)) {
    fail('could not create differential case directory: ' . $caseDir);
}

$cases = [
    'straight-line-echo' => <<<'PHP'
<?php
declare(strict_types=1);
$a = 7;
$b = 5;
echo "sum=" . ($a + $b) . "\n";
PHP,
    'string-builtins' => <<<'PHP'
<?php
declare(strict_types=1);
$value = strtoupper(trim('  jinx oracle  '));
echo json_encode(['value' => $value, 'len' => strlen($value)]) . "\n";
PHP,
    'array-builtins' => <<<'PHP'
<?php
declare(strict_types=1);
$values = [3, 5, 8];
echo json_encode(['count' => count($values), 'sum' => array_sum($values), 'product' => array_product($values)]) . "\n";
PHP,
    'json-base64-hash' => <<<'PHP'
<?php
declare(strict_types=1);
$payload = ['name' => 'jinx', 'encoded' => base64_encode('oracle'), 'md5' => md5('oracle')];
echo json_encode($payload) . "\n";
PHP,
    'conditionals-loops' => <<<'PHP'
<?php
declare(strict_types=1);
$out = [];
for ($i = 1; $i <= 5; $i++) {
    $out[] = ($i % 2 === 0) ? "even:$i" : "odd:$i";
}
echo implode('|', $out) . "\n";
PHP,
    'function-call' => <<<'PHP'
<?php
declare(strict_types=1);
function jinx_diff_value(string $name, int $n): string
{
    return strtoupper($name) . ':' . ($n * 3);
}
echo jinx_diff_value('oracle', 14) . "\n";
PHP,
    'object-basics' => <<<'PHP'
<?php
declare(strict_types=1);
final class JinxDiffBox
{
    public function __construct(private string $name) {}
    public function label(): string { return 'box:' . $this->name; }
}
$box = new JinxDiffBox('oracle');
echo $box->label() . "\n";
PHP,
];

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

    if ($phpResult['exit'] !== 0 && normalize_stderr($jinxResult['stderr']) !== normalize_stderr($phpResult['stderr'])) {
        fail("{$name} stderr mismatch\nPHP stderr:\n{$phpResult['stderr']}\nJINX stderr:\n{$jinxResult['stderr']}");
    }

    $checked++;
}

echo 'PASS: PHP vs JINX differential runner matches PHP stdout/exit behavior across ' . $checked . ' deterministic cases' . PHP_EOL;
