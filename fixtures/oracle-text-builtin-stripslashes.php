<?php

declare(strict_types=1);

$result = stripslashes("Jinx\\'s \\\"Oracle\\\"");
echo 'stripslashes=' . $result;
return $result;
