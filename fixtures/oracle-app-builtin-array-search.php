<?php

declare(strict_types=1);

$result = array_search('needle', ['hay', 'needle', 'stack'], true);
echo 'array_search=' . $result;
return $result;
