<?php

declare(strict_types=1);

$a = true;
$b = false;
$result = ($a && !$b) ? 'bool-pass' : 'bool-fail';

echo $result;

return $result;
