<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$jinxCommand = escapeshellarg($jinx);

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run_native_jinx(string $label, string $command, string $expected): void
{
    global $jinxCommand;

    $out = [];
    $code = 0;
    exec($jinxCommand . ' ' . $command . ' 2>&1', $out, $code);
    $text = implode(PHP_EOL, $out) . (count($out) ? PHP_EOL : '');

    if ($code !== 0) {
        fail("{$label} failed:\n{$text}");
    }

    if (!str_contains($text, $expected)) {
        fail("{$label} did not produce expected marker {$expected}:\n{$text}");
    }

    echo "PASS: {$label}" . PHP_EOL;
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

echo "JINX native high-value edge fixture suite" . PHP_EOL;
echo "Binary: {$jinx}" . PHP_EOL;

run_native_jinx(
    'native compiled Oracle artifact parity',
    'scripts/test-native-oracle-expressions.php',
    'PASS: structured native Oracle expressions'
);

run_native_jinx(
    'strict PHP replacement regression gate',
    'scripts/audit-native-php-replacement.php --gate',
    'REPLACEMENT REGRESSION GATE: 30/30'
);

run_native_jinx(
    'php vs jinx high-value edge coverage audit',
    'scripts/test-php-jinx-high-value-edge-coverage-audit.php',
    'PASS: PHP vs JINX high-value edge coverage audit validates 40 fixtures across 20 semantic areas and focused-suite wiring'
);

run_native_jinx(
    'oracle modern php recording',
    'scripts/test-oracle-modern-php-recording.php',
    'PASS: Oracle modern PHP recorder distinguishes high-risk PHP 8+ semantic constructs without claiming execution'
);

run_native_jinx(
    'php vs jinx high-value edge fixtures wave one',
    'scripts/test-php-jinx-high-value-edge-fixtures.php',
    'PASS: PHP vs JINX high-value edge fixtures checked 20 fixtures across 10 semantic areas'
);

run_native_jinx(
    'php vs jinx high-value edge fixtures wave two',
    'scripts/test-php-jinx-high-value-edge-fixtures-two.php',
    'PASS: PHP vs JINX high-value edge fixtures wave two checked 20 fixtures across 10 semantic areas'
);

echo "PASS: native high-value edge fixture suite waves 1-2" . PHP_EOL;
