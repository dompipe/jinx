<?php

declare(strict_types=1);

$result = '' . levenshtein('oracle', 'orakel');
echo 'levenshtein=' . $result;
return $result;
