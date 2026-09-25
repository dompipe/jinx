<?php

declare(strict_types=1);

$result = var_export(['a' => 1, 'b' => 'two'], true);
echo 'var_export=' . $result;
return $result;
