<?php

declare(strict_types=1);

$left = 7;
$right = 5;
$gt = $left > $right;
$cmp = $left <=> $right;
$ne = $left !== $right;
$result = (string) $gt . ':' . (string) $cmp . ':' . (string) $ne;

echo $result;

return $result;
