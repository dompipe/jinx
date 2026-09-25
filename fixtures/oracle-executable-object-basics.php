<?php

declare(strict_types=1);

class ScoreCard
{
    public function __construct($name)
    {
        $this->name = $name;
        $this->total = 0;
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

$card = new ScoreCard('jinx');
$card->add(7);
$card->add(5);
$result = $card->label();

echo $result;

return $result;
