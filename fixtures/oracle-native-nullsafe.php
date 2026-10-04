<?php
declare(strict_types=1);

final class NativeNullNode
{
    public function __construct(public $value, public $child = null) {}

    public function read()
    {
        return $this->value;
    }
}

$tail = new NativeNullNode(9);
$head = new NativeNullNode(4, $tail);
$none = null;

echo json_encode([
    $head?->child?->read(),
    $none?->child?->read(),
    $head?->child?->value,
]), "\n";

function nativeNullArg(): int
{
    echo "BAD\n";
    return 3;
}

$none?->read(nativeNullArg());
echo "SIDE:0\n";
