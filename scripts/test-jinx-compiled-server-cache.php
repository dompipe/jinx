<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/JinxCompiledCache.php';

use jinx\server\JinxCompiledCache;

$root = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function same(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$index = $root . '/web/index.php';
$strlen = $root . '/web/strlen.php';

same(JinxCompiledCache::runPhpFile($index), 5, 'compiled web index result');
same(JinxCompiledCache::runPhpFile($strlen), 6, 'compiled web strlen result');

$compiledIndex = JinxCompiledCache::compiledPathFor($index);

if (!is_file($compiledIndex)) {
    fail('compiled cache file was not created');
}

$mtime1 = filemtime($compiledIndex);
usleep(100000);
same(JinxCompiledCache::runPhpFile($index), 5, 'compiled web index cached result');
$mtime2 = filemtime($compiledIndex);

same($mtime2, $mtime1, 'compiled cache does not rebuild when source unchanged');

echo "PASS: php -S router can use cached compiled JINX/coalesced Oracle code\n";
