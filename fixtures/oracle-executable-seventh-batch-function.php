<?php

declare(strict_types=1);

function jinx_seventh_fold(int $limit): string
{
    $total = 0;
    for ($n = 1; $n <= $limit; $n++) {
        $total += ($n % 2 === 0) ? ($n * 2) : $n;
    }
    return "limit:" . $limit . ":total:" . $total;
}
echo json_encode(["case" => 1, "result" => jinx_seventh_fold(5)]) . "\n";
