<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebApiCompiler.php';

use jinx\web\WebApiCompiler;

$sourceDocroot = $argv[1] ?? null;
$outputDocroot = $argv[2] ?? null;

if ($sourceDocroot === null || $outputDocroot === null) {
    fwrite(STDERR, "Usage: php scripts/build-web-docroot.php <source-docroot> <output-docroot>\n");
    exit(1);
}

if (!is_dir($sourceDocroot)) {
    fwrite(STDERR, "Missing source docroot: {$sourceDocroot}\n");
    exit(1);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($path);
}

removeTree($outputDocroot);
@mkdir($outputDocroot, 0775, true);

$compiled = 0;
$skipped = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDocroot, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $sourcePath = $file->getPathname();
    $relative = substr($sourcePath, strlen(rtrim($sourceDocroot, '/\\')));
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    $outputPath = rtrim($outputDocroot, '/\\') . '/' . $relative;

    @mkdir(dirname($outputPath), 0775, true);

    if ($file->getExtension() !== 'php') {
        copy($sourcePath, $outputPath);
        continue;
    }

    try {
        WebApiCompiler::compileFileToEndpoint($sourcePath, $outputPath);
        $compiled++;
    } catch (Throwable $e) {
        // Current compiler only supports the executable Web API subset.
        // Keep unsupported PHP files out of the compiled docroot for now.
        $skipped++;
    }
}

echo "compiled PHP endpoints: {$compiled}\n";
echo "skipped PHP files: {$skipped}\n";
echo "compiled docroot: {$outputDocroot}\n";
