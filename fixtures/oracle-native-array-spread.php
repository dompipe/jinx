<?php
declare(strict_types=1);
$left = [0 => 'zero', 'a' => 1];
$right = ['a' => 2, 'b' => 3, 9 => 'nine'];
echo json_encode([...$left, ...$right, 'c' => 4]) . "\n";
echo json_encode([8, ...[], ...[20 => 9, 30 => 10], 11]) . "\n";
$nested = ['n' => [1, 2]];
$copy = [...$nested];
$copy['n'] = [9];
echo json_encode([$nested, $copy]) . "\n";
$notArray = 7;
try {
    $invalid = [...$notArray];
} catch (Error $e) {
    echo "ERR:Error\n";
}
