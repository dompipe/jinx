<?php

declare(strict_types=1);

$result = number_format(deg2rad(180), 6, '.', '');
echo 'deg2rad=' . $result;
return $result;
