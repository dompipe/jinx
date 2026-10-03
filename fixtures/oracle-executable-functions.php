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

function sum_tail($head, ...$tail)
{
    return $head + $tail[0] + $tail[1];
}

function build_score($name, $base = 7, $bonus = 5)
{
    $score = add_score($base, $bonus);

    return label_score($name, $score);
}

$result = build_score(name: 'jinx');
$packed = [2, 3];
$extra = sum_tail(1, ...$packed);
$result .= ':' . $extra;

echo $result;

return $result;
