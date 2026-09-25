<?php

declare(strict_types=1);

$result = number_format(acos(1), 2, '.', '');
echo 'acos=' . $result;
return $result;
