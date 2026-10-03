<?php

declare(strict_types=1);

$matrix = [
    ['id' => 'a1', 'value' => 1],
    ['id' => 'b1', 'value' => 11],
    ['id' => 'c1', 'value' => 21],
];
$values = [$matrix[0]['value'], $matrix[1]['value'], $matrix[2]['value']];
$out = [
    'first' => $matrix[0]['id'],
    'last' => $matrix[2]['id'],
    'sum' => array_sum($values),
    'count' => count($matrix),
    'joined' => implode(',', [$matrix[0]['id'], $matrix[1]['id'], $matrix[2]['id']]),
];
echo json_encode($out) . "\n";
