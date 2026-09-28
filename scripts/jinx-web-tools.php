#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function usage(): never
{
    echo <<<TXT
JINX Web Helper Tools

Usage:
  php scripts/jinx-web-tools.php rc
  php scripts/jinx-web-tools.php notes
  php scripts/jinx-web-tools.php benchmarks
  php scripts/jinx-web-tools.php test
  php scripts/jinx-web-tools.php oracle-smoke
  php scripts/jinx-web-tools.php functions-count
  php scripts/jinx-web-tools.php functions
  php scripts/jinx-web-tools.php function-exists <name>
  php scripts/jinx-web-tools.php bench-wrapper-first100 [iterations]
  php scripts/jinx-web-tools.php bench-cache-server [iterations]
  php scripts/jinx-web-tools.php bench-frozen-server [iterations]
  php scripts/jinx-web-tools.php bench-docroot [iterations]
  php scripts/jinx-web-tools.php bench-socket [iterations]
  php scripts/jinx-web-tools.php bench-worker [iterations]
  php scripts/jinx-web-tools.php bench-endpoint [iterations]
  php scripts/jinx-web-tools.php serve-frozen <host:port> <docroot> [cache-root]
  php scripts/jinx-web-tools.php build-docroot <source-docroot> <output-docroot>
  php scripts/jinx-web-tools.php worker <source.php> <host:port>
  php scripts/jinx-web-tools.php hybrid-server <docroot> <host:port>
  php scripts/jinx-web-tools.php web-statements <input.php> [output.web.json]
  php scripts/jinx-web-tools.php web-compile <input.php> <output.php>
  php scripts/jinx-web-tools.php web-plan <input.php>
  php scripts/jinx-web-tools.php web-cache <input.php> [cache-root]
  php scripts/jinx-web-tools.php run-compiled <compiled.php>
  php scripts/jinx-web-tools.php -S <host:port> <docroot-or-router>
  php scripts/jinx-web-tools.php -S <host:port> --jinx-cache <docroot> [cache-root]
  php scripts/jinx-web-tools.php scripts/test-oracle-program-compiler.php

Examples:
  php scripts/jinx-web-tools.php rc
  php scripts/jinx-web-tools.php benchmarks
  php scripts/jinx-web-tools.php oracle-smoke
  php scripts/jinx-web-tools.php functions-count
  php scripts/jinx-web-tools.php test
  php scripts/jinx-web-tools.php bench-wrapper-first100 1000
  php scripts/jinx-web-tools.php web-statements fixtures/oracle-post-curl-dynamic.php build/web-statements/post.web.json
  php scripts/jinx-web-tools.php web-compile fixtures/simple-web-api-validated.php build/web-compiled/simple-web-api-validated.compiled.php
  php scripts/jinx-web-tools.php -S 127.0.0.1:8098 build/web-compiled

TXT;

    exit(1);
}

function runCommand(string $cmd): int
{
    passthru($cmd, $code);
    return (int) $code;
}

function printProjectFile(string $path): never
{
    if (!is_file($path)) {
        fwrite(STDERR, "Missing project file: {$path}" . PHP_EOL);
        exit(1);
    }

    $contents = (string) file_get_contents($path);
    echo $contents;

    if (!str_ends_with($contents, "\n")) {
        echo PHP_EOL;
    }

    exit(0);
}

$args = array_slice($argv, 1);

if ($args === []) {
    usage();
}

$cmd = array_shift($args);

if (str_ends_with($cmd, '.php')) {
    $script = $cmd;

    if (!is_file($script)) {
        $script = $root . '/' . ltrim(str_replace('\\', '/', $cmd), '/');
    }

    if (!is_file($script)) {
        fwrite(STDERR, "Missing PHP script: {$cmd}" . PHP_EOL);
        exit(1);
    }

    $shell = 'php ' . escapeshellarg($script);

    foreach ($args as $arg) {
        $shell .= ' ' . escapeshellarg($arg);
    }

    exit(runCommand($shell));
}

if ($cmd === 'rc') {
    printProjectFile($root . '/README.md');
}

if ($cmd === 'notes') {
    printProjectFile($root . '/docs/RC_NOTES.md');
}

if ($cmd === 'benchmarks') {
    printProjectFile($root . '/docs/BENCHMARKS.md');
}

if ($cmd === 'functions-count') {
    require_once $root . '/runtime/WebNativeFunctions.php';
    echo jinx\web\WebNativeFunctionRegistry::COUNT . PHP_EOL;
    exit(0);
}

