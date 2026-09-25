<?php

declare(strict_types=1);

$sum = 0;

for ($i = 0; $i < 5; $i++) {
    $sum += $i;
}

$result = '' . $sum;

echo $result;

return $result;
