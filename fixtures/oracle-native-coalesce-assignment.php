<?php
declare(strict_types=1);
function nativeCoalesceFail(): int { return 'invalid'; }
$data = [];
$data['n'] ??= 4;
$data['n'] ??= nativeCoalesceFail();
$object = new stdClass();
$object->value ??= 6;
$object->value ??= nativeCoalesceFail();
echo json_encode([$data, $object->value]) . "\n";
$missing ??= 8;
$zero = 0;
$zero ??= 9;
$false = false;
$false ??= 10;
$null = null;
$alias =& $null;
$alias ??= 11;
echo json_encode([$missing, $zero, $false, $null, $alias]) . "\n";
class NativeCoalesceTyped {
    public int $value;
    public static int $shared;
}
$typed = new NativeCoalesceTyped();
$typed->value ??= 12;
$typed->value ??= nativeCoalesceFail();
NativeCoalesceTyped::$shared ??= 13;
NativeCoalesceTyped::$shared ??= nativeCoalesceFail();
echo json_encode([$typed->value, NativeCoalesceTyped::$shared]) . "\n";
try { $typed->value = 'bad'; } catch (TypeError $e) { echo "TYPE:TypeError\n"; }
try { $needsValue ??= nativeCoalesceFail(); } catch (TypeError $e) { echo "RHS:TypeError\n"; }
