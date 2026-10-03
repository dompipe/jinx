<?php

declare(strict_types=1);

$suffix = '!';
$decorate = function ($text) use ($suffix) {
    return strtoupper($text) . $suffix;
};
$summarize = fn ($text) => $text . ':' . strlen($text);

$first = $decorate('jinx');
$second = $summarize($first);

echo $second;

return $second;
