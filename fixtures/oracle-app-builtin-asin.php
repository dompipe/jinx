<?php

declare(strict_types=1);

$result = number_format(asin(0), 2, '.', '');
echo 'asin=' . $result;
return $result;
