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

function build_score($name, $base = 7, $bonus = 5)
{
    $score = add_score($base, $bonus);

    return label_score($name, $score);
}

$result = build_score(name: 'jinx');

echo $result;

return $result;
