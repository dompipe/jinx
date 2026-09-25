<?php

declare(strict_types=1);

$result = strip_tags('<p>Jinx <strong>PHP</strong></p>');
echo 'strip_tags=' . $result;
return $result;
