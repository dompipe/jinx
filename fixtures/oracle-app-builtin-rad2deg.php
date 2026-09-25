<?php

declare(strict_types=1);

$result = number_format(rad2deg(3.141592653589793), 2, '.', '');
echo 'rad2deg=' . $result;
return $result;
