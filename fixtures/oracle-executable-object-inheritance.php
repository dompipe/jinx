<?php

declare(strict_types=1);

class BaseGreeting
{
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function label(): string
    {
        return 'base:' . $this->name;
    }
}

class LoudGreeting extends BaseGreeting
{
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->suffix = '!';
    }

    public function label(): string
    {
        return strtoupper($this->name) . $this->suffix;
    }
}

$greeting = new LoudGreeting('jinx');
$result = $greeting->label();
echo $result;
return $result;
