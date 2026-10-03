<?php
declare(strict_types=1);
class NativePrivateClone {
    private int $n = 2;
    private static int $secret = 9;
    public function bump(): int { return ++$this->n; }
    private function hidden(int $n): int { return $n + $this->n; }
    public function nested(int $n): int { return $this->hidden($n); }
    public function broken(): int { return $this->hidden('bad'); }
    public function secret(): int { return NativePrivateClone::$secret; }
}
$a = new NativePrivateClone();
$b = clone $a;
echo json_encode([$a->bump(), $b->bump(), $a->bump(), $b->bump()]) . "\n";
echo $b->nested(3) . "\n";
try { echo $a->n; } catch (Throwable $e) { echo 'PRIVATE:' . $e::class . "\n"; }
try { echo $a->hidden(1); } catch (Throwable $e) { echo 'METHOD:' . $e::class . "\n"; }
try { echo $a->nested('bad'); } catch (Throwable $e) { echo 'TYPE:' . $e::class . "\n"; }
try { echo $a->missing(); } catch (Throwable $e) { echo 'MISSING:' . $e::class . "\n"; }
try { echo $a->broken(); } catch (Throwable $e) { echo 'NESTED:' . $e::class . "\n"; }
try { echo NativePrivateClone::$secret; } catch (Throwable $e) { echo 'STATIC:' . $e::class . "\n"; }
echo $a->secret() . "\n";
class NativeCloneHook {
    public int $n = 3;
    public function __clone() { $this->n += 10; }
}
$original = new NativeCloneHook();
$copy = clone $original;
echo json_encode([$original->n, $copy->n]) . "\n";
echo $a->bump() . "\n";
