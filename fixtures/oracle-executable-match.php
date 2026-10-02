<?php

declare(strict_types=1);

$code = 2;
$fallback = 'fallback';
$label = match ($code) {
    1 => 'one',
    2 => 'two',
    default => $fallback,
};

echo $label;

return strlen($label);
