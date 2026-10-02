<?php

declare(strict_types=1);

$label = 'start';
$code = 1;

try {
    $label = 'try';
    throw new RuntimeException('oracle-miss');
    $label = 'unreachable';
} catch (RuntimeException $caught) {
    $label = 'caught';
    $code = $code + 4;
} finally {
    $label = $label . '-finally';
}

try {
    $label = $label . '-clean';
} catch (RuntimeException $caught) {
    $label = 'wrong';
} finally {
    $code = $code + 2;
}

echo $label;

return $code;
