<?php

declare(strict_types=1);

$value = abs(-5) + max(2, 8, 3) + min(6, 4, 9);
$result = (string) round($value / 3, 2);

echo $result;

return $result;
