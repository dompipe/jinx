<?php
declare(strict_types=1);
error_reporting(E_ALL);

$a = ['n' => 1];
$b =& $a['n'];
$b += 4;
$a['m'] =& $b;
$a['m']++;
echo json_encode(['a' => $a, 'b' => $b]) . "\n";

$items = [1, 2, 3];
foreach ($items as &$value) {
    $value *= 2;
}
unset($value);
$value = 99;
echo json_encode($items) . "\n";

$items = [4, 5];
foreach ($items as &$value) {
    $value += 1;
}
$value = 20;
echo json_encode($items) . "\n";
unset($value);

$original = ['n' => 1];
$copy = $original;
$copy['n'] = 9;
echo json_encode([$original, $copy]) . "\n";

$source = 8;
$alias =& $source;
$other = 30;
$alias =& $other;
$alias++;
unset($alias);
$alias = 90;
echo json_encode([$source, $other, $alias]) . "\n";

$growth = ['n' => 2];
$alias =& $growth['n'];
$growth['a'] = 3;
$growth['b'] = 4;
$growth['c'] = 5;
$growth['d'] = 6;
$alias += 10;
echo json_encode($growth) . "\n";

$keys = [];
$keys[1] = 'int';
$keys['1'] = 'numeric-string';
$keys[true] = 'bool';
$keys[null] = 'null-key';
$keys[''] = 'empty-string';
echo json_encode($keys) . "\n";
