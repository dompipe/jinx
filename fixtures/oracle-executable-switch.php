<?php

declare(strict_types=1);

$mode = 'beta';
$score = 2;
$label = 'start';

switch ($mode) {
    case 'alpha':
        $label = 'A';
        break;
    case 'beta':
        $label = 'B';
        $score = $score + 3;
        break;
    default:
        $label = 'D';
}

switch ($score) {
    case 4:
        $label = $label . '-low';
        break;
    default:
        $label = $label . '-ok';
}

echo $label;

return $score;
