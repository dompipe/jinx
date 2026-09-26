<?php

declare(strict_types=1);

// Backward-compatible alias for older docs/commands.
// The canonical benchmark script is benchmark-native-jinx-vs-php.php.
// This wrapper intentionally captures and filters stdout because benchmarked
// PHP functions may print JSON, serialized payloads, or other machine output.

$script = __DIR__ . '/benchmark-native-jinx-vs-php.php';
$args = array_slice($argv, 1);
$cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($script);

foreach ($args as $arg) {
    $cmd .= ' ' . escapeshellarg($arg);
}

$lines = [];
exec($cmd . ' 2>&1', $lines, $code);

foreach (safeBenchmarkLines($lines) as $line) {
    echo $line . PHP_EOL;
}

exit((int) $code);

/** @param list<string> $lines @return list<string> */
function safeBenchmarkLines(array $lines): array
{
    $safe = [];
    $redacted = 0;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $safe[] = '';
            continue;
        }

        if (isAllowedBenchmarkLine($trimmed)) {
            $safe[] = $line;
            continue;
        }

        if (looksLikeMachinePayload($trimmed)) {
            $redacted++;
            continue;
        }

        // Keep ordinary human-readable diagnostics, but block opaque dense data.
        if (strlen($trimmed) <= 160 && preg_match('/^[A-Za-z0-9_\.\/\- :;,()\[\]\+%=<>!]+$/', $trimmed) === 1) {
            $safe[] = $line;
            continue;
        }

        $redacted++;
    }

    if ($redacted > 0) {
        $safe[] = '';
        $safe[] = "Filtered {$redacted} machine-output line(s). Set JINX_BENCH_VERBOSE=1 and run the canonical script directly if raw output is needed.";
    }

    return $safe;
}

function isAllowedBenchmarkLine(string $line): bool
{
    if ($line === str_repeat('-', 62)) {
        return true;
    }

    $allowedPrefixes = [
        'PHP vs native ./jinx all-functions benchmark',
        'Build path:',
        'Native names:',
        'PHP cases:',
        'Iterations per function:',
        'engine',
        'php',
        './jinx native',
        'Native summary:',
        'Functions:',
        'Total functions:',
        'Iterations:',
        'Total dispatches:',
        'Elapsed ns:',
        'Elapsed ms:',
        'Per dispatch ns:',
        'First PHP-side skipped cases:',
        'Filtered ',
        'FAIL:',
    ];

    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($line, $prefix)) {
            return true;
        }
    }

    return false;
}

function looksLikeMachinePayload(string $line): bool
{
    if (strlen($line) > 160) {
        return true;
    }

    if (preg_match('/^\s*[\[{]/', $line) === 1) {
        return true;
    }

    if (preg_match('/^(a|O|s|i|b|d|N):\d*[:;]/', $line) === 1) {
        return true;
    }

    if (preg_match('/^\s*"[A-Za-z0-9_\-]+"\s*:/', $line) === 1) {
        return true;
    }

    $needles = [
        '"opcode"',
        '"op"',
        '"args"',
        '"serialized"',
        '"zval"',
        '"type"',
        '"value"',
        'serialize(',
        'unserialize(',
        'array(',
        'stdClass Object',
    ];

    foreach ($needles as $needle) {
        if (str_contains($line, $needle)) {
            return true;
        }
    }

    return false;
}
