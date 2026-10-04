<?php
declare(strict_types=1);
class NativePromotedBox {
    public function __construct(public int $n) {}
}
class NativePromotedHolder {
    public function __construct(public NativePromotedBox $box) {}
    public function __clone() { $this->box = clone $this->box; $this->box->n++; }
}
$a = new NativePromotedHolder(new NativePromotedBox(3));
$b = clone $a;
$b->box->n += 10;
echo json_encode([$a->box->n, $b->box->n]) . "\n";
class NativeShallowHolder {
    public function __construct(public NativePromotedBox $box) {}
}
$shallow = new NativeShallowHolder(new NativePromotedBox(2));
$other = clone $shallow;
$other->box->n += 5;
echo json_encode([$shallow->box->n, $other->box->n]) . "\n";
try { echo (new NativePromotedBox('bad'))->n; } catch (Throwable $e) { echo 'ARG:' . $e::class . "\n"; }
try { echo (new NativePromotedBox())->n; } catch (Throwable $e) { echo 'COUNT:' . $e::class . "\n"; }
try { echo (new NativePromotedHolder(2))->box; } catch (Throwable $e) { echo 'OBJECT:' . $e::class . "\n"; }
try { $a->box = new NativeShallowHolder(new NativePromotedBox(1)); } catch (Throwable $e) { echo 'PROPERTY:' . $e::class . "\n"; }
echo $a->box->n . "\n";
class NativePrivatePromotion {
    public function __construct(private int $n) { $this->n += 1; }
    public function value(): int { return $this->n; }
}
$private = new NativePrivatePromotion(4);
echo $private->value() . "\n";
try { echo $private->n; } catch (Throwable $e) { echo 'PRIVATE:' . $e::class . "\n"; }
class NativePromotedChild extends NativePromotedBox {}
$child = new NativePromotedHolder(new NativePromotedChild(7));
echo $child->box->n . "\n";
try { echo (new NativePromotedHolder($shallow))->box; } catch (Throwable $e) { echo 'CLASS:' . $e::class . "\n"; }
