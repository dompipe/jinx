<?php

declare(strict_types=1);

function jinx_late_strings(string $word): array
{
    $upper = strtoupper($word);
    $lower = strtolower($upper);
    $parts = [$upper, $lower, strrev($word), strlen($word)];
    return ['word' => $word, 'parts' => $parts, 'joined' => implode('|', $parts)];
}
echo json_encode(['result' => jinx_late_strings('eleventh-1')]) . "\n";
