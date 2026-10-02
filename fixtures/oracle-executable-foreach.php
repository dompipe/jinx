<?php

declare(strict_types=1);

$items = [];
$items['a'] = 2;
$items['b'] = 3;
$items['skip'] = 4;
$items['c'] = 5;
$items['d'] = 6;

$sum = 0;
$text = '';

foreach ($items as $key => $value) {
    if ($key === 'skip') {
        continue;
    }

    $sum += $value;
    $text .= $key . ':' . $value . ';';

    if ($sum > 9) {
        break;
    }
}

echo $text;

return $sum;
