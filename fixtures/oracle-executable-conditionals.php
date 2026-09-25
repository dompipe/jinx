<?php

$a = 3;
$b = 4;
$label = '';

if ($a < $b && $b === 4) {
    echo "lt\n";
    $label = 'ok';
} else {
    echo "bad\n";
    $label = 'no';
}

if ($label) {
    print strtoupper($label);
} else {
    print 'empty';
}

if (0) {
    echo ':zero';
} else {
    echo ':false';
}

return $label . ':' . strlen($label);
