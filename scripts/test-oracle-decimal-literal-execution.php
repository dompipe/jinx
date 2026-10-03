<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';
require_once dirname(__DIR__) . '/runtime/OracleExpressionBatchExecutor.php';

use jinx\oracle\OracleExpressionBatchExecutor;
use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$source = <<<'PHP'
<?php

declare(strict_types=1);

echo json_encode(round(12.55, 1)) . "\n";
echo json_encode(1.25 + 2.5) . "\n";
echo json_encode(3.75) . "\n";
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'jinx-decimal-');
if ($tmp === false) {
    fail('could not allocate decimal fixture');
}
$fixture = $tmp . '.php';
@unlink($tmp);
file_put_contents($fixture, $source);

ob_start();
try {
    require $fixture;
    $phpOutput = (string) ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    @unlink($fixture);
    fail('PHP decimal fixture failed: ' . $e->getMessage());
}

try {
    $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
    $oracle = OracleExpressionBatchExecutor::execute($program, 'math-builtins');
} catch (Throwable $e) {
    @unlink($fixture);
    fail('Oracle decimal fixture failed: ' . $e->getMessage());
}

@unlink($fixture);

if (($oracle['output'] ?? null) !== $phpOutput) {
    fail(
        'decimal expression output mismatch: PHP=' . json_encode($phpOutput) .
        ' Oracle=' . json_encode($oracle['output'] ?? null)
    );
}

if ($phpOutput !== "12.6\n3.75\n3.75\n") {
    fail('unexpected PHP decimal baseline: ' . json_encode($phpOutput));
}

echo "PASS: Oracle decimal literals are parsed before concatenation and match PHP round behavior" . PHP_EOL;
