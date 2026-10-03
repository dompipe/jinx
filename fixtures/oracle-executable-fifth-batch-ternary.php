<?php

declare(strict_types=1);

$base = 42;
$status = $base > 40 ? 'large' : ($base % 2 === 0 ? 'even' : 'odd');
$out = [
    'case' => 401,
    'status' => $status,
    'bools' => [$base > 10, $base < 90, $base === 42],
    'value' => $base + ($status === 'large' ? 100 : 10),
];
echo json_encode($out) . "\n";
