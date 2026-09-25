<?php

declare(strict_types=1);

$items = ['a', 'b', 'c'];
$text = '';

foreach ($items as $index => $item) {
    $text .= $index . ':' . $item . ';';
}

echo $text;

return $text;
