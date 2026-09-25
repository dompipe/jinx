<?php

declare(strict_types=1);

$value = '123.5';
$result = is_numeric($value);
echo $result ? 'numeric' : 'not-numeric';
return $result;
