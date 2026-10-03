<?php

declare(strict_types=1);

function jinx_sixth_state(int $limit): array
{
    $acc = [];
    for ($n = 1; $n <= $limit; $n++) {
        $acc[] = ["n" => $n, "kind" => ($n % 3 === 0 ? "tri" : "plain")];
    }
    return $acc;
}

$rows = jinx_sixth_state(5);
$last = $rows[count($rows) - 1];
echo json_encode(["case" => 1, "count" => count($rows), "last" => $last]) . "\n";
