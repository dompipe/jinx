<?php

declare(strict_types=1);

class StaticCounter
{
    public static $counter = 2;
}

$first = StaticCounter::$counter;
StaticCounter::$counter = StaticCounter::$counter + 5;
$second = StaticCounter::$counter;
$text = $first . ':' . $second;

echo $text;

return StaticCounter::$counter;
