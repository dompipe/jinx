<?php

declare(strict_types=1);

$result = hash_hmac('sha256', 'jinx', 'secret');
echo 'hash_hmac=' . $result;
return $result;
