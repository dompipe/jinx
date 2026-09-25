<?php

declare(strict_types=1);

namespace Dompipe\Jinx\Fixtures;

use RuntimeException as ImportedRuntimeException;

interface RenderableFixture
{
    public function render(): string;
}

trait CountsFixture
{
    public function bump(int $step): int
    {
        return $this->value + $step;
    }
}

enum FixtureMode: string
{
    case One = 'one';
}

final class ArbitraryFixture implements RenderableFixture
{
    use CountsFixture;

    private int $value = 1;

    public static function make(): self
    {
        return new self();
    }

    public function render(): string
    {
        return (string) $this->value;
    }
}

function arbitrary_entry(array $payload): string
{
    global $globalCounter;
    static $calls = 0;

    $calls = $calls + 1;
    $object = new ArbitraryFixture();
    $object = ArbitraryFixture::make();
    $next = $object->bump(2);
    $rendered = $object->render();
    $mode = FixtureMode::One;
    $property = $object->value;
    $value = $object->value ?? null;

    if (isset($payload['name']) && !empty($payload['name'])) {
        $rendered = (string) $payload['name'];
    }

    switch ($mode->value) {
        case 'one':
            $rendered = $rendered . ':' . $next;
            break;
        default:
            $rendered = 'default';
            break;
    }

    do {
        $calls++;
        continue;
    } while ($calls < 1);

    $mapped = match ($rendered) {
        'default' => 'fallback',
        default => $rendered,
    };

    unset($payload['unused']);

    if ($mapped === '') {
        throw new ImportedRuntimeException('empty render');
    }

    return $mapped;
}
