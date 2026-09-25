<?php

declare(strict_types=1);

$items = array_reverse(['a', 'b', 'c']);
$result = implode('', $items);

echo $result;

return $result;
