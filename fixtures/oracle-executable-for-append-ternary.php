<?php

declare(strict_types=1);

$out = [];
for ($i = 1; $i <= 5; $i++) {
    $out[] = ($i % 2 === 0) ? "even:$i" : "odd:$i";
}
echo implode('|', $out) . "\n";
return implode('|', $out);
