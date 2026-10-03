<?php

declare(strict_types=1);

function jinx_third_branch(int $limit): array
{
    $total = 0;
    $labels = [];
    for ($n = 1; $n <= $limit; $n++) {
        if ($n % 3 === 0) {
            $labels[] = 'tri:' . $n;
        } elseif ($n % 2 === 0) {
            $labels[] = 'even:' . $n;
        } else {
            $labels[] = 'odd:' . $n;
        }
        $total += $n;
    }

    return ['total' => $total, 'labels' => implode('|', $labels)];
}

$out = jinx_third_branch(6);
$out['case'] = 1;
echo json_encode($out) . "\n";
