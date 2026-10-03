<?php

declare(strict_types=1);

class EdgeNullsafeNode
{
    public ?string $value = null;

    public function label(): string
    {
        return $this->value ?? 'none';
    }
}

$a = new EdgeNullsafeNode();
$a->value = 'leaf';
$b = null;
$x = $a?->label();
$y = $b?->label();
$z = $b?->value;
echo ($x ?? 'fallback') . '|' . ($y ?? 'fallback') . '|' . ($z ?? 'fallback');
return ($x ?? 'fallback') . '|' . ($y ?? 'fallback') . '|' . ($z ?? 'fallback');
