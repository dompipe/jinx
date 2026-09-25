<?php

declare(strict_types=1);

class RuntimeOps
{
    public static int $counter = 0;
}

function runtime_ops(array $payload): iterable
{
    $items = [];
    $items['raw'] = 'raw';
    $items['name'] = $payload['name'] ?? 'anon';
    $value = $items['name'];
    $value .= '!';
    $value++;
    --$value;

    $answer = $value === 'anon!' ? 'guest' : $value;
    $closure = function (string $text): string {
        return $text;
    };
    $arrow = fn (string $text): string => strtoupper($text);
    $object = new class {
        public function render(): string
        {
            return 'anon';
        }
    };

    $clone = clone $object;
    $isRuntime = $object instanceof RuntimeOps;
    $static = RuntimeOps::$counter;

    echo $closure($answer);
    print $arrow($answer);
    \strlen($answer);

    goto finished;
    finished:;

    yield $static => $answer;

    exit($answer);
}
