<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failTimezoneAbbr(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!function_exists('timezone_abbreviations_list')) {
    failTimezoneAbbr('PHP runtime missing timezone_abbreviations_list()');
}
if (!is_file($jinx) || !is_executable($jinx)) {
    failTimezoneAbbr('repository-root native ./jinx missing or not executable');
}

$phpTable = timezone_abbreviations_list();
if (!is_array($phpTable) || $phpTable === []) {
    failTimezoneAbbr('PHP timezone abbreviation table is empty');
}

$phpJson = json_encode($phpTable);
if (!is_string($phpJson)) {
    failTimezoneAbbr('PHP timezone abbreviation table JSON encoding failed');
}

$out = [];
$code = 0;
exec(
    escapeshellarg($jinx) .
        ' oracle-call-json timezone_abbreviations_list 2>&1',
    $out,
    $code
);
$actual = rtrim(implode(PHP_EOL, $out), "\r\n");
if ($code !== 0 || !str_starts_with($actual, 'string:')) {
    failTimezoneAbbr(
        "native timezone_abbreviations_list execution failed\nJINX: {$actual}"
    );
}
$jinxJson = substr($actual, strlen('string:'));

if ($jinxJson !== $phpJson) {
    $phpEntries = 0;
    foreach ($phpTable as $entries) {
        if (is_array($entries)) $phpEntries += count($entries);
    }
    failTimezoneAbbr(
        "timezone abbreviation JSON parity mismatch\n" .
        'PHP groups=' . count($phpTable) .
        ' entries=' . $phpEntries .
        ' bytes=' . strlen($phpJson) . "\n" .
        'JINX bytes=' . strlen($jinxJson)
    );
}

echo 'PASS: native timezone_abbreviations_list exactly matches PHP JSON shape' . PHP_EOL;
