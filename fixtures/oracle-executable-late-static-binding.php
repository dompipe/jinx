<?php

declare(strict_types=1);

class EdgeLateBase
{
    public static string $name = 'base';

    public static function who(): string
    {
        return static::class . ':' . static::$name . ':' . self::$name;
    }

    public static function make(): static
    {
        return new static();
    }

    public function label(): string
    {
        return static::class;
    }
}

class EdgeLateChild extends EdgeLateBase
{
    public static string $name = 'child';
}

$a = EdgeLateBase::who();
$b = EdgeLateChild::who();
$c = EdgeLateChild::make();
$d = $c->label();
echo $a . '|' . $b . '|' . $d;
return $a . '|' . $b . '|' . $d;
