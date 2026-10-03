<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$waveOnePath = $root . '/scripts/test-php-jinx-high-value-edge-fixtures.php';
$waveTwoPath = $root . '/scripts/test-php-jinx-high-value-edge-fixtures-two.php';
$suitePath = $root . '/scripts/test-jinx-native-edge-suite.php';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/** @return array{fixtures:int,areas:list<string>} */
function inspect_wave(string $path): array
{
    if (!is_file($path)) {
        fail('missing edge fixture script: ' . $path);
    }

    $source = (string) file_get_contents($path);
    preg_match_all("/add_case\(\$cases, '([^']+)', '([^']+)'/", $source, $matches, PREG_SET_ORDER);

    $areas = [];
    foreach ($matches as $match) {
        $areas[$match[1]] = true;
    }

    if (!str_contains($source, "count(\$cases) !== 20")) {
        fail('edge fixture script missing exact 20-case guard: ' . basename($path));
    }
    if (!str_contains($source, "count(\$areas) !== 10")) {
        fail('edge fixture script missing exact 10-area guard: ' . basename($path));
    }

    return [
        'fixtures' => count($matches),
        'areas' => array_keys($areas),
    ];
}

$waveOne = inspect_wave($waveOnePath);
$waveTwo = inspect_wave($waveTwoPath);

if ($waveOne['fixtures'] !== 20) {
    fail('wave one expected 20 declared fixtures, found ' . $waveOne['fixtures']);
}
if ($waveTwo['fixtures'] !== 20) {
    fail('wave two expected 20 declared fixtures, found ' . $waveTwo['fixtures']);
}
if (count($waveOne['areas']) !== 10) {
    fail('wave one expected 10 semantic areas, found ' . count($waveOne['areas']));
}
if (count($waveTwo['areas']) !== 10) {
    fail('wave two expected 10 semantic areas, found ' . count($waveTwo['areas']));
}

$combinedAreas = array_values(array_unique(array_merge($waveOne['areas'], $waveTwo['areas'])));
sort($combinedAreas);

if (count($combinedAreas) !== 20) {
    fail('expected 20 unique semantic areas across both waves, found ' . count($combinedAreas));
}

if (!is_file($suitePath)) {
    fail('focused native edge suite is missing');
}
$suite = (string) file_get_contents($suitePath);

foreach ([
    'scripts/test-php-jinx-high-value-edge-fixtures.php',
    'scripts/test-php-jinx-high-value-edge-fixtures-two.php',
] as $script) {
    if (!str_contains($suite, $script)) {
        fail('focused native edge suite does not wire ' . $script);
    }
}

if (!str_contains($suite, 'waves 1-2')) {
    fail('focused native edge suite missing combined waves marker');
}

echo 'PASS: PHP vs JINX high-value edge coverage audit validates 40 fixtures across 20 semantic areas and focused-suite wiring' . PHP_EOL;
