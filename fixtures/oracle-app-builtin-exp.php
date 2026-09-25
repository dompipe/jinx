<?php

declare(strict_types=1);

$result = number_format(exp(1), 6, '.', '');
echo 'exp=' . $result;
return $result;
