<?php

declare(strict_types=1);

$result = number_format(tan(0), 2, '.', '');
echo 'tan=' . $result;
return $result;
