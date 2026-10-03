<?php

declare(strict_types=1);

$text = 'jinx fifth unit 426';
$needle = 'jinx';
$pos = strpos($text, $needle);
$out = [
    'case' => 426,
    'pos' => $pos,
    'found' => $pos !== false,
    'prefix' => substr($text, 0, 4),
    'reverse' => strrev(substr($text, -4)),
    'hash' => substr(md5($text), 0, 8),
];
echo json_encode($out) . "\n";
