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
$localDefault ??= 4;
$localDefault ??= 9;
$items['n'] ??= 6;
$items['n'] ??= 11;

echo $value;
print strtoupper(' oracle');

return strlen($value) + $count + strlen($fallback) + $localDefault + $items['n'];
