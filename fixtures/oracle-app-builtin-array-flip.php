<?php

declare(strict_types=1);

$result = array_flip(['red', 'blue']);
echo 'array_flip=' . json_encode($result);
return json_encode($result);
