<?php

declare(strict_types=1);

$result = str_starts_with('oracle-runtime', 'oracle');
echo 'starts=' . $result;
return $result;
