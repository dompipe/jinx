<?php

declare(strict_types=1);

$result = strtr('jinx compiler', 'jc', 'JC');
echo 'strtr=' . $result;
return $result;
