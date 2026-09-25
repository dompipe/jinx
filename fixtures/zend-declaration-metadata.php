<?php

declare(strict_types=1);

namespace Dompipe\Jinx\Fixtures\Metadata;

use Attribute;
use RuntimeException;
use Throwable;

const GLOBAL_LIMIT = 10;

#[Attribute]
final class OracleMeta
{
    public function __construct(public readonly string $name)
    {
    }
}

#[OracleMeta('contract')]
readonly class MetadataSubject
{
    public const KIND = 'metadata';
    public string $label;

    public function __construct(public string $value)
    {
    }

    public function __destruct()
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

abstract class MetadataBase
{
    abstract protected function render(string $name, int ...$flags): string;
}

function metadata_entry(?MetadataSubject $subject, mixed $fallback = null): string
{
    try {
        if ($subject === null) {
            throw new RuntimeException('missing');
        }

        return (string) $subject;
    } catch (RuntimeException|Throwable $error) {
        return (string) $fallback;
    } finally {
        $fallback = null;
    }
}
