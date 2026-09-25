<?php

declare(strict_types=1);

$result = array_diff(['a', 'b', 'c'], ['b']);
echo 'array_diff=' . json_encode($result);
return json_encode($result);
