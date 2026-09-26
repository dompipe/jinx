<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleJinxWebExecutionFamilies.php';
require_once dirname(__DIR__) . '/runtime/OracleMergedExecutionFamilies.php';

use jinx\oracle\OracleMergedExecutionFamilies;

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$php = PHP_BINARY;
$caseDir = $root . '/build/differential/php-vs-jinx-callables';

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

/** @return array<string,string> */
function safe_callable_cases(): array
{
    return [
        'abs' => "echo json_encode(abs(-42)) . \"\\n\";",
        'array_product' => "echo json_encode(array_product([2, 3, 5])) . \"\\n\";",
        'array_sum' => "echo json_encode(array_sum([2, 3, 5])) . \"\\n\";",
        'base64_encode' => "echo json_encode(base64_encode('jinx-oracle')) . \"\\n\";",
        'count' => "echo json_encode(count(['a', 'b', 'c'])) . \"\\n\";",
        'implode' => "echo json_encode(implode('-', ['jinx', 'oracle', 'php'])) . \"\\n\";",
        'json_encode' => "echo json_encode(['name' => 'jinx', 'ok' => true, 'n' => 7]) . \"\\n\";",
        'lcfirst' => "echo json_encode(lcfirst('JinxOracle')) . \"\\n\";",
        'max' => "echo json_encode(max([7, 3, 14, 9])) . \"\\n\";",
        'md5' => "echo json_encode(md5('jinx-oracle')) . \"\\n\";",
        'min' => "echo json_encode(min([7, 3, 14, 9])) . \"\\n\";",
        'round' => "echo json_encode(round(12.55, 1)) . \"\\n\";",
        'sha1' => "echo json_encode(sha1('jinx-oracle')) . \"\\n\";",
        'str_pad' => "echo json_encode(str_pad('42', 6, '0', STR_PAD_LEFT)) . \"\\n\";",
        'str_repeat' => "echo json_encode(str_repeat('jx', 3)) . \"\\n\";",
        'str_replace' => "echo json_encode(str_replace('php', 'jinx', 'php-oracle-php')) . \"\\n\";",
        'strrev' => "echo json_encode(strrev('jinx-oracle')) . \"\\n\";",
        'strlen' => "echo json_encode(strlen('jinx-oracle')) . \"\\n\";",
        'strtolower' => "echo json_encode(strtolower('JINX-ORACLE')) . \"\\n\";",
        'strtoupper' => "echo json_encode(strtoupper('jinx-oracle')) . \"\\n\";",
        'substr' => "echo json_encode(substr('oracle-jinx-runtime', 7, 4)) . \"\\n\";",
        'trim' => "echo json_encode(trim('  jinx oracle  ')) . \"\\n\";",
        'ucfirst' => "echo json_encode(ucfirst('jinxOracle')) . \"\\n\";",
    ];
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

if (!is_dir($caseDir) && !mkdir($caseDir, 0777, true) && !is_dir($caseDir)) {
    fail('could not create all-callables differential case directory: ' . $caseDir);
}

$families = OracleMergedExecutionFamilies::all();
$declaredBuiltins = [];
foreach ($families as $metadata) {
    foreach (($metadata['builtins'] ?? []) as $builtin) {
        if (is_string($builtin) && $builtin !== '') {
            $declaredBuiltins[$builtin] = true;
        }
    }
}

ksort($declaredBuiltins);
$safeCases = safe_callable_cases();
$checked = 0;
$skipped = 0;

foreach (array_keys($declaredBuiltins) as $callable) {
    if (!array_key_exists($callable, $safeCases)) {
        $skipped++;
        continue;
    }

    $fixture = $caseDir . '/' . preg_replace('/[^A-Za-z0-9_]+/', '_', $callable) . '.php';
    $source = "<?php\n\ndeclare(strict_types=1);\n\n" . $safeCases[$callable] . "\n";
    file_put_contents($fixture, $source);

    $phpResult = run_process([$php, $fixture]);
    $jinxResult = run_process([$jinx, $fixture]);

    if ($jinxResult['exit'] !== $phpResult['exit']) {
        fail("{$callable} exit mismatch: PHP={$phpResult['exit']} JINX={$jinxResult['exit']}\nPHP stdout:\n{$phpResult['stdout']}\nJINX stdout:\n{$jinxResult['stdout']}\nPHP stderr:\n{$phpResult['stderr']}\nJINX stderr:\n{$jinxResult['stderr']}");
    }

    if ($jinxResult['stdout'] !== $phpResult['stdout']) {
        fail("{$callable} stdout mismatch\nPHP stdout:\n{$phpResult['stdout']}\nJINX stdout:\n{$jinxResult['stdout']}\nPHP stderr:\n{$phpResult['stderr']}\nJINX stderr:\n{$jinxResult['stderr']}");
    }

    $checked++;
}

if ($checked === 0) {
    fail('no declared callable facets were checked');
}

$total = count($declaredBuiltins);
echo "PASS: PHP vs JINX all-callables differential checked {$checked} declared callable facets and skipped {$skipped} of {$total} pending callable facets" . PHP_EOL;