if ($cmd === 'functions') {
    require_once $root . '/runtime/WebNativeFunctions.php';

    foreach (jinx\web\WebNativeFunctionRegistry::names() as $index => $name) {
        printf("%4d  %s\n", $index + 1, $name);
    }

    exit(0);
}

if ($cmd === 'function-exists') {
    $name = $args[0] ?? null;

    if ($name === null) {
        usage();
    }

    require_once $root . '/runtime/WebNativeFunctions.php';

    if (jinx\web\WebNativeFunctionRegistry::has($name)) {
        echo "present: {$name}" . PHP_EOL;
        exit(0);
    }

    echo "missing: {$name}" . PHP_EOL;
    exit(1);
}

if ($cmd === 'bench-wrapper-first100') {
    $iterations = $args[0] ?? '1000';
    $script = $root . '/scripts/benchmark-first-100-wrapper-functions.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing benchmark script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $iterations) . ' 100'));
}

if ($cmd === 'oracle-smoke') {
    $tests = [
        $root . '/scripts/test-oracle-builtin-dispatch.php',
        $root . '/scripts/test-pasm-call-builtin-through-oracle.php',
    ];

    foreach ($tests as $script) {
        if (!is_file($script)) {
            fwrite(STDERR, "Missing Oracle smoke script: {$script}" . PHP_EOL);
            exit(1);
        }

        $code = runCommand('php ' . escapeshellarg($script));

        if ($code !== 0) {
            exit($code);
        }
    }

    exit(0);
}

if ($cmd === 'bench-endpoint') {
    $iterations = $args[0] ?? '10000';
    $script = $root . '/scripts/benchmark-endpoint-execution.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing benchmark script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $iterations)));
}

if ($cmd === 'bench-worker') {
    $iterations = $args[0] ?? '2000';
    $script = $root . '/scripts/benchmark-worker.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing benchmark script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $iterations)));
}

if ($cmd === 'bench-socket') {
    $iterations = $args[0] ?? '1000';
    $script = $root . '/scripts/benchmark-docroot-socket.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing benchmark script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $iterations)));
}

if ($cmd === 'bench-docroot') {
    $iterations = $args[0] ?? '100';
    $script = $root . '/scripts/benchmark-compiled-docroot.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing benchmark script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $iterations)));
}

if ($cmd === 'bench-frozen-server') {
    $iterations = $args[0] ?? '100';
    $script = $root . '/scripts/benchmark-frozen-server.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing benchmark script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $iterations)));
}

if ($cmd === 'bench-cache-server') {
    $iterations = $args[0] ?? '100';
    $script = $root . '/scripts/benchmark-bin-jinx-cache-server.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing benchmark script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($script) . ' ' . escapeshellarg((string) $iterations)));
}

if ($cmd === 'test') {
    $script = $root . '/scripts/test-web-green-main.sh';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing test suite: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand(escapeshellarg($script)));
}

if ($cmd === 'hybrid-server') {
    $docroot = $args[0] ?? null;
    $hostPort = $args[1] ?? null;

    if ($docroot === null || $hostPort === null) {
        usage();
    }

    $script = $root . '/scripts/jinx-hybrid-server.php';

    exit(runCommand(
        'php ' . escapeshellarg($script) . ' ' .
        escapeshellarg($docroot) . ' ' .
        escapeshellarg($hostPort)
    ));
}

if ($cmd === 'worker') {
    $source = $args[0] ?? null;
    $hostPort = $args[1] ?? null;

    if ($source === null || $hostPort === null) {
        usage();
    }

    $script = $root . '/scripts/jinx-worker.php';

    exit(runCommand(
        'php ' . escapeshellarg($script) . ' ' .
        escapeshellarg($source) . ' ' .
        escapeshellarg($hostPort)
    ));
}

if ($cmd === 'build-docroot') {
    $sourceDocroot = $args[0] ?? null;
    $outputDocroot = $args[1] ?? null;

    if ($sourceDocroot === null || $outputDocroot === null) {
        usage();
    }

    $script = $root . '/scripts/build-web-docroot.php';

    exit(runCommand(
        'php ' . escapeshellarg($script) . ' ' .
        escapeshellarg($sourceDocroot) . ' ' .
        escapeshellarg($outputDocroot)
    ));
}

