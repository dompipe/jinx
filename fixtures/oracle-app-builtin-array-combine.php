<?php

declare(strict_types=1);

$result = array_combine(['a', 'b'], [1, 2]);
echo 'array_combine=' . json_encode($result);
return json_encode($result);
