<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedBuiltinExecutor.php';
require_once dirname(__DIR__) . '/runtime/OracleGeneratedExecutionFamilies.php';

use jinx\oracle\OracleGeneratedBuiltinExecutor;
use jinx\oracle\OracleGeneratedExecutionFamilies;
use jinx\oracle\OracleProgramCompiler;

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

/** @return array{output:string,return:mixed,error_class:?string,error_message:?string} */
function run_php_generated_fixture(string $fixture): array
{
    $return = null;
    $errorClass = null;
    $errorMessage = null;

    ob_start();
    try {
        $return = require $fixture;
    } catch (Throwable $e) {
        $errorClass = $e::class;
        $errorMessage = $e->getMessage();
    } finally {
        $output = (string) ob_get_clean();
    }

    return ['output' => $output, 'return' => $return, 'error_class' => $errorClass, 'error_message' => $errorMessage];
}

/** @return array{output:mixed,return:mixed,error_class:?string,error_message:?string,oracle:mixed} */
function run_oracle_generated_fixture(string $fixture, string $family): array
{
    $result = ['output' => '', 'return' => null, 'error_class' => null, 'error_message' => null, 'oracle' => null];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleGeneratedBuiltinExecutor::execute($program, $family);
        $result['oracle'] = $oracle;
        $result['output'] = $oracle['output'] ?? null;
        $result['return'] = $oracle['return'] ?? null;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    }

    return $result;
}

function expression_for_generated_family(int $i): string
{
    return match ($i % 25) {
        0 => "strlen('jinx-{$i}')",
        1 => "strtoupper('jinx-{$i}')",
        2 => "strtolower('JINX-{$i}')",
        3 => "trim('  jinx-{$i}  ')",
        4 => "substr('oracle-jinx-{$i}', 1, 6)",
        5 => "str_replace('jinx', 'oracle', 'jinx-{$i}-jinx')",
        6 => "strrev('jinx-{$i}')",
        7 => "ucfirst('jinx-{$i}')",
        8 => "lcfirst('Jinx-{$i}')",
        9 => "str_repeat('x', " . (($i % 5) + 1) . ")",
        10 => "str_pad('{$i}', 6, '0', 0)",
        11 => "abs(-{$i})",
        12 => "max([{$i}, " . ($i + 7) . ', ' . ($i - 3) . "])",
        13 => "min([{$i}, " . ($i + 7) . ', ' . ($i - 3) . "])",
        14 => "round(" . ($i + 0.55) . ", 1)",
        15 => "array_sum([{$i}, 2, 3])",
        16 => "array_product([" . (($i % 5) + 1) . ", 2, 3])",
        17 => "count([{$i}, " . ($i + 1) . ', ' . ($i + 2) . "])",
        18 => "implode('-', ['jinx', 'oracle', '{$i}'])",
        19 => "json_encode(['family' => {$i}, 'name' => 'jinx'])",
        20 => "base64_encode('jinx-{$i}')",
        21 => "md5('jinx-{$i}')",
        22 => "sha1('jinx-{$i}')",
        23 => "strlen(strtoupper('jinx-{$i}'))",
        default => "substr(str_replace('x', 'z', 'jinx-{$i}'), 0, 8)",
    };
}

$root = dirname(__DIR__);
$generatedDir = $root . '/build/generated/oracle-generated-builtin';

if (!is_dir($generatedDir) && !mkdir($generatedDir, 0777, true) && !is_dir($generatedDir)) {
    fail('could not create generated builtin fixture directory');
}

for ($i = 1; $i <= OracleGeneratedExecutionFamilies::TOTAL_GENERATED_FAMILIES; $i++) {
    $family = sprintf('generated-pure-builtin-%03d', $i);
    $expression = expression_for_generated_family($i);
    $fixture = $generatedDir . '/' . $family . '.php';

    $source = "<?php\n\ndeclare(strict_types=1);\n\n";
    $source .= '$value = ' . $expression . ";\n";
    $source .= "echo 'value=' . json_encode(\$value) . \"\\n\";\n";
    $source .= "return json_encode(\$value);\n";
    file_put_contents($fixture, $source);

    $php = run_php_generated_fixture($fixture);
    $oracle = run_oracle_generated_fixture($fixture, $family);

    same($oracle['error_class'], $php['error_class'], "{$family} Oracle error class matches PHP");

    if ($php['error_class'] !== null) {
        if (!str_contains((string) $oracle['error_message'], (string) $php['error_message'])) {
            fail("{$family} Oracle error message does not include PHP error message");
        }
        continue;
    }

    same($oracle['output'], $php['output'], "{$family} Oracle output matches PHP");
    same($oracle['return'], $php['return'], "{$family} Oracle return matches PHP");
    same($oracle['oracle']['kind'] ?? null, 'JINX_ORACLE_EXECUTION', "{$family} Oracle execution kind");
    same($oracle['oracle']['family'] ?? null, $family, "{$family} Oracle execution family");

    if (($oracle['oracle']['executed_ops'] ?? 0) < 3) {
        fail("{$family} Oracle executed too few ops");
    }
}

echo 'PASS: Oracle executes generated pure builtin PHP families and matches PHP output/return/error behavior' . PHP_EOL;
