<?php

declare(strict_types=1);

global $counter;
$counter = 10;
$shadow = 100;

function oracle_scope_step(): string
{
    global $counter;
    static $calls = 0;
    $calls = $calls + 1;
    $counter++;
    $shadow = 7;
    return $calls . ':' . $counter . ':' . $shadow;
}

$first = oracle_scope_step();
$second = oracle_scope_step();
$result = $first . '|' . $second . '|' . $counter . ':' . $shadow;
echo $result;
return $result;
