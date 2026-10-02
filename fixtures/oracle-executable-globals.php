<?php

declare(strict_types=1);

$globalCounter = 3;

function bump_global($delta)
{
    global $globalCounter;

    $globalCounter += $delta;

    return $globalCounter;
}

$first = bump_global(4);
$second = bump_global(2);
$text = $globalCounter . ':' . $first . ':' . $second;

echo $text;

return $globalCounter;
