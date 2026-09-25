<?php

declare(strict_types=1);

$items = [];
$items[] = 'alpha';
$items[] = 'beta';

$profile = [];
$profile['user'] = [];
$profile['user']['name'] = 'jinx';
$profile['user']['score'] = 4;

$name = $profile['user']['name'];
$score = $profile['user']['score'];
$hasName = isset($profile['user']['name']);
$missingIsEmpty = empty($profile['user']['missing']);

unset($items[0]);

$total = count($items) + $score;

echo $name . ':' . (string) $total;

return $hasName . ':' . $missingIsEmpty . ':' . $items[1];
