<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleDataBuiltinExecutor.php';

use jinx\oracle\OracleDataBuiltinExecutor;
use jinx\oracle\OracleProgramCompiler;

$root = dirname(__DIR__);
$fixtureDir = $root . '/build/generated/oracle-data-builtin-two';
if (!is_dir($fixtureDir) && !mkdir($fixtureDir, 0777, true) && !is_dir($fixtureDir)) {
    fwrite(STDERR, "FAIL: could not create generated fixture directory {$fixtureDir}" . PHP_EOL);
    exit(1);
}

$cases = [
    'join-builtins' => 'return join("|", ["a", "b", "c"]);',
    'count-builtins' => 'return count([1, 2, 3, 4]);',
    'array-count-values-builtins' => 'return json_encode(array_count_values(["red", "blue", "red", 2, 2, 2]));',
    'array-pad-builtins' => 'return json_encode(array_pad(["x"], 3, "pad"));',
    'array-replace-builtins' => 'return json_encode(array_replace(["a" => 1, "b" => 2], ["b" => 20, "c" => 30]));',
    'array-replace-recursive-builtins' => 'return json_encode(array_replace_recursive(["a" => ["x" => 1], "b" => 2], ["a" => ["y" => 3]]));',
    'array-is-list-builtins' => 'return array_is_list(["zero", "one", "two"]);',
    'array-filter-builtins' => 'return json_encode(array_filter([1, 0, 2, "", 3]));',
    'quoted-printable-encode-builtins' => 'return quoted_printable_encode("hello world=jinx");',
    'quoted-printable-decode-builtins' => 'return quoted_printable_decode("hello=20world=3Djinx");',
    'convert-uuencode-builtins' => 'return convert_uuencode("JINX");',
    'convert-uudecode-builtins' => 'return convert_uudecode(convert_uuencode("JINX"));',
    'pack-builtins' => 'return bin2hex(pack("C*", 65, 66, 67));',
    'unpack-builtins' => 'return json_encode(unpack("C*", "ABC"));',
    'decbin-builtins' => 'return decbin(42);',
    'dechex-builtins' => 'return dechex(48879);',
    'decoct-builtins' => 'return decoct(511);',
    'bindec-builtins' => 'return bindec("101010");',
    'hexdec-builtins' => 'return hexdec("beef");',
    'base-convert-builtins' => 'return base_convert("ff", 16, 2);',
];

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

function write_fixture(string $dir, string $family, string $body): string
{
    $path = $dir . '/' . $family . '.php';
    $source = "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n";
    file_put_contents($path, $source);
    return $path;
}

function run_php_fixture(string $fixture): array
{
    $__jinx_capture_return = null;
    $__jinx_capture_error_class = null;
    $__jinx_capture_error_message = null;

    ob_start();
    try {
        $__jinx_capture_return = require $fixture;
    } catch (Throwable $e) {
        $__jinx_capture_error_class = $e::class;
        $__jinx_capture_error_message = $e->getMessage();
    } finally {
        $__jinx_capture_output = (string) ob_get_clean();
    }

    return [
        'output' => $__jinx_capture_output,
        'return' => $__jinx_capture_return,
        'error_class' => $__jinx_capture_error_class,
        'error_message' => $__jinx_capture_error_message,
    ];
}

function run_oracle_fixture(string $fixture, string $family): array
{
    $result = [
        'output' => '',
        'return' => null,
        'error_class' => null,
        'error_message' => null,
        'oracle' => null,
    ];

    try {
        $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
        $oracle = OracleDataBuiltinExecutor::execute($program, $family);
        $result['oracle'] = $oracle;
        $result['output'] = $oracle['output'] ?? null;
        $result['return'] = $oracle['return'] ?? null;
    } catch (Throwable $e) {
        $result['error_class'] = $e::class;
        $result['error_message'] = $e->getMessage();
    }

    return $result;
}

foreach ($cases as $family => $body) {
    $fixture = write_fixture($fixtureDir, $family, $body);
    $php = run_php_fixture($fixture);
    $oracle = run_oracle_fixture($fixture, $family);

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

    if (($oracle['oracle']['executed_ops'] ?? 0) < 1) {
        fail("{$family} Oracle executed too few ops");
    }
}

echo "PASS: Oracle executes data builtin batch two PHP families and matches PHP output/return/error behavior" . PHP_EOL;
