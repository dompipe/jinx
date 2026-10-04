<?php
declare(strict_types=1);

final class NativeMagicBox
{
    private array $data = ['x' => 3];

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return [$name, $arguments];
    }
}

$box = new NativeMagicBox();
echo json_encode([$box->x, $box->missing]), "\n";
$box->y = 8;
echo $box->y, "\n";
echo json_encode($box->sum(2, 5)), "\n";
