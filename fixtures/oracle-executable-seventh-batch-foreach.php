<?php

declare(strict_types=1);

$values = ["x" => 3, "y" => 6, "z" => 9];
$keys = [];
$weighted = 0;
$n = 1;
foreach ($values as $key => $value) {
    $keys[] = $key;
    $weighted += $value * $n;
    $n++;
}
echo json_encode(["keys" => implode("-", $keys), "weighted" => $weighted]) . "\n";
