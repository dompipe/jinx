<?php

declare(strict_types=1);

$result = chunk_split('abcdef', 2, '|');
echo 'chunk_split=' . $result;
return $result;
