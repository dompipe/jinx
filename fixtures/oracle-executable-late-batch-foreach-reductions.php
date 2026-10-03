<?php

declare(strict_types=1);

$map = ['alpha' => 3, 'beta' => 5, 'gamma' => 7];
$keys = [];
$values = [];
foreach ($map as $key => $value) {
    $keys[] = $key;
    $values[] = $value;
}
$out = ['keys' => implode(',', $keys), 'sum' => array_sum($values), 'max' => max($values), 'min' => min($values)];
echo json_encode($out) . "\n";
