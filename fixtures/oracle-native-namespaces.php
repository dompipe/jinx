<?php
declare(strict_types=1);

namespace NativeAlpha;

function value(): int
{
    return 11;
}

class Box
{
    public function read(): int
    {
        return value();
    }
}

namespace NativeBeta;

use NativeAlpha\Box as AlphaBox;
use function NativeAlpha\value as alphaValue;

$box = new AlphaBox();
echo alphaValue(), "\n";
echo $box->read(), "\n";
echo AlphaBox::class, "\n";
