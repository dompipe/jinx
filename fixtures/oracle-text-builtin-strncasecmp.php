<?php

declare(strict_types=1);

$result = '' . strncasecmp('JINX-native', 'jinx-web', 4);
echo 'strncasecmp=' . $result;
return $result;
