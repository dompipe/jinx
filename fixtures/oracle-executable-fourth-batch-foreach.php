<?php

declare(strict_types=1);

$rows = [
    ['k' => 'a', 'v' => 1],
    ['k' => 'b', 'v' => 11],
    ['k' => 'c', 'v' => 21],
];
$sum = 0;
$labels = [];
foreach ($rows as $row) {
    $sum += $row['v'];
    $labels[] = $row['k'] . ':' . $row['v'];
}
$out = ['case' => 351, 'sum' => $sum, 'labels' => implode('|', $labels), 'count' => count($rows)];
echo json_encode($out) . "\n";
