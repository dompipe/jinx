<?php

declare(strict_types=1);

$value = true;
$result = is_bool($value);
echo $result ? 'bool' : 'not-bool';
return $result;
