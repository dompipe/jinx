<?php
declare(strict_types=1);

function nativeFinallyReturn(): int
{
    try {
        return 1;
    } finally {
        return 2;
    }
}

function nativeFinallyPreserve(): int
{
    try {
        return 3;
    } finally {
        echo "cleanup\n";
    }
}

echo nativeFinallyReturn(), "\n";
echo nativeFinallyPreserve(), "\n";
try {
    try {
        throw new RuntimeException('first');
    } finally {
        throw new InvalidArgumentException('replacement');
    }
} catch (RuntimeException $e) {
    echo "wrong\n";
} catch (InvalidArgumentException $e) {
    echo $e::class, "\n";
}
