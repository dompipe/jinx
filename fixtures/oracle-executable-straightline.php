<?php

declare(strict_types=1);

$items = [];
$items['name'] = 'jinx';
$value = $items['name'];
$value .= '!';
$count = 1;
$count++;
--$count;
$fallback = $items['missing'] ?? 'fallback';

echo $value;
print strtoupper(' oracle');

return strlen($value) + $count + strlen($fallback);
