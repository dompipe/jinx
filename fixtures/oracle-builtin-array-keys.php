<?php

declare(strict_types=1);

$map = ['a' => 1, 'b' => 2];
$result = implode('|', array_keys($map));

echo $result;

return $result;
