<?php

declare(strict_types=1);

$raw = '  JINX Runtime  ';
$result = strtolower(trim($raw)) . ':' . substr('abcdef', 1, 3) . ':' . strlen('oracle');

echo $result;

return $result;
