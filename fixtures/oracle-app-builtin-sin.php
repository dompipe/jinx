<?php

declare(strict_types=1);

$result = number_format(sin(0), 2, '.', '');
echo 'sin=' . $result;
return $result;
