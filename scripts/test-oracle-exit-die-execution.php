<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleExitExecutor.php';

use jinx\oracle\OracleExitExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixture = $root . '/fixtures/oracle-executable-exit-die.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function run_php_exit_fixture(string $fixture): array
{
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1', $out, $code);

    return [
        'output' => implode(PHP_EOL, $out) . (count($out) ? PHP_EOL : ''),
        'exit_code' => $code,
    ];
}

function run_oracle_exit_fixture(string $fixture): array
{
    $result = [
        'output' => '',
        'return' => null,
        'exit_code' => null,
        'terminated' => null,
        'error_class' => null,
        'error_message' => null,
        'oracle' => null,
        'program' => null,
    ];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleExitExecutor::execute($program);
        $result['program'] = $program;
        $result['oracle'] = $oracle;
        $result['output'] = $oracle['output'] ?? null;
        $result['return'] = $oracle['return'] ?? null;
        $result['exit_code'] = $oracle['exit_code'] ?? null;
        $result['terminated'] = $oracle['terminated'] ?? null;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    }

    return $result;
}

$php = run_php_exit_fixture($fixture);
$oracle = run_oracle_exit_fixture($fixture);

same($oracle['error_class'], null, 'Oracle error class');
same($oracle['output'], $php['output'], 'Oracle output matches PHP');
same($oracle['exit_code'], $php['exit_code'], 'Oracle exit code matches PHP');
same($oracle['terminated'], true, 'Oracle terminated flag');
same($oracle['return'], null, 'Oracle return after exit');
same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', 'Oracle execution kind');
same($oracle['oracle']['family'] ?? null, 'exit-die', 'Oracle execution family');

$ops = array_column($oracle['program']['statements'] ?? [], 'op');

foreach (['O_ASSIGN', 'O_ECHO', 'O_EXIT'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("fixture did not produce expected {$op}");
    }
}

$source = implode("\n", array_map(static fn (array $statement): string => (string) ($statement['source'] ?? ''), $oracle['program']['statements'] ?? []));

if (!str_contains($source, "die('stopped')")) {
    fail('fixture source did not preserve die statement');
}

echo "PASS: Oracle executes exit/die PHP subset and matches PHP output/exit behavior" . PHP_EOL;
