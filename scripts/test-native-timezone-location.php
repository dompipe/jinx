<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failTimezoneLocation(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!function_exists('timezone_location_get')) {
    failTimezoneLocation('PHP runtime missing timezone_location_get()');
}
if (!is_file($jinx) || !is_executable($jinx)) {
    failTimezoneLocation('repository-root native ./jinx missing or not executable');
}

$zones = [
    'America/Detroit',
    'Europe/London',
    'Asia/Tokyo',
    'UTC',
];

foreach ($zones as $zone) {
    $timezone = new DateTimeZone($zone);
    $php = timezone_location_get($timezone);
    $phpJson = json_encode($php);
    if (!is_string($phpJson)) {
        failTimezoneLocation("PHP JSON encoding failed for {$zone}");
    }

    $out = [];
    $code = 0;
    exec(
        escapeshellarg($jinx) .
            ' oracle-call-json timezone_location_get ' .
            escapeshellarg('tz:' . $zone) .
            ' 2>&1',
        $out,
        $code
    );
    $actual = rtrim(implode(PHP_EOL, $out), "\r\n");

    if ($code !== 0 || !str_starts_with($actual, 'string:')) {
        failTimezoneLocation(
            "native timezone_location_get failed for {$zone}\nJINX: {$actual}"
        );
    }

    $jinxJson = substr($actual, strlen('string:'));
    if ($jinxJson !== $phpJson) {
        failTimezoneLocation(
            "timezone_location_get parity mismatch for {$zone}\n" .
            "PHP: {$phpJson}\nJINX: {$jinxJson}"
        );
    }
}

echo 'PASS: native timezone_location_get matches PHP location metadata' . PHP_EOL;
