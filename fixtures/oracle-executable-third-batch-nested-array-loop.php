<?php

declare(strict_types=1);

$rows = [
    ['name' => 'a1', 'value' => 1],
    ['name' => 'b1', 'value' => 11],
    ['name' => 'c1', 'value' => 21],
];
$sum = 0;
$names = [];
for ($idx = 0; $idx < count($rows); $idx++) {
    $sum += $rows[$idx]['value'];
    $names[] = $rows[$idx]['name'];
}
$out = [
    'sum' => $sum,
    'names' => implode(',', $names),
    'first' => $rows[0]['value'],
    'last' => $rows[2]['value'],
];
echo json_encode($out) . "\n";
