<?php

declare(strict_types=1);

$host = getenv('JINX_DEMO_HOST') ?: '127.0.0.1';
$port = getenv('JINX_DEMO_PORT') ?: '8099';
$router = dirname(__DIR__) . '/scripts/demo-no-js-islands.php';
$url = "http://{$host}:{$port}/";
$command = 'php -S ' . escapeshellarg("{$host}:{$port}") . ' ' . escapeshellarg($router);

if (isset($argv[1]) && ($argv[1] === '--help' || $argv[1] === '-h')) {
    echo "JINX no-JS islands demo server" . PHP_EOL;
    echo PHP_EOL;
    echo "Starts the browser demo HTTP listener from ./jinx." . PHP_EOL;
    echo PHP_EOL;
    echo "Usage:" . PHP_EOL;
    echo "  ./jinx scripts/serve-no-js-islands-demo.php" . PHP_EOL;
    echo "  ./jinx scripts/serve-no-js-islands-demo.php --check" . PHP_EOL;
    echo PHP_EOL;
    echo "Environment:" . PHP_EOL;
    echo "  JINX_DEMO_HOST=127.0.0.1" . PHP_EOL;
    echo "  JINX_DEMO_PORT=8099" . PHP_EOL;
    exit(0);
}

if (isset($argv[1]) && $argv[1] === '--check') {
    echo "PASS: no-JS island demo server command is available" . PHP_EOL;
    echo $command . PHP_EOL;
    echo $url . PHP_EOL;
    exit(0);
}

if (!is_file($router)) {
    fwrite(STDERR, "Missing demo router: {$router}" . PHP_EOL);
    exit(1);
}

if (!function_exists('passthru')) {
    fwrite(STDERR, "Cannot start demo server: passthru() is unavailable." . PHP_EOL);
    fwrite(STDERR, "Run manually: {$command}" . PHP_EOL);
    exit(1);
}

echo "JINX no-JS islands demo server" . PHP_EOL;
echo "URL: {$url}" . PHP_EOL;
echo "Command: {$command}" . PHP_EOL;
echo PHP_EOL;
echo "Press Ctrl+C to stop." . PHP_EOL;
echo PHP_EOL;

passthru($command, $code);
exit(is_int($code) ? $code : 0);
