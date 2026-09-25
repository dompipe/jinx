<?php

declare(strict_types=1);

$rows = [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Linus']];
$result = array_column($rows, 'name');
echo 'array_column=' . json_encode($result);
return json_encode($result);
