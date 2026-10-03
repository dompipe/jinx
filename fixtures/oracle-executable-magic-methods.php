<?php

declare(strict_types=1);

class EdgeMagicBox
{
    private array $data = [];

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    public function __call(string $name, array $args): string
    {
        return 'instance:' . $name . ':' . implode(',', $args);
    }

    public static function __callStatic(string $name, array $args): string
    {
        return 'static:' . $name . ':' . implode(',', $args);
    }
}

$o = new EdgeMagicBox();
$o->x = 7;
$a = isset($o->x);
$b = $o->x;
unset($o->x);
$c = isset($o->x);
$d = $o->missing(1, 'z');
$e = EdgeMagicBox::unknown('q', 2);
echo $a . '|' . $b . '|' . $c . '|' . $d . '|' . $e;
return $a . '|' . $b . '|' . $c . '|' . $d . '|' . $e;
