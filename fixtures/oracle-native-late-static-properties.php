<?php
declare(strict_types=1);

class NativeLateBase
{
    public static string $name = 'base';

    public static function who(): array
    {
        return [static::class, static::$name, self::$name];
    }
}

class NativeLateChild extends NativeLateBase
{
    public static string $name = 'child';
}

echo json_encode([NativeLateBase::who(), NativeLateChild::who()]), "\n";
