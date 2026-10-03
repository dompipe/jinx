<?php
declare(strict_types=1);

$counter = 1;
$first = require __DIR__ . '/oracle-native-source-helper.php';
$second = include __DIR__ . '/oracle-native-source-helper.php';
$skipped = require_once __DIR__ . '/oracle-native-source-helper.php';
$name = strtoupper('oracle');
echo strtolower($name), ':', strlen($name), ':', $first, ':', $second, ':', $skipped, ':', $counter, "\n";
echo ((8 * 3) + (8 % 3) - 3), ':', abs(-7), "\n";
return $counter;
