<?php
declare(strict_types=1);

class NativeStaticBase
{
    public static function who(): string
    {
        return static::class;
    }

    public static function make(): static
    {
        return new static();
    }
}

final class NativeStaticChild extends NativeStaticBase {}

echo NativeStaticBase::who(), "\n";
echo NativeStaticChild::who(), "\n";
echo NativeStaticChild::make()::class, "\n";
