<?php

declare(strict_types=1);

$result = addcslashes('AZ09', 'A..Z');
echo 'addcslashes=' . $result;
return $result;
