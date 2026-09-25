<?php

declare(strict_types=1);

$items = array_unique(['a', 'b', 'a']);
$result = implode('', $items);

echo $result;

return $result;
