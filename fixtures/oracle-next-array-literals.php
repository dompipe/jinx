<?php

declare(strict_types=1);

$items = ['a', 'b', 'c'];
$map = ['x' => 2, 'y' => 3];
$result = implode('-', $items) . ':' . (string) count($map) . ':' . (string) array_sum([1, 2, 3]);

echo $result;

return $result;
