<?php

declare(strict_types=1);

namespace Dompipe\Jinx\Fixtures\ExecutableArbitrary;

use RuntimeException as ImportedRuntimeException;

$globalCounter = 10;

function zend_big_entry(array $payload): string
{
    global $globalCounter;
    static $calls = 0;

    $calls = $calls + 1;
    $name = 'anon';

    if (isset($payload['name']) && !empty($payload['name'])) {
        $name = (string) $payload['name'];
    }

    switch ($payload['mode'] ?? 'one') {
        case 'one':
            $suffix = 'one';
            break;
        case 'two':
            $suffix = 'two';
            break;
        default:
            $suffix = 'fallback';
            break;
    }

    do {
        $globalCounter++;
        continue;
    } while ($globalCounter < 11);

    $mapped = match ($suffix) {
        'one' => $name . ':' . $calls . ':' . $globalCounter,
        default => 'fallback',
    };

    unset($payload['unused']);

    if ($mapped === '') {
        throw new ImportedRuntimeException('empty mapped');
    }

    return $mapped;
}

$result = zend_big_entry(['name' => 'JINX', 'mode' => 'one', 'unused' => 'x']);

echo $result;

return $result;
