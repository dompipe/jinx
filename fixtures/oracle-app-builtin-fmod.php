<?php

declare(strict_types=1);

$result = number_format(fmod(17, 5), 2, '.', '');
echo 'fmod=' . $result;
return $result;
