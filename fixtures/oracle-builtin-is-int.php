<?php

declare(strict_types=1);

$value = 42;
$result = is_int($value);
echo $result ? 'int' : 'not-int';
return $result;
