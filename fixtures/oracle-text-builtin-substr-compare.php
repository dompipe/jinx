<?php

declare(strict_types=1);

$result = '' . substr_compare('oracle-jinx', 'jinx', 7);
echo 'substr_compare=' . $result;
return $result;
