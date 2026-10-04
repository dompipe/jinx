<?php
declare(strict_types=1);

final class NativeNullNode
{
    public function __construct(public int $value, public ?NativeNullNode $child = null) {}

    public function read(): int
    {
        return $this->value;
    }
}

$tail = new NativeNullNode(9);
$head = new NativeNullNode(4, $tail);
$none = null;
$side = 0;

echo json_encode([
    $head?->child?->read(),
    $none?->child?->read(),
    $head?->child?->value,
]), "\n";

function nativeNullArg(int $value): int
{
    global $side;
    $side++;
    return $value;
}

$none?->child?->read(nativeNullArg(3));
echo "SIDE:", $side, "\n";
