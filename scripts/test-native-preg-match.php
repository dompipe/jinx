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

function oraclePregSplit(string $jinx, string $pattern, string $subject, ?int &$code = null): string
{
    return runPreg(
        escapeshellarg($jinx)
        . ' oracle-call preg_split '
        . escapeshellarg('s:' . $pattern)
        . ' ' . escapeshellarg('s:' . $subject),
        $code
    );
}

function oraclePregReplace(
    string $jinx,
    string $pattern,
    string $replacement,
    string $subject,
    ?int &$code = null
): string {
    return runPreg(
        escapeshellarg($jinx)
        . ' oracle-call preg_replace '
        . escapeshellarg('s:' . $pattern)
        . ' ' . escapeshellarg('s:' . $replacement)
        . ' ' . escapeshellarg('s:' . $subject),
        $code
    );
}

function oraclePregFilter(
    string $jinx,
    string $pattern,
    string $replacement,
    string $subject,
    ?int &$code = null
): string {
    return runPreg(
        escapeshellarg($jinx)
        . ' oracle-call preg_filter '
        . escapeshellarg('s:' . $pattern)
        . ' ' . escapeshellarg('s:' . $replacement)
        . ' ' . escapeshellarg('s:' . $subject),
        $code
    );
}

function oraclePregState(
    string $jinx,
    string $typedPattern,
    string $typedSubject,
    ?int &$code = null
): string {
    return runPreg(
        escapeshellarg($jinx)
        . ' oracle-preg-state '
        . escapeshellarg($typedPattern)
        . ' ' . escapeshellarg($typedSubject),
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

$splitCases = [
    ['/,+/', 'a,b,,c'],
    ['/\s+/', 'one two   three'],
    ['/:/', 'a:b:c:d'],
];

foreach ($splitCases as [$pattern, $subject]) {
    $php = @preg_split($pattern, $subject);
    if (!is_array($php)) {
        failPreg("PHP preg_split fixture unexpectedly failed: {$pattern}");
    }
    $actual = oraclePregSplit($jinx, $pattern, $subject, $code);
    $expected = 'zend-array:' . count($php);
    if ($code !== 0 || trim($actual) !== $expected) {
        failPreg(
            "preg_split count parity mismatch\n"
            . "pattern={$pattern}\nsubject={$subject}\n"
            . "PHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

$invalidSplit = oraclePregSplit($jinx, '/[/', 'x', $invalidSplitCode);
if ($invalidSplitCode !== 0 || trim($invalidSplit) !== 'bool:false') {
    failPreg("preg_split invalid-pattern contract mismatch\nJINX: {$invalidSplit}");
}

$replaceCases = [
    ['/cat/', 'dog', 'cat cat'],
    ['/jinx/i', 'native', 'JINX + jinx'],
    ['/([0-9]+)/', '[$1]', 'a12b34'],
];

foreach ($replaceCases as [$pattern, $replacement, $subject]) {
    $php = @preg_replace($pattern, $replacement, $subject);
    if (!is_string($php)) {
        failPreg("PHP preg_replace fixture unexpectedly failed: {$pattern}");
    }
    $actual = oraclePregReplace($jinx, $pattern, $replacement, $subject, $code);
    $expected = 'string:' . $php;
    if ($code !== 0 || trim($actual) !== $expected) {
        failPreg(
            "preg_replace parity mismatch\n"
            . "pattern={$pattern}\nreplacement={$replacement}\nsubject={$subject}\n"
            . "PHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

$filterCases = [
    ['/cat/', 'dog', 'cat cat'],
    ['/jinx/i', 'native', 'JINX + jinx'],
    ['/nomatch/', 'native', 'JINX + jinx'],
];

foreach ($filterCases as [$pattern, $replacement, $subject]) {
    $php = @preg_filter($pattern, $replacement, $subject);
    $actual = oraclePregFilter($jinx, $pattern, $replacement, $subject, $code);
    $expected = $php === null ? 'null' : 'string:' . $php;
    if ($code !== 0 || trim($actual) !== $expected) {
        failPreg(
            "preg_filter parity mismatch\n"
            . "pattern={$pattern}\nreplacement={$replacement}\nsubject={$subject}\n"
            . "PHP/expected: {$expected}\nJINX: {$actual}"
        );
    }
}

$invalidFilter = oraclePregFilter($jinx, '/[/', 'x', 'subject', $invalidFilterCode);
if ($invalidFilterCode !== 0 || trim($invalidFilter) !== 'null') {
    failPreg("preg_filter invalid-pattern contract mismatch\nJINX: {$invalidFilter}");
}

$stateCases = [
    ['/jinx/', 'jinx compiler', false],
    ['/[/', 'x', false],
    ['/./u', "\xff", true],
];

foreach ($stateCases as [$pattern, $subject, $binarySubject]) {
    $phpMatch = @preg_match($pattern, $subject);
    $phpError = preg_last_error();
    $phpMessage = preg_last_error_msg();

    $typedSubject = $binarySubject
        ? 'h:' . bin2hex($subject)
        : 's:' . $subject;

    $actual = oraclePregState(
        $jinx,
        's:' . $pattern,
        $typedSubject,
        $code
    );

    $expected = implode(PHP_EOL, [
        'match=' . ($phpMatch === false ? 'bool:false' : 'int:' . $phpMatch),
        'error=int:' . $phpError,
        'message=string:' . $phpMessage,
    ]);

    if ($code !== 0 || trim($actual) !== $expected) {
        failPreg(
            "preg last-error state parity mismatch\n"
            . "pattern={$pattern}\n"
            . "PHP/expected:\n{$expected}\n"
            . "JINX:\n{$actual}"
        );
    }
}

echo "PASS: native PCRE2 preg_match, preg_match_all, preg_split, preg_replace, preg_filter, preg_last_error, and preg_last_error_msg core forms match PHP" . PHP_EOL;
