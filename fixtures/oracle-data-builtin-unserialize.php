<?php

declare(strict_types=1);

$value = unserialize('a:2:{s:1:"a";i:1;s:1:"b";s:3:"two";}');
$result = json_encode($value);
echo 'unserialize=' . $result;
return $result;
