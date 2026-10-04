<?php
declare(strict_types=1);
class NativeCallbackTarget {
    public int $n = 3;
    public static function twice(int $n): int { return $n * 2; }
    public function add(int $n): int { return $this->n + $n; }
    private function hidden(int $n): int { return $n; }
}
function nativeCallbackIncrement(int $n): int { return $n + 1; }
$values = array_map([NativeCallbackTarget::class, 'twice'], [2, 4, 6]);
$sum = array_reduce($values, fn (int $carry, int $value): int => $carry + $value, 0);
echo json_encode(['values' => $values, 'sum' => $sum]) . "\n";
echo json_encode(array_map('nativeCallbackIncrement', ['x' => 2, 4 => 5])) . "\n";
$target = new NativeCallbackTarget();
echo json_encode(array_map([$target, 'add'], [1, 2])) . "\n";
echo call_user_func([NativeCallbackTarget::class, 'twice'], 5) . "\n";
echo call_user_func(fn (int $x): int => $x + 3, 4) . "\n";
echo json_encode(array_map('strtoupper', ['a', 'b'])) . "\n";
echo json_encode([array_map('nativeCallbackIncrement', []), array_reduce([], fn ($a, $b) => $a, 9), array_reduce([], fn ($a, $b) => $a)]) . "\n";
$count = 0;
$counter = function (int $n) use (&$count): int { return ++$count + $n; };
echo json_encode([array_map($counter, [1, 2]), $count]) . "\n";
try { echo array_map('nativeMissingCallback', []); } catch (Throwable $e) { echo 'EMPTY:' . $e::class . "\n"; }
try { echo call_user_func([$target, 'hidden'], 1); } catch (Throwable $e) { echo 'PRIVATE:' . $e::class . "\n"; }
try { echo array_map(fn (int $n): int => $n, ['bad']); } catch (Throwable $e) { echo 'TYPE:' . $e::class . "\n"; }
echo $target->n . "\n";
echo json_encode(array_map([NativeCallbackTarget::class, 'twice'], ['2', true])) . "\n";
echo json_encode(array_map(fn (string $n): string => $n, [3, false])) . "\n";
echo json_encode(array_map(fn (bool $n): bool => $n, [0, '0', 'yes'])) . "\n";
echo json_encode(array_map(null, ['x' => 3, 4 => 2])) . "\n";
try { echo call_user_func([NativeCallbackTarget::class, 'twice'], '2'); } catch (Throwable $e) { echo 'STRICT:' . $e::class . "\n"; }
echo json_encode(array_map([NativeCallbackTarget::class, 'twice'], [' 2 ', '-3', '+4'])) . "\n";
try { echo array_map([NativeCallbackTarget::class, 'twice'], [' ']); } catch (Throwable $e) { echo 'SPACE:' . $e::class . "\n"; }
$original = ['x' => 3];
$identity = array_map(null, $original);
$identity['x'] = 8;
echo json_encode([$original['x'], $identity['x']]) . "\n";
