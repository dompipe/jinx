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
class NativeBoundClosure {
    private int $n = 4;
    public function make(): Closure { return fn (int $x): int => $this->n + $x; }
    public function ordinary(): Closure { return function (int $x): int { return $this->n + $x; }; }
}
$bound = (new NativeBoundClosure())->make();
$ordinary = (new NativeBoundClosure())->ordinary();
echo json_encode([$bound(6), $ordinary(7)]) . "\n";
$outer = 5;
$arrow = fn (int $x): int => $outer + $x;
$outer = 20;
echo json_encode([$arrow(2), $outer]) . "\n";
