<?php

declare(strict_types=1);

$values = [42, 6, 4];
$total = array_sum($values);
$out = ["case" => 1, "total" => $total, "rounded" => round($total / 6, 2)];
echo json_encode($out) . "\n";
