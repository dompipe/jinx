<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$supported = $root . '/fixtures/oracle-executable-straightline.php';

$minimal = tempnam(sys_get_temp_dir(), 'jinx-minimal-');
if ($minimal === false) {
    fail('could not allocate minimal Oracle fixture');
}
$minimalFixture = $minimal . '.php';
@unlink($minimal);
file_put_contents(
    $minimalFixture,
    "<?php\ndeclare(strict_types=1);\n\$a = 7;\n\$b = 5;\necho \"sum=\" . (\$a + \$b) . \"\\n\";\n"
);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function run_process(array $command): array
{
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $spec, $pipes, $GLOBALS['root']);
    if (!is_resource($proc)) {
        fail('could not start process: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return [
        'exit' => is_int($exit) ? $exit : 1,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable');
}

$php = run_process([PHP_BINARY, $supported]);
$jinxResult = run_process([$jinx, $supported]);

if ($jinxResult['exit'] !== $php['exit']) {
    fail('supported Oracle fixture exit differs from PHP');
}
if ($jinxResult['stdout'] !== $php['stdout']) {
    fail(
        'supported Oracle fixture output differs from PHP: PHP=' .
        json_encode($php['stdout']) . ' JINX=' . json_encode($jinxResult['stdout'])
    );
}
if ($jinxResult['stderr'] !== '') {
    fail('supported Oracle fixture emitted stderr: ' . json_encode($jinxResult['stderr']));
}

try {
    $phpMinimal = run_process([PHP_BINARY, $minimalFixture]);
    $jinxMinimal = run_process([$jinx, $minimalFixture]);
} finally {
    @unlink($minimalFixture);
}

if ($jinxMinimal['exit'] !== $phpMinimal['exit']) {
    fail('minimal straight-line fixture exit differs from PHP');
}
if ($jinxMinimal['stdout'] !== $phpMinimal['stdout']) {
    fail(
        'minimal straight-line fixture output differs from PHP: PHP=' .
        json_encode($phpMinimal['stdout']) . ' JINX=' . json_encode($jinxMinimal['stdout'])
    );
}
if ($jinxMinimal['stderr'] !== '') {
    fail('minimal straight-line fixture emitted stderr: ' . json_encode($jinxMinimal['stderr']));
}

$tmp = tempnam(sys_get_temp_dir(), 'jinx-no-fallback-');
if ($tmp === false) {
    fail('could not allocate no-fallback fixture');
}
$unsupported = $tmp . '.php';
@unlink($tmp);
file_put_contents(
    $unsupported,
    "<?php\necho \"PHP_FALLBACK_MUST_NOT_RUN\\n\";\nnew class { public function x(): void {} };\n"
);

try {
    $rejected = run_process([$jinx, $unsupported]);
} finally {
    @unlink($unsupported);
}

if ($rejected['exit'] === 0) {
    fail('unsupported PHP fixture unexpectedly succeeded');
}
if (str_contains($rejected['stdout'], 'PHP_FALLBACK_MUST_NOT_RUN')) {
    fail('native ./jinx silently executed unsupported fixture through PHP');
}
if (!str_contains($rejected['stderr'], 'refusing PHP fallback')) {
    fail('unsupported fixture did not report explicit no-fallback rejection: ' . json_encode($rejected['stderr']));
}

echo "PASS: native ./jinx runs supported PHP fixtures through Oracle and refuses PHP fallback for unsupported scripts" . PHP_EOL;
