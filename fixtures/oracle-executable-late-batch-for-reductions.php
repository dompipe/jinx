<?php

declare(strict_types=1);

$left = 65;
$right = 6;
$values = [];
for ($n = 1; $n <= 4; $n++) {
    $values[] = ($left - $n) + ($right * $n);
}
echo json_encode([
    'max' => max($values),
    'min' => min($values),
    'sum' => array_sum($values),
    'last' => $values[count($values) - 1],
]) . "\n";
