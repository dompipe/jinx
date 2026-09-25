<?php

declare(strict_types=1);

$host = '127.0.0.1';
$port = '8099';
$router = 'scripts/demo-no-js-islands.php';
$url = "http://{$host}:{$port}/";

if (isset($argv[1]) && ($argv[1] === '--help' || $argv[1] === '-h')) {
    echo "JINX no-JS islands demo server helper" . PHP_EOL;
    echo PHP_EOL;
    echo "This script is intentionally safe for ./jinx execution. It prints the" . PHP_EOL;
    echo "browser-server command instead of trying to own the terminal process." . PHP_EOL;
    echo PHP_EOL;
}

echo "JINX no-JS islands demo" . PHP_EOL;
echo PHP_EOL;
echo "Run this browser server from the repository root:" . PHP_EOL;
echo PHP_EOL;
echo "php -S {$host}:{$port} {$router}" . PHP_EOL;
echo PHP_EOL;
echo "Then open:" . PHP_EOL;
echo PHP_EOL;
echo $url . PHP_EOL;
echo PHP_EOL;
echo "Notes:" . PHP_EOL;
echo "- Use the .php filename, not .phphp." . PHP_EOL;
echo "- ./jinx can verify this helper and the page definitions." . PHP_EOL;
echo "- php -S is still the HTTP listener the browser needs for iframe islands." . PHP_EOL;
