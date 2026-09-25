<?php

declare(strict_types=1);

$result = number_format(1234.567, 2, '.', ',');
echo 'number_format=' . $result;
return $result;
