<?php

declare(strict_types=1);

$value = ['a', 'b'];
$result = is_array($value);
echo $result ? 'array' : 'not-array';
return $result;
