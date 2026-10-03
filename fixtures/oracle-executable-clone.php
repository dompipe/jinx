<?php

declare(strict_types=1);

class CloneBox
{
    public function __construct($name)
    {
        $this->name = $name;
        $this->total = 1;
    }

    public function __clone()
    {
        $this->name = $this->name . '-copy';
        $this->total += 10;
    }

    public function add($amount)
    {
        $this->total += $amount;

        return $this->total;
    }

    public function label()
    {
        return strtoupper($this->name) . ':' . $this->total;
    }
}

$original = new CloneBox('jinx');
$copy = clone $original;
$copy->add(5);
$original->add(2);
$left = $original->label();
$right = $copy->label();
$result = $left . '|' . $right;

echo $result;

return $result;