if ($cmd === 'serve-frozen') {
    $hostPort = $args[0] ?? null;
    $docroot = $args[1] ?? null;
    $cacheRoot = $args[2] ?? ($root . '/build/web-cache-frozen');

    if ($hostPort === null || $docroot === null || !is_dir($docroot)) {
        usage();
    }

    $manifest = $cacheRoot . '/manifest.json';
    $builder = $root . '/scripts/build-web-cache-manifest.php';
    $router = $root . '/scripts/jinx-web-frozen-router.php';

    $buildCode = runCommand(
        'php ' . escapeshellarg($builder) . ' ' .
        escapeshellarg($docroot) . ' ' .
        escapeshellarg($cacheRoot) . ' ' .
        escapeshellarg($manifest)
    );

    if ($buildCode !== 0) {
        exit($buildCode);
    }

    $shell = 'JINX_WEB_MANIFEST=' . escapeshellarg($manifest)
        . ' php -S ' . escapeshellarg($hostPort)
        . ' ' . escapeshellarg($router);

    exit(runCommand($shell));
}

if ($cmd === 'web-statements') {
    $input = $args[0] ?? null;
    $output = $args[1] ?? null;

    if ($input === null) {
        usage();
    }

    $script = $root . '/scripts/web-compile.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing script: {$script}" . PHP_EOL);
        exit(1);
    }

    $shell = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg($input);

    if ($output !== null) {
        $shell .= ' ' . escapeshellarg($output);
    }

    exit(runCommand($shell));
}

if ($cmd === 'web-compile') {
    $input = $args[0] ?? null;
    $output = $args[1] ?? null;

    if ($input === null || $output === null) {
        usage();
    }

    $script = $root . '/scripts/web-api-compile.php';

    if (!is_file($script)) {
        fwrite(STDERR, "Missing script: {$script}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand(
        'php ' . escapeshellarg($script) . ' ' .
        escapeshellarg($input) . ' ' .
        escapeshellarg($output)
    ));
}

if ($cmd === 'web-plan') {
    $input = $args[0] ?? null;

    if ($input === null) {
        usage();
    }

    require_once $root . '/runtime/WebApiCompiler.php';

    try {
        $plan = jinx\web\WebApiCompiler::compileFileToPlan($input);
        echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($cmd === 'web-cache') {
    $input = $args[0] ?? null;
    $cacheRoot = $args[1] ?? ($root . '/build/web-cache');

    if ($input === null) {
        usage();
    }

    require_once $root . '/runtime/WebCacheServer.php';

    try {
        $entry = jinx\web\WebCacheServer::compileIfStale($input, $cacheRoot);
        echo json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($cmd === 'run-compiled') {
    $compiled = $args[0] ?? null;

    if ($compiled === null) {
        usage();
    }

    if (!is_file($compiled)) {
        fwrite(STDERR, "Missing compiled file: {$compiled}" . PHP_EOL);
        exit(1);
    }

    exit(runCommand('php ' . escapeshellarg($compiled)));
}

if ($cmd === '-S') {
    $hostPort = $args[0] ?? null;
    $target = $args[1] ?? null;

    if ($hostPort === null || $target === null) {
        usage();
    }

    if ($target === '--jinx-cache') {
        $docroot = $args[2] ?? null;
        $cacheRoot = $args[3] ?? ($root . '/build/web-cache');

        if ($docroot === null || !is_dir($docroot)) {
            fwrite(STDERR, "Missing cache server docroot: " . (string) $docroot . PHP_EOL);
            exit(1);
        }

        $router = $root . '/scripts/jinx-web-cache-router.php';

        $shell = 'JINX_WEB_DOCROOT=' . escapeshellarg($docroot)
            . ' JINX_WEB_CACHE=' . escapeshellarg($cacheRoot)
            . ' php -S ' . escapeshellarg($hostPort)
            . ' ' . escapeshellarg($router);

        exit(runCommand($shell));
    }

    if (is_dir($target)) {
        exit(runCommand(
            'php -S ' . escapeshellarg($hostPort) . ' -t ' . escapeshellarg($target)
        ));
    }

    if (is_file($target)) {
        exit(runCommand(
            'php -S ' . escapeshellarg($hostPort) . ' ' . escapeshellarg($target)
        ));
    }

    fwrite(STDERR, "Missing server target: {$target}" . PHP_EOL);
    exit(1);
}

fwrite(STDERR, "Unknown command: {$cmd}" . PHP_EOL);
usage();
