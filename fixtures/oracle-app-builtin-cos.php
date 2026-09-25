<?php

declare(strict_types=1);

$result = number_format(cos(0), 2, '.', '');
echo 'cos=' . $result;
return $result;
