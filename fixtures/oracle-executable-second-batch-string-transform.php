<?php

declare(strict_types=1);

$left = 'alpha-1';
$right = 'omega-51';
$joined = $left . ':' . $right;
$out = [
    'trimmed' => trim('  ' . $joined . '  '),
    'first' => ucfirst('case1'),
    'lower_first' => lcfirst('Case1'),
    'rev' => strrev($left),
    'repeat' => str_repeat('x', 2),
    'padded' => str_pad((string) 1, 4, '0', STR_PAD_LEFT),
];
echo json_encode($out) . "\n";
