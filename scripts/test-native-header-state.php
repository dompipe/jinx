<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function headerStateFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function headerStateRun(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function headerStateValue(mixed $value): string
{
    if ($value === null) return 'null';
    if (is_bool($value)) return 'bool:' . ($value ? 'true' : 'false');
    if (is_int($value)) return 'int:' . $value;
    if (is_string($value)) return 'string:' . $value;
    if (is_array($value)) return 'zend-array:' . count($value);
    headerStateFail('unsupported PHP header-state value: ' . get_debug_type($value));
}

if (!is_file($jinx) || !is_executable($jinx)) {
    headerStateFail('repository-root native ./jinx missing or not executable');
}

$headersSentInitial = headers_sent();
$headersInitial = headers_list();
$responseInitial = http_response_code();

$headerResult = header('X-Jinx: yes', true, 418);
$headersAfterHeader = headers_list();
$responseAfterHeader = http_response_code();

$responsePrevious = http_response_code(404);
$responseCurrent = http_response_code();

$setCookie = setcookie('jinx', 'value');
$setRawCookie = setrawcookie('jinxraw', 'value');

$headerRemove = header_remove();
$headersFinal = headers_list();
$headersSentFinal = headers_sent();
$responseFinal = http_response_code();

$expected = implode(PHP_EOL, [
    'headers_sent_initial=' . headerStateValue($headersSentInitial),
    'headers_initial=' . headerStateValue($headersInitial),
    'response_initial=' . headerStateValue($responseInitial),
    'header_result=' . headerStateValue($headerResult),
    'headers_after_header=' . headerStateValue($headersAfterHeader),
    'response_after_header=' . headerStateValue($responseAfterHeader),
    'response_previous=' . headerStateValue($responsePrevious),
    'response_current=' . headerStateValue($responseCurrent),
    'setcookie=' . headerStateValue($setCookie),
    'setrawcookie=' . headerStateValue($setRawCookie),
    'header_remove=' . headerStateValue($headerRemove),
    'headers_final=' . headerStateValue($headersFinal),
    'headers_sent_final=' . headerStateValue($headersSentFinal),
    'response_final=' . headerStateValue($responseFinal),
]);

$actual = headerStateRun(
    escapeshellarg($jinx) . ' oracle-header-state-smoke',
    $code
);

if ($code !== 0 || $actual !== $expected) {
    headerStateFail(
        "native CLI header state parity mismatch\n" .
        "PHP/expected:\n{$expected}\n" .
        "JINX:\n{$actual}"
    );
}

echo "PASS: native CLI header and response state matches PHP", PHP_EOL;
