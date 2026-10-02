<?php

declare(strict_types=1);

$i = 0;
$sum = 0;
$text = '';

do {
    $i++;

    if ($i == 2) {
        continue;
    }

    $sum += $i;
    $text .= $i;

    if ($sum > 10) {
        break;
    }
} while ($i < 8);

echo $text;

return $sum;
