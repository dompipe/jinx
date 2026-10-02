<?php

declare(strict_types=1);

$sum = 0;
$text = '';

for ($i = 0; $i < 8; $i++) {
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
