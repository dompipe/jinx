<?php

declare(strict_types=1);

$result = basename(__FILE__) . ':' . basename(__DIR__) . ':' . strlen(PHP_VERSION);

echo $result;

return $result;
