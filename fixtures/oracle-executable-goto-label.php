<?php

declare(strict_types=1);

$trace = 'start';

goto finish;

$trace = 'wrong';

finish:;
$trace = $trace . ':finish';

echo $trace;

return $trace;
