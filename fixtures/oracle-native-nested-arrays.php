<?php
declare(strict_types=1);
$a = ['x' => ['n' => 1]];
$b = $a;
$b['x']['n'] = 7;
echo json_encode(['a' => $a, 'b' => $b]) . "\n";
$deep = ['x' => ['y' => [3, 4]]];
$copy = $deep;
$copy['x']['y'][1] += 6;
$alias =& $copy['x']['y'][0];
$alias = 8;
echo json_encode([$deep, $copy, $copy['x']['y'][1]]) . "\n";
