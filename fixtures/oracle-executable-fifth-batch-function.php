<?php

declare(strict_types=1);

function jinx_fifth_nested(int $limit): array
{
    $items = [];
    $total = 0;
    for ($n = 1; $n <= $limit; $n++) {
        $value = $n * 3;
        $kind = $value % 3 === 0 ? 'tri' : ($value % 2 === 0 ? 'even' : 'odd');
        $items[] = $kind . ':' . $value;
        $total += $value;
    }
    return ['limit' => $limit, 'items' => $items, 'total' => $total];
}
echo json_encode(['case' => 476, 'result' => jinx_fifth_nested(6)]) . "\n";
