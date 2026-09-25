<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleFilesystemBuiltinExecutor.php';

use jinx\oracle\OracleFilesystemBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @return array{output:string,return:mixed,error:?string} */
function run_php_fixture(string $file): array
{
    $output = '';
    $return = null;
    $error = null;

    ob_start();
    try {
        $return = require $file;
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    } finally {
        $output = (string) ob_get_clean();
    }

    return ['output' => $output, 'return' => $return, 'error' => $error];
}

function php_literal_string(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

$root = dirname(__DIR__);
$generatedDir = $root . '/build/generated/oracle-filesystem-builtin';
$assetDir = $generatedDir . '/assets';
$subDir = $assetDir . '/subdir';

if (!is_dir($subDir) && !mkdir($subDir, 0777, true) && !is_dir($subDir)) {
    fail('could not create filesystem builtin fixture directory');
}

$dataFile = $assetDir . '/sample.txt';
$extraFile = $assetDir . '/second.txt';
$missingFile = $assetDir . '/missing.txt';
file_put_contents($dataFile, "jinx oracle file\nsecond line\n");
file_put_contents($extraFile, "another file\n");
clearstatcache(true, $dataFile);
clearstatcache(true, $extraFile);
clearstatcache(true, $subDir);

$data = php_literal_string(str_replace('\\', '/', $dataFile));
$extra = php_literal_string(str_replace('\\', '/', $extraFile));
$dir = php_literal_string(str_replace('\\', '/', $assetDir));
$sub = php_literal_string(str_replace('\\', '/', $subDir));
$missing = php_literal_string(str_replace('\\', '/', $missingFile));
$glob = php_literal_string(str_replace('\\', '/', $assetDir) . '/*.txt');

$cases = [
    'file-exists-builtins' => "file_exists({$data})",
    'is-file-builtins' => "is_file({$data})",
    'is-dir-builtins' => "is_dir({$sub})",
    'is-readable-builtins' => "is_readable({$data})",
    'is-writable-builtins' => "is_writable({$data})",
    'filesize-builtins' => "filesize({$data})",
    'filetype-builtins' => "filetype({$data})",
    'fileperms-builtins' => "fileperms({$data})",
    'fileinode-builtins' => "fileinode({$data})",
    'filemtime-builtins' => "filemtime({$data})",
    'filectime-builtins' => "filectime({$data})",
    'realpath-builtins' => "realpath({$data})",
    'stat-builtins' => "json_encode(stat({$data}))",
    'lstat-builtins' => "json_encode(lstat({$data}))",
    'file-get-contents-builtins' => "file_get_contents({$data})",
    'file-lines-builtins' => "json_encode(file({$data}))",
    'md5-file-builtins' => "md5_file({$data})",
    'sha1-file-builtins' => "sha1_file({$data})",
    'hash-file-builtins' => "hash_file('sha256', {$data})",
    'glob-builtins' => "json_encode(glob({$glob}))",
];

foreach ($cases as $family => $expression) {
    $fixture = $generatedDir . '/' . $family . '.php';
    $source = "<?php\n\ndeclare(strict_types=1);\n\n";
    $source .= '$value = ' . $expression . ";\n";
    $source .= "echo 'value=' . \$value . \"\\n\";\n";
    $source .= "return \$value;\n";
    file_put_contents($fixture, $source);

    clearstatcache(true, $dataFile);
    clearstatcache(true, $extraFile);
    clearstatcache(true, $subDir);
    $php = run_php_fixture($fixture);
    $program = OracleProgramCompiler::compileFile($fixture, $family);
    clearstatcache(true, $dataFile);
    clearstatcache(true, $extraFile);
    clearstatcache(true, $subDir);
    $oracle = OracleFilesystemBuiltinExecutor::execute($program, $family);

    assert_same($php['error'], null, "{$family} PHP fixture should not throw");
    assert_same($php['output'], $oracle['output'], "{$family} Oracle output matches PHP");
    assert_same($php['return'], $oracle['return'], "{$family} Oracle return matches PHP");
    assert_same('JINX_ORACLE_EXECUTION', $oracle['kind'], "{$family} result kind");
    assert_same($family, $oracle['family'], "{$family} result family");
}

echo 'PASS: Oracle executes filesystem/stat builtin PHP families and matches PHP output/return/error behavior' . PHP_EOL;
