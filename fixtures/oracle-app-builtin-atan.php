<?php

declare(strict_types=1);

$result = number_format(atan(1), 6, '.', '');
echo 'atan=' . $result;
return $result;
