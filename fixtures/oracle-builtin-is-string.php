<?php

declare(strict_types=1);

$value = 'jinx';
$result = is_string($value);
echo $result ? 'string' : 'not-string';
return $result;
