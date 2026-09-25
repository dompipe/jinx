<?php

declare(strict_types=1);

$left = 7;
$right = 5;
$result = ($left > $right) . ':' . ($left <=> $right) . ':' . ($left !== $right);

echo $result;

return $result;
