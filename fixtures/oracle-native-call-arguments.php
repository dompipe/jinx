<?php
declare(strict_types=1);

function nativeArgs(int $a, int $b = 20, int ...$rest): array
{
    return [$a, $b, $rest];
}

echo json_encode(nativeArgs(1)), "\n";
echo json_encode(nativeArgs(...[2, 3, 4, 5])), "\n";
echo json_encode(nativeArgs(b: 9, a: 7)), "\n";

try {
    nativeArgs(nope: 1);
} catch (Error $e) {
    echo "UNKNOWN:", $e::class, "\n";
}

try {
    nativeArgs(1, a: 2);
} catch (Error $e) {
    echo "OVERWRITE:", $e::class, "\n";
}
