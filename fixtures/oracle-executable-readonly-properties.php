<?php

declare(strict_types=1);

class EdgeReadonlyBox
{
    public readonly int $n;

    public function __construct(int $n)
    {
        $this->n = $n;
    }
}

$o = new EdgeReadonlyBox(4);
echo $o->n;
try {
    $o->n = 9;
    echo ':BAD';
} catch (Error $e) {
    echo ':ERR';
}
return $o->n;
