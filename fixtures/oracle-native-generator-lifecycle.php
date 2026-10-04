<?php
declare(strict_types=1);

function nativeLazyGenerator(int $number): Generator
{
    echo "START\n";
    $sent = yield 4 => $number;
    yield $sent;
    return 12;
}
$g = nativeLazyGenerator(3);
echo "CREATED\n";
try { $g->getReturn(); } catch (Exception $e) { echo "EARLY:", $e::class, "\n"; }
echo json_encode([$g->current(), $g->key(), $g->valid()]), "\n";
$g->rewind();
echo json_encode([$g->send(8), $g->key()]), "\n";
try { $g->rewind(); } catch (Exception $e) { echo "REWIND:", $e::class, "\n"; }
$g->next();
echo json_encode([$g->valid(), $g->current(), $g->getReturn()]), "\n";

function nativeDelegateChild(): Generator
{
    $sent = yield 'x' => 1;
    return $sent;
}
function nativeDelegateParent(): Generator
{
    $result = yield from nativeDelegateChild();
    yield 'x' => $result;
    return $result + 1;
}
$outer = nativeDelegateParent();
echo $outer->current(), "\n";
echo $outer->send(9), "\n";
$outer->next();
echo $outer->getReturn(), "\n";

function nativeRepeatedKeys(): Generator
{
    yield 'x' => 1;
    yield 'x' => 2;
    yield from [];
    return 4;
}
echo json_encode(iterator_to_array(nativeRepeatedKeys())), "\n";
echo json_encode(iterator_to_array(nativeRepeatedKeys(), false)), "\n";
