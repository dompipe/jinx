<?php
declare(strict_types=1);
function nativeUnion(int|string $value): int|string { return $value; }
function nativeNullable(bool $set): ?string { return $set ? 'yes' : null; }
function nativeNullableArg(string|null $value): string|null { return $value; }
function nativeBadReturn(): int|string { return false; }
function nativeBadNullable(): ?string { return 7; }
function nativeSkipped(): int { return 'bad'; }
echo json_encode([nativeUnion(7), nativeUnion('x'), nativeNullable(true), nativeNullable(false)]) . "\n";
echo json_encode([nativeNullableArg(null), nativeNullableArg('ok')]) . "\n";
try { $value = nativeUnion([]); } catch (TypeError $e) { echo "ARG:TypeError\n"; }
try { $value = nativeBadReturn(); } catch (TypeError $e) { echo "RETURN:TypeError\n"; }
try { $value = nativeBadNullable(); } catch (TypeError $e) { echo "NULLABLE:TypeError\n"; }
echo json_encode([true ? 3 : nativeSkipped(), false ? nativeSkipped() : 4, '0' ? 5 : 6, '' ?: 'empty', [] ? 7 : 8, [1] ? 9 : 10]) . "\n";
$arrow = fn(int|string $value): int|string => $value;
echo json_encode([$arrow(2), $arrow('two')]) . "\n";
try { $value = true ? nativeSkipped() : 1; } catch (TypeError $e) { echo "YES:TypeError\n"; }
try { $value = false ? 1 : nativeSkipped(); } catch (TypeError $e) { echo "NO:TypeError\n"; }
