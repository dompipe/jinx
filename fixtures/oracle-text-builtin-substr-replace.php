<?php

declare(strict_types=1);

$result = substr_replace('jinx-core', 'oracle', 5, 4);
echo 'substr_replace=' . $result;
return $result;
