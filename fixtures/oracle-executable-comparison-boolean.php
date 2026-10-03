<?php

declare(strict_types=1);

$a = 12;
$b = 4;
$c = 2;
$out = [
    'gt' => $a > $b,
    'lt' => $b < $c,
    'eq' => ($a - 12) === 0,
    'and' => ($a > $b) && ($c >= $b),
    'or' => ($a < $b) || ($c > $a),
    'ternary' => $a > $c ? 'a' : 'c',
];
echo json_encode($out) . "\n";
