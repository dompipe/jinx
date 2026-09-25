<?php

declare(strict_types=1);

$result = array_intersect(['a', 'b', 'c'], ['b', 'c']);
echo 'array_intersect=' . json_encode($result);
return json_encode($result);
