<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/OracleProgramCompiler.php';

use jinx\oracle\OracleProgramCompiler;

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$source = <<<'PHP'
<?php

$total = 0;
$n = 2;
$total += ($n % 2 === 0) ? ($n * 2) : $n;

$trace = [];
$trace[] = ($n % 2 === 0 ? 'e' : 'o') . $n;

$label = $n % 3 === 0 ? 'tri' : ($n % 2 === 0 ? 'even' : 'odd');

$rows = [
    ['n' => 1],
    ['n' => 2],
];
$last = $rows[count($rows) - 1];

$weighted = 0;
foreach ([1, 2] as $value) {
    $weighted += $value;
    $n++;
}
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'jinx-late-shapes-');
if ($tmp === false) {
    fail('could not allocate late-shape classifier fixture');
}

$fixture = $tmp . '.php';
@unlink($tmp);
file_put_contents($fixture, $source);

try {
    $program = OracleProgramCompiler::interpretAnyPhpFileToOracleProgram($fixture);
} finally {
    @unlink($fixture);
}

$ops = array_column($program['statements'] ?? [], 'op');

$expectedMinimums = [
    'O_COMPOUND_ASSIGN' => 2,
    'O_DIM_ASSIGN' => 1,
    'O_ASSIGN' => 4,
    'O_DIM_FETCH' => 1,
    'O_FOREACH' => 1,
    'O_INC' => 1,
];

foreach ($expectedMinimums as $op => $minimum) {
    $count = count(array_filter($ops, static fn(string $candidate): bool => $candidate === $op));
    if ($count < $minimum) {
        fail("expected at least {$minimum} {$op}, got {$count}: " . json_encode($ops));
    }
}

if (in_array('O_TERNARY', $ops, true)) {
    fail('embedded ternary expressions must not replace statement-level assignment ops: ' . json_encode($ops));
}

echo "PASS: Oracle classifier preserves late differential statement shapes" . PHP_EOL;
