<?php
declare(strict_types=1);

enum NativeMode: string
{
    case A = 'a';
    case B = 'b';
}

function nativeModeLabel($mode): string
{
    return match ($mode) {
        NativeMode::A => 'alpha',
        NativeMode::B => 'beta',
    };
}

echo NativeMode::A->name, ":", NativeMode::A->value, "\n";
echo nativeModeLabel(NativeMode::B), "\n";
echo NativeMode::from('a')->name, "\n";
echo json_encode(NativeMode::tryFrom('z')), "\n";

try {
    match (3) {
        4 => 'no',
    };
} catch (UnhandledMatchError $e) {
    echo "MATCH:", $e::class, "\n";
}
