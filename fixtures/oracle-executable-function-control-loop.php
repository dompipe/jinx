<?php

declare(strict_types=1);

function jinx_control_unit(int $limit): string
{
    $parts = [];
    for ($n = 1; $n <= $limit; $n++) {
        $parts[] = ($n % 2 === 0 ? 'even' : 'odd') . ':' . $n;
    }
    return implode(',', $parts);
}

echo json_encode(['case' => 1, 'trace' => jinx_control_unit(4)]) . "\n";
return jinx_control_unit(4);
