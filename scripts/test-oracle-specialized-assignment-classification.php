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

$items = ['name' => 'jinx'];
$value = $items['name'];
$suffix = '!';
$decorate = function ($text) use ($suffix) {
    return $text . $suffix;
};
$summarize = fn ($text) => $text . ':' . strlen($text);
$rows = [['value' => 3]];
$idx = 0;
$sum = 0;
$sum += $rows[$idx]['value'];
$total = 0;
$n = 2;
$total += ($n % 2 === 0) ? ($n * 2) : $n;
$status = $value === 'jinx' ? 'yes' : 'no';
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'jinx-classify-');
if ($tmp === false) {
    fail('could not allocate temporary classifier fixture');
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

foreach (['O_DIM_FETCH', 'O_CLOSURE', 'O_ARROW_FUNCTION', 'O_COMPOUND_ASSIGN', 'O_ASSIGN'] as $op) {
    if (!in_array($op, $ops, true)) {
        fail("classifier regression missing {$op}: " . json_encode($ops));
    }
}

if (count(array_filter($ops, static fn(string $op): bool => $op === 'O_CLOSURE')) !== 1) {
    fail('expected exactly one O_CLOSURE');
}
if (count(array_filter($ops, static fn(string $op): bool => $op === 'O_ARROW_FUNCTION')) !== 1) {
    fail('expected exactly one O_ARROW_FUNCTION');
}

if (count(array_filter($ops, static fn(string $op): bool => $op === 'O_COMPOUND_ASSIGN')) !== 2) {
    fail('expected exactly two O_COMPOUND_ASSIGN records');
}

echo "PASS: Oracle classifier preserves specialized assignment families before generic assignment" . PHP_EOL;
