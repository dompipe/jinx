<?php

declare(strict_types=1);

class StaticScoreCard
{
    public static function make($name)
    {
        return new self($name);
    }

    public function __construct($name)
    {
        $this->name = $name;
        $this->total = 1;
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

$card = StaticScoreCard::make('jinx');
$first = $card->add(4);
$second = $card->add(6);
$label = $card->label();
$result = $label . ':' . $first . ':' . $second;

echo $result;

return $result;
