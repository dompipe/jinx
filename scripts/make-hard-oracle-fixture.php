<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$out = $root . '/fixtures/hard-oracle-chain.php';

$lines = [];
$lines[] = '<?php';
$lines[] = '';
$lines[] = '$v0 = 3;';
$lines[] = '$v1 = 5;';
$lines[] = '$v2 = 7;';

for ($i = 3; $i <= 80; $i++) {
    $a = '$v' . ($i - 1);
    $b = '$v' . ($i - 2);

    if ($i % 3 === 0) {
        $lines[] = '$v' . $i . " = {$a} + {$b};";
    } elseif ($i % 3 === 1) {
        $lines[] = '$v' . $i . " = {$a} - {$b};";
    } else {
        $small = '$v' . ($i % 3);
        $lines[] = '$v' . $i . " = {$a} * {$small};";
    }
}

$lines[] = '';
$lines[] = 'return $v80 + $v79;';
$lines[] = '';

file_put_contents($out, implode("\n", $lines));

echo "generated {$out}\n";
