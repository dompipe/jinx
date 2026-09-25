<?php

declare(strict_types=1);

$result = serialize(['a' => 1, 'b' => 'two']);
echo 'serialize=' . $result;
return $result;
