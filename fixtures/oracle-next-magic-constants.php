<?php

declare(strict_types=1);

$file = __FILE__;
$dir = __DIR__;
$result = strlen($file) . ':' . strlen($dir) . ':' . strlen(PHP_VERSION);

echo $result;

return $result;
