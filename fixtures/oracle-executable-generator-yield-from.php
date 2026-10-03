<?php

declare(strict_types=1);

function edgeInnerGenerator(): Generator
{
    yield 1;
    yield 2;
    return 7;
}

function edgeOuterGenerator(): Generator
{
    $ret = yield from edgeInnerGenerator();
    yield $ret + 1;
    return $ret + 2;
}

$g = edgeOuterGenerator();
$a = $g->current();
$g->next();
$b = $g->current();
$g->next();
$c = $g->current();
$g->next();
$r = $g->getReturn();
echo $a . '|' . $b . '|' . $c . '|' . $r;
return $a . '|' . $b . '|' . $c . '|' . $r;
