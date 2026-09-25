<?php

declare(strict_types=1);

$result = array_fill(1, 3, 'x');
echo 'array_fill=' . json_encode($result);
return json_encode($result);
