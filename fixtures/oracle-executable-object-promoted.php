<?php

declare(strict_types=1);

final class JinxDiffBox
{
    public function __construct(private string $name) {}

    public function label(): string
    {
        return 'box:' . $this->name;
    }
}

$box = new JinxDiffBox('oracle');
echo $box->label() . "\n";
return $box->label();
