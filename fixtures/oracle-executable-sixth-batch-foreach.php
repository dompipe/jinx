<?php

declare(strict_types=1);

$values = [12, 4, 7];
$total = 0;
foreach ($values as $idx => $value) {
    $total += ($value * ($idx + 1));
}
echo json_encode(["case" => 1, "total" => $total, "mod" => ($total % 4)]) . "\n";
