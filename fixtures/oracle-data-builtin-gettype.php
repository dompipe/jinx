<?php

declare(strict_types=1);

$result = gettype(['a' => 1]);
echo 'gettype=' . $result;
return $result;
