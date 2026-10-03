<?php

declare(strict_types=1);

class StaticCounter
{
    public static $counter = 2;
}

$first = StaticCounter::$counter;
StaticCounter::$counter = StaticCounter::$counter + 5;
$second = StaticCounter::$counter;
StaticCounter::$counter += 3;
$third = StaticCounter::$counter;
$text = $first . ':' . $second . ':' . $third;

echo $text;

return StaticCounter::$counter;
