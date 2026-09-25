<?php

declare(strict_types=1);

$result = print_r(['a' => 1, 'b' => 'two'], true);
echo 'print_r=' . $result;
return $result;
