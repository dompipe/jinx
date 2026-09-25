<?php

declare(strict_types=1);

function add_score($base, $bonus)
{
    $total = $base + $bonus;

    return $total;
}

function label_score($name, $score)
{
    $upper = strtoupper($name);
    $length = strlen($upper);

    return $upper . ':' . $score . ':' . $length;
}

function build_score($name, $base, $bonus)
{
    $score = add_score($base, $bonus);

    return label_score($name, $score);
}

$result = build_score('jinx', 7, 5);

echo $result;

return $result;
