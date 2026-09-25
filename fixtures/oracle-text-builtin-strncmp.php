<?php

declare(strict_types=1);

$result = '' . strncmp('jinx-native', 'jinx-web', 4);
echo 'strncmp=' . $result;
return $result;
