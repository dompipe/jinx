<?php

declare(strict_types=1);

$result = number_format(hypot(3, 4), 2, '.', '');
echo 'hypot=' . $result;
return $result;
