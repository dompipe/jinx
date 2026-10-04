<?php
declare(strict_types=1);

class NativeFactoryBase
{
    public static function make(): static
    {
        return new static();
    }
}

class NativeFactoryChild extends NativeFactoryBase
{
    public static function throughParent(): static
    {
        return parent::make();
    }
}

echo json_encode([
    NativeFactoryBase::make()::class,
    NativeFactoryChild::make()::class,
    NativeFactoryChild::throughParent()::class,
]), "\n";
