<?php

declare(strict_types=1);

$result = array_chunk(['a', 'b', 'c', 'd'], 2);
echo 'array_chunk=' . json_encode($result);
return json_encode($result);
