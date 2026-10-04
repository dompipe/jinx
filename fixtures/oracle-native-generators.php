<?php
declare(strict_types=1);

function nativeGenerator(): Generator
{
    yield 'a' => 1;
    yield from [2, 3];
    return 9;
}

$generator = nativeGenerator();
$values = [];
foreach ($generator as $key => $value) {
    $values[] = [$key, $value];
}

echo json_encode($values), "\n";
echo "RETURN:", $generator->getReturn(), "\n";
