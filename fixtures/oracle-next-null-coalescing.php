<?php

declare(strict_types=1);

$items = ['fallback' => 'safe'];
$result = ($items['missing'] ?? $items['fallback']) . ':' . ($items['fallback'] ?? 'nope');

echo $result;

return $result;
