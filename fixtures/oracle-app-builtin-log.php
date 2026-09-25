<?php

declare(strict_types=1);

$result = number_format(log(100, 10), 2, '.', '');
echo 'log=' . $result;
return $result;
