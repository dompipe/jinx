<?php
declare(strict_types=1);

readonly class NativeReadonlyBox
{
    public function __construct(public int $value) {}
}

$box = new NativeReadonlyBox(7);
echo $box->value, "\n";

try {
    $box->value = 8;
} catch (Error $e) {
    echo "WRITE:", $e::class, "\n";
}

try {
    $box->extra = 1;
} catch (Error $e) {
    echo "DYNAMIC:", $e::class, "\n";
}
