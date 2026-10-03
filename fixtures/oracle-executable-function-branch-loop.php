<?php

declare(strict_types=1);

function jinx_branch_unit(int $limit, int $multiplier): array
{
    $sum = 0;
    $trace = [];
    for ($n = 1; $n <= $limit; $n++) {
        $value = $n * $multiplier;
        $sum += $value;
        if ($value % 3 === 0) {
            $trace[] = 'three:' . $value;
        } else {
            $trace[] = 'other:' . $value;
        }
    }
    return ['sum' => $sum, 'trace' => implode('|', $trace)];
}
echo json_encode(['case' => 1, 'result' => jinx_branch_unit(5, 3)]) . "\n";
