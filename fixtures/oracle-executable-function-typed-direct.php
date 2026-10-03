<?php

declare(strict_types=1);

function jinx_diff_value(string $name, int $n): string
{
    return strtoupper($name) . ':' . ($n * 3);
}

echo jinx_diff_value('oracle', 14) . "\n";
return jinx_diff_value('oracle', 14);
