<?php
declare(strict_types=1);
function nativeAdd(int $n): int { return $n + 1; }
function nativeNested(int $n): int { $local = 30; return nativeAdd($n) + $local; }
function nativeBadReturn(): int { return 'wrong'; }
function nativeThrowInside(int $n): int { return nativeAdd('wrong'); }
function nativeText(string $text): string { return strtoupper($text); }
function nativeBool(bool $flag): bool { return $flag; }
function nativeArrayCopy($values): int { $values[0] = 8; return $values[0]; }
$n = 90;
$local = 12;
echo json_encode([nativeAdd(3), nativeNested(4), $n, $local]) . "\n";
try { echo nativeAdd('nope'); } catch (Throwable $e) { echo 'ARG:' . $e::class . "\n"; }
try { echo nativeAdd(); } catch (Throwable $e) { echo 'COUNT:' . $e::class . "\n"; }
try { echo nativeBadReturn(); } catch (Throwable $e) { echo 'RETURN:' . $e::class . "\n"; }
try { echo nativeThrowInside(1); } catch (Throwable $e) { echo 'NESTED:' . $e::class . "\n"; }
echo json_encode([nativeAdd(8), $n, $local]) . "\n";
$values = [2];
echo json_encode([nativeText('yes'), nativeBool(true), nativeArrayCopy($values), $values[0]]) . "\n";
