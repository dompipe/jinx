<?php

declare(strict_types=1);

$result = wordwrap('abcdefghi', 3, '|', true);
echo 'wordwrap=' . $result;
return $result;
