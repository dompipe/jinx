<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function failPreg(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runPreg(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function oraclePreg(string $jinx, string $pattern, string $subject, ?int &$code = null): string
{
    return runPreg(
        escapeshellarg($jinx)
        . ' oracle-call preg_match '
        . escapeshellarg('s:' . $pattern)
        . ' ' . escapeshellarg('s:' . $subject),
        $code
    );
}

function oraclePregAll(string $jinx, string $pattern, string $subject, ?int &$code = null): string
{
    return runPreg(
        escapeshellarg($jinx)
        . ' oracle-call preg_match_all '
        . escapeshellarg('s:' . $pattern)
        . ' ' . escapeshellarg('s:' . $subject),
        $code
    );
}

if (!is_file($jinx) || !is_executable($jinx)) {
    failPreg('repository-root native ./jinx missing');
}
if (!function_exists('preg_match')) {
    failPreg('PHP preg_match unavailable');
}

$cases = [
    ['/jinx/', 'jinx compiler'],
    ['/jinx/', 'php compiler'],
    ['/jinx/i', 'JINX compiler'],
    ['/^compiler$/', 'compiler'],
    ['/^compiler$/', 'compiler x'],
    ['~a.+z~s', "a\n\nz"],
    ['/(?:foo|bar){2}/', 'xxfoobarxx'],
];

foreach ($cases as [$pattern, $subject]) {
    $php = @preg_match($pattern, $subject);
    $actual = oraclePreg($jinx, $pattern, $subject, $code);
    $expected = $php === false ? 'bool:false' : 'int:' . $php;
    if ($code !== 0 || trim($actual) !== $expected) {
        failPreg(
            "preg_match parity mismatch\n"
            . "pattern={$pattern}\nsubject={$subject}\n"
            . "PHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

$invalid = oraclePreg($jinx, '/[/', 'x', $invalidCode);
if ($invalidCode !== 0 || trim($invalid) !== 'bool:false') {
    failPreg("invalid-pattern contract mismatch\nJINX: {$invalid}");
}

$allCases = [
    ['/jinx/i', 'jinx JINX php jinx'],
    ['/a+/', 'aa x aaaa y'],
    ['~[0-9]+~', 'a12b345c'],
    ['/nomatch/', 'jinx compiler'],
];

foreach ($allCases as [$pattern, $subject]) {
    $php = @preg_match_all($pattern, $subject);
    $actual = oraclePregAll($jinx, $pattern, $subject, $code);
    $expected = $php === false ? 'bool:false' : 'int:' . $php;
    if ($code !== 0 || trim($actual) !== $expected) {
        failPreg(
            "preg_match_all parity mismatch\n"
            . "pattern={$pattern}\nsubject={$subject}\n"
            . "PHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

echo "PASS: native PCRE2 preg_match and preg_match_all two-argument forms match PHP" . PHP_EOL;
