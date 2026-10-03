<?php

declare(strict_types=1);

function edgeGeneratorSend(): Generator
{
    $incoming = yield 'ready';
    yield $incoming * 2;
    return $incoming * 3;
}

$g = edgeGeneratorSend();
$a = $g->current();
$b = $g->send(5);
$g->next();
$r = $g->getReturn();
echo $a . '|' . $b . '|' . $r;
return $a . '|' . $b . '|' . $r;
