<?php

declare(strict_types=1);

$result = json_encode(str_getcsv('jinx,"oracle,asm",pasm'));
echo 'str_getcsv=' . $result;
return $result;
