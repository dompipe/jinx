<?php

declare(strict_types=1);

/**
 * PHP-side worker for the paired PHP vs compiled ./jinx benchmark.
 *
 * Usage:
 *   php scripts/benchmark-php-call.php <function> <iterations> [typed-args...]
 */

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function decodeArg(string $typed): array
{
    if ($typed === 'null') return [true, null];
    if (str_starts_with($typed, 'i:')) return [true, (int) substr($typed, 2)];
    if (str_starts_with($typed, 'f:')) return [true, (float) substr($typed, 2)];
    if (str_starts_with($typed, 'b:')) {
        $raw = strtolower(substr($typed, 2));
        return [true, $raw === 'true' || $raw === '1'];
    }
    if (str_starts_with($typed, 's:')) return [true, substr($typed, 2)];
    if (str_starts_with($typed, 'h:')) {
        $value = hex2bin(substr($typed, 2));
        return $value === false ? [false, null] : [true, $value];
    }

    return match ($typed) {
        'za:sample' => [true, [10, 20, 'name' => 30, 'keep' => 40]],
        'za:empty' => [true, []],
        'za:deleted' => [true, [0 => 10, 'keep' => 40]],
        'za:strings' => [true, ['b', 'a', 'c']],
        'za:walk' => [true, [1, 2, 3]],
        default => match (true) {
            str_starts_with($typed, 'dt:') => [true, new DateTime(substr($typed, 3))],
            str_starts_with($typed, 'dti:') => [true, new DateTimeImmutable(substr($typed, 4))],
            str_starts_with($typed, 'di:') => [
                ($v = DateInterval::createFromDateString(substr($typed, 3))) !== false,
                $v === false ? null : $v,
            ],
            str_starts_with($typed, 'tz:') => [true, new DateTimeZone(substr($typed, 3))],
            default => [false, null],
        },
    };
}

$name = $argv[1] ?? '';
$iterations = max(1, (int) ($argv[2] ?? 1));
$typedArgs = array_slice($argv, 3);

if ($name === '' || !function_exists($name)) {
    fail('missing PHP function: ' . $name);
}

$args = [];
foreach ($typedArgs as $typed) {
    try {
        [$ok, $value] = decodeArg($typed);
    } catch (Throwable $e) {
        fail('fixture decode failed: ' . $e->getMessage());
    }
    if (!$ok) fail('unsupported PHP fixture: ' . $typed);
    $args[] = $value;
}

try {
    $rf = new ReflectionFunction($name);
    foreach ($rf->getParameters() as $index => $parameter) {
        if ($index >= count($args)) break;
        if ($parameter->isPassedByReference()) {
            fail('by-reference PHP argument unsupported in paired timing');
        }
    }

    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException($message, $severity);
    });
    try {
        $name(...$args);
    } finally {
        restore_error_handler();
    }
} catch (Throwable $e) {
    fail('PHP sample rejected: ' . $e->getMessage());
}

$start = hrtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $name(...$args);
}
$elapsed = hrtime(true) - $start;

echo json_encode([
    'engine' => 'php',
    'function' => $name,
    'iterations' => $iterations,
    'elapsed_ns' => $elapsed,
    'ns_per_call' => $elapsed / $iterations,
], JSON_UNESCAPED_SLASHES), PHP_EOL;
