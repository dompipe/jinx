<?php

declare(strict_types=1);

$result = range(2, 5);
echo 'range=' . implode('-', $result);
return implode('-', $result);
