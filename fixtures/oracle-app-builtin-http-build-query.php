<?php

declare(strict_types=1);

$result = http_build_query(['q' => 'jinx compiler', 'page' => 2]);
echo 'http_build_query=' . $result;
return $result;
