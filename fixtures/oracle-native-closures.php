<?php
declare(strict_types=1);
$count = 1;
$inc = function () use (&$count): int { return ++$count; };
$count = 10;
echo json_encode([$inc(), $count, $inc()]) . "\n";
$snapshot = function () use ($count): int { return ++$count; };
$count = 30;
echo json_encode([$snapshot(), $snapshot(), $count]) . "\n";
unset($count);
echo $inc() . "\n";
$typed = function (int $n): int { return $n + 2; };
try { echo $typed('bad'); } catch (Throwable $e) { echo $e::class . "\n"; }
echo $typed(8) . "\n";
