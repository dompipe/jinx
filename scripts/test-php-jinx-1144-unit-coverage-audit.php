<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$suitePath = $root . '/scripts/test-jinx-native-suite.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$expectedScripts = [
    'scripts/test-php-jinx-100-unit-cases.php' => 100,
    'scripts/test-php-jinx-100-more-unit-cases.php' => 100,
    'scripts/test-php-jinx-100-third-unit-cases.php' => 100,
    'scripts/test-php-jinx-100-fourth-unit-cases.php' => 100,
    'scripts/test-php-jinx-100-fifth-unit-cases.php' => 100,
    'scripts/test-php-jinx-100-sixth-unit-cases.php' => 100,
    'scripts/test-php-jinx-100-seventh-unit-cases.php' => 100,
    'scripts/test-php-jinx-50-eighth-unit-cases.php' => 50,
    'scripts/test-php-jinx-100-ninth-unit-cases.php' => 100,
    'scripts/test-php-jinx-100-tenth-unit-cases.php' => 100,
    'scripts/test-php-jinx-50-eleventh-unit-cases.php' => 50,
    'scripts/test-php-jinx-100-twelfth-unit-cases.php' => 100,
    'scripts/test-php-jinx-44-final-unit-cases.php' => 44,
];

if (!is_file($suitePath)) {
    fail('native suite missing: ' . $suitePath);
}

$suite = (string) file_get_contents($suitePath);
$total = 0;
$scriptCount = 0;

foreach ($expectedScripts as $relativePath => $expectedCaseCount) {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        fail('unit case script missing: ' . $relativePath);
    }

    $source = (string) file_get_contents($path);

    if (!str_contains($suite, $relativePath)) {
        fail('native suite does not wire unit case script: ' . $relativePath);
    }

    if (!str_contains($source, 'run_process([$php, $fixture])')) {
        fail('unit case script does not run PHP baseline: ' . $relativePath);
    }

    if (!str_contains($source, 'run_process([$jinx, $fixture])')) {
        fail('unit case script does not run native JINX candidate: ' . $relativePath);
    }

    if (!str_contains($source, "$checked !== {$expectedCaseCount}")) {
        fail('unit case script missing exact checked-count guard for ' . $expectedCaseCount . ': ' . $relativePath);
    }

    if (!str_contains($source, "count($" . "cases) !== {$expectedCaseCount}")) {
        fail('unit case script missing exact case-count guard for ' . $expectedCaseCount . ': ' . $relativePath);
    }

    if (!str_contains($source, "$jinxResult['exit'] !== $phpResult['exit']")) {
        fail('unit case script missing exit-code parity assertion: ' . $relativePath);
    }

    if (!str_contains($source, "$jinxResult['stdout'] !== $phpResult['stdout']")) {
        fail('unit case script missing stdout parity assertion: ' . $relativePath);
    }

    $total += $expectedCaseCount;
    $scriptCount++;
}

if ($scriptCount !== 13) {
    fail('expected 13 direct PHP-vs-JINX unit scripts, found ' . $scriptCount);
}

if ($total !== 1144) {
    fail('expected 1,144 direct PHP-vs-JINX unit cases, found ' . $total);
}

echo 'PASS: PHP vs JINX 1144 unit coverage audit validates 13 scripts, exact case counts, PHP/JINX parity assertions, and native-suite wiring' . PHP_EOL;
