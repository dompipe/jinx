<?php

declare(strict_types=1);

$items = ['a', 'b', 'c', 'd'];
$result = implode('|', array_slice($items, 1, 2));

echo $result;

return $result;
