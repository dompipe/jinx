<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failTimezone(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runTimezone(string $jinx, string $abbr, ?int $offset, ?int $dst): string
{
    $cmd = escapeshellarg($jinx)
        . ' oracle-call timezone_name_from_abbr '
        . escapeshellarg('s:' . $abbr);
    if ($offset !== null) $cmd .= ' ' . escapeshellarg('i:' . $offset);
    if ($dst !== null) $cmd .= ' ' . escapeshellarg('i:' . $dst);

    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        failTimezone("native timezone_name_from_abbr failed: " . implode("\n", $out));
    }
    return trim(implode("\n", $out));
}

function phpTimezoneValue(string|false $value): string
{
    return $value === false ? 'bool:false' : 'string:' . $value;
}

if (!function_exists('timezone_name_from_abbr') || !function_exists('timezone_abbreviations_list')) {
    echo "PASS: timezone abbreviation parity unavailable on this PHP build" . PHP_EOL;
    exit(0);
}

$cases = [
    ['UTC', null, null],
    ['__jinx_missing_abbr__', null, null],
];

$table = timezone_abbreviations_list();
$foundDefault = false;
$foundExact = false;
$foundEmpty = false;

foreach ($table as $abbr => $entries) {
    if (!$foundDefault && $abbr !== '' && timezone_name_from_abbr((string)$abbr) !== false) {
        $cases[] = [(string)$abbr, null, null];
        $foundDefault = true;
    }

    if (!is_array($entries)) continue;
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $offset = (int)($entry['offset'] ?? 0);
        $dst = !empty($entry['dst']) ? 1 : 0;

        if (!$foundExact && timezone_name_from_abbr((string)$abbr, $offset, $dst) !== false) {
            $cases[] = [(string)$abbr, $offset, $dst];
            $foundExact = true;
        }
        if (!$foundEmpty && timezone_name_from_abbr('', $offset, $dst) !== false) {
            $cases[] = ['', $offset, $dst];
            $foundEmpty = true;
        }

        if ($foundDefault && $foundExact && $foundEmpty) break 2;
    }
}

$seen = [];
foreach ($cases as [$abbr, $offset, $dst]) {
    $key = strtolower($abbr) . '|' . ($offset ?? 'null') . '|' . ($dst ?? 'null');
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    $expected = $offset === null
        ? timezone_name_from_abbr($abbr)
        : ($dst === null
            ? timezone_name_from_abbr($abbr, $offset)
            : timezone_name_from_abbr($abbr, $offset, $dst));

    $actual = runTimezone($jinx, $abbr, $offset, $dst);
    $wanted = phpTimezoneValue($expected);

    if ($actual !== $wanted) {
        failTimezone(
            "timezone_name_from_abbr parity mismatch for {$key}\n" .
            "PHP/expected: {$wanted}\nJINX: {$actual}"
        );
    }
}

echo "PASS: native timezone_name_from_abbr matches PHP abbreviation resolution" . PHP_EOL;
