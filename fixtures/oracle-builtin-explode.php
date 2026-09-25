<?php

declare(strict_types=1);

$parts = explode(':', 'a:b:c');
$result = implode('-', $parts) . ':' . count($parts);

echo $result;

return $result;
