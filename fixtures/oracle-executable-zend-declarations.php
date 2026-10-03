<?php

declare(strict_types=1);

namespace Dompipe\Jinx\Fixtures\ExecutableZend;

use RuntimeException as ImportedRuntimeException;

interface RenderableDeclaration
{
    public function render(): string;
}

trait CountsDeclaration
{
    public function bump(int $step): int
    {
        return $this->value + $step;
    }
}

enum DeclarationMode: string
{
    case One = 'one';
}

final class DeclarationFixture implements RenderableDeclaration
{
    use CountsDeclaration;

    private int $value = 3;

    public static function make(): self
    {
        return new self();
    }

    public function render(): string
    {
        return (string) $this->value;
    }
}

$object = DeclarationFixture::make();
$next = $object->bump(4);
$mode = DeclarationMode::One;
$modeValue = $mode->value;
$result = $object->render() . ':' . $next . ':' . $modeValue;

if ($result === '') {
    throw new ImportedRuntimeException('empty declaration result');
}

echo $result;

return $result;
