<?php

declare(strict_types=1);

$values = [2, 3, 4, 7];
$kept = [];
foreach ($values as $value) {
    if ($value % 2 === 1) {
        $kept[] = $value;
    }
}
$out = [
    'case' => 451,
    'values' => $values,
    'kept' => $kept,
    'sum' => array_sum($kept),
    'joined' => implode(',', $kept),
];
echo json_encode($out) . "\n";
