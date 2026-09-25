<?php

declare(strict_types=1);

$number = (int) '7';
$text = (string) $number;
$truthy = (bool) $text;
$result = $text . ':' . (string) $truthy;

echo $result;

return $result;
