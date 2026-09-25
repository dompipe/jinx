<?php

declare(strict_types=1);

$i = 0;
$sum = 0;
$text = '';

while ($i < 7) {
    $i++;

    if ($i == 2) {
        continue;
    }

    $sum += $i;
    $text .= $i;

    if ($sum > 10) {
        break;
    }
}

echo $text;

return $sum;
