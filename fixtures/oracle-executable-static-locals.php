<?php

declare(strict_types=1);

function next_ticket($step)
{
    static $ticket = 10;

    $ticket = $ticket + $step;

    return $ticket;
}

$first = next_ticket(2);
$second = next_ticket(5);
$third = next_ticket(1);
$text = $first . ':' . $second . ':' . $third;

echo $text;

return $second + $third;
