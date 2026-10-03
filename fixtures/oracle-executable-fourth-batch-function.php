<?php

declare(strict_types=1);

function jinx_fourth_accumulator(int $limit): array
{
    $total = 0;
    $trace = [];
    for ($n = 1; $n <= $limit; $n++) {
        $total += $n * 3;
        $trace[] = ($total % 2 === 0 ? 'e' : 'o') . $total;
    }
    return ['total' => $total, 'trace' => implode(',', $trace)];
}
echo json_encode(['case' => 376, 'result' => jinx_fourth_accumulator(5)]) . "\n";
