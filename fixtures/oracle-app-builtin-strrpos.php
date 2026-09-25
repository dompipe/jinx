<?php

declare(strict_types=1);

$result = strrpos('one two one two', 'two');
echo 'strrpos=' . $result;
return $result;
