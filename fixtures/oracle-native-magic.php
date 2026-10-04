<?php
declare(strict_types=1);

class NativeMagicBox
{
    private $data = ['x' => 3];

    public function __get($name)
    {
        return $this->data[$name] ?? null;
    }

    public function __set($name, $value): void
    {
        $this->data[$name] = $value;
    }

    public function __call($name, $arguments)
    {
        return [$name, $arguments];
    }
}

$box = new NativeMagicBox();
echo json_encode([$box->x, $box->missing]), "\n";
$box->y = 8;
echo $box->y, "\n";
echo json_encode($box->sum(2, 5)), "\n";
