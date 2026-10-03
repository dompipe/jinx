<?php

declare(strict_types=1);

$left = 602;
$right = 2;
$out = [
    'case' => 301,
    'div' => intdiv($left, $right),
    'mod' => $left % $right,
    'abs' => abs($right - $left),
    'round' => round(($left / $right), 3),
];
echo json_encode($out) . "\n";
