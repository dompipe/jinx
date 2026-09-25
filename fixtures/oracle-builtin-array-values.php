<?php

declare(strict_types=1);

$map = ['a' => 'x', 'b' => 'y'];
$result = implode('|', array_values($map));

echo $result;

return $result;
