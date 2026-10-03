<?php

declare(strict_types=1);

$text = "jinx-oracle-runtime-001";
$needle = "jinx";
$out = [
    "needle" => $needle,
    "has" => str_contains($text, $needle),
    "prefix" => substr($text, 0, 4),
    "tail" => substr($text, -3),
    "swap" => str_replace("-", "_", $text),
];
echo json_encode($out) . "\n";
