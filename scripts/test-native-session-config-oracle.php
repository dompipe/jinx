<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function sessionConfigFail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function sessionConfigRun(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function sessionConfigTyped(mixed $value): string
{
    if (is_string($value)) return 'string:' . $value;
    if (is_int($value)) return 'int:' . $value;
    if (is_bool($value)) return 'bool:' . ($value ? 'true' : 'false');
    if ($value === null) return 'null';
    sessionConfigFail('unsupported expected PHP value type: ' . get_debug_type($value));
}

if (!is_file($jinx) || !is_executable($jinx)) {
    sessionConfigFail('repository-root native ./jinx missing or not executable');
}

$required = [
    'session_status',
    'session_create_id',
    'session_get_cookie_params',
    'session_set_cookie_params',
    'session_name',
    'session_id',
    'session_cache_limiter',
    'session_cache_expire',
    'session_module_name',
    'session_save_path',
];
foreach ($required as $name) {
    if (!function_exists($name)) {
        sessionConfigFail("PHP {$name} is required for native parity");
    }
}

$sidPrefix = 'jx-';
$phpCreatedId = session_create_id($sidPrefix);
if (!is_string($phpCreatedId)) {
    sessionConfigFail('PHP session_create_id did not return a string');
}
$jinxCreatedLine = sessionConfigRun(
    escapeshellarg($jinx)
        . ' oracle-call session_create_id '
        . escapeshellarg('s:' . $sidPrefix),
    $createIdCode
);
if ($createIdCode !== 0 || !str_starts_with($jinxCreatedLine, 'string:')) {
    sessionConfigFail(
        "JINX session_create_id did not return a string\n{$jinxCreatedLine}"
    );
}
$jinxCreatedId = substr($jinxCreatedLine, strlen('string:'));

$sidLength = (int)ini_get('session.sid_length');
$sidBits = (int)ini_get('session.sid_bits_per_character');
$charClass = match ($sidBits) {
    4 => '0-9a-f',
    5 => '0-9a-v',
    6 => '0-9a-zA-Z,\\-',
    default => sessionConfigFail("unsupported PHP session.sid_bits_per_character={$sidBits}"),
};
$pattern = '/^' . preg_quote($sidPrefix, '/') . '[' . $charClass . ']{' . $sidLength . '}$/';
foreach (['PHP' => $phpCreatedId, 'JINX' => $jinxCreatedId] as $engine => $createdId) {
    if (strlen($createdId) !== strlen($sidPrefix) + $sidLength ||
        preg_match($pattern, $createdId) !== 1) {
        sessionConfigFail(
            "{$engine} session_create_id format mismatch\n" .
            "id={$createdId}\nlength=" . strlen($createdId) .
            " expected=" . (strlen($sidPrefix) + $sidLength) .
            " bits={$sidBits}"
        );
    }
}

$phpBadPrefix = @session_create_id('bad_prefix');
$jinxBadPrefix = sessionConfigRun(
    escapeshellarg($jinx)
        . ' oracle-call session_create_id '
        . escapeshellarg('s:bad_prefix'),
    $badPrefixCode
);
if ($phpBadPrefix !== false ||
    $badPrefixCode !== 0 ||
    $jinxBadPrefix !== 'bool:false') {
    sessionConfigFail(
        "session_create_id invalid-prefix parity mismatch\n" .
        "PHP=" . var_export($phpBadPrefix, true) .
        "\nJINX={$jinxBadPrefix}"
    );
}

$phpCookieParams = session_get_cookie_params();

$phpPositionalSet = session_set_cookie_params(
    600,
    '/jinx',
    'example.test',
    true,
    true
);
$phpPositionalParams = session_get_cookie_params();

$phpArraySet = session_set_cookie_params([
    'lifetime' => 1200,
    'path' => '/array',
    'domain' => '.example.test',
    'secure' => false,
    'httponly' => false,
    'samesite' => 'Strict',
]);
$phpArrayParams = session_get_cookie_params();

session_set_cookie_params($phpCookieParams);

$jinxCookieSmoke = sessionConfigRun(
    escapeshellarg($jinx) . ' oracle-session-cookie-smoke',
    $cookieSmokeCode
);
$expectedCookieSmoke = implode(PHP_EOL, [
    'positional_set=' . sessionConfigTyped($phpPositionalSet),
    'positional=hex:' . bin2hex(serialize($phpPositionalParams)),
    'array_set=' . sessionConfigTyped($phpArraySet),
    'array=hex:' . bin2hex(serialize($phpArrayParams)),
]);
if ($cookieSmokeCode !== 0 || $jinxCookieSmoke !== $expectedCookieSmoke) {
    sessionConfigFail(
        "session_set_cookie_params parity mismatch\n" .
        "PHP/expected:\n{$expectedCookieSmoke}\nJINX:\n{$jinxCookieSmoke}"
    );
}

$jinxCookieParams = sessionConfigRun(
    escapeshellarg($jinx) . ' oracle-call-serialize-hex session_get_cookie_params',
    $cookieCode
);
$expectedCookieParams = 'hex:' . bin2hex(serialize($phpCookieParams));
if ($cookieCode !== 0 || $jinxCookieParams !== $expectedCookieParams) {
    sessionConfigFail(
        "session_get_cookie_params parity mismatch\n" .
        "PHP/expected: {$expectedCookieParams}\nJINX: {$jinxCookieParams}"
    );
}

$statusInitial = session_status();
$nameInitial = session_name();
$namePrevious = session_name('JINXSESSID');
$nameCurrent = session_name();

$idInitial = session_id();
$idPrevious = session_id('jinxsession123');
$idCurrent = session_id();

$limiterInitial = session_cache_limiter();
$limiterPrevious = session_cache_limiter('private');
$limiterCurrent = session_cache_limiter();

$expireInitial = session_cache_expire();
$expirePrevious = session_cache_expire(321);
$expireCurrent = session_cache_expire();

$moduleInitial = session_module_name();
$modulePrevious = session_module_name('files');
$moduleCurrent = session_module_name();

$pathInitial = session_save_path();
$pathPrevious = session_save_path('/tmp/jinx-session-parity');
$pathCurrent = session_save_path();

$statusFinal = session_status();

/* Restore the PHP process state before asserting/returning. */
session_save_path($pathInitial);
session_module_name($moduleInitial);
session_cache_expire($expireInitial);
session_cache_limiter($limiterInitial);
session_id($idInitial);
session_name($nameInitial);

$expected = implode(PHP_EOL, [
    'status_initial=' . sessionConfigTyped($statusInitial),
    'name_initial=' . sessionConfigTyped($nameInitial),
    'name_previous=' . sessionConfigTyped($namePrevious),
    'name_current=' . sessionConfigTyped($nameCurrent),
    'id_initial=' . sessionConfigTyped($idInitial),
    'id_previous=' . sessionConfigTyped($idPrevious),
    'id_current=' . sessionConfigTyped($idCurrent),
    'limiter_initial=' . sessionConfigTyped($limiterInitial),
    'limiter_previous=' . sessionConfigTyped($limiterPrevious),
    'limiter_current=' . sessionConfigTyped($limiterCurrent),
    'expire_initial=' . sessionConfigTyped($expireInitial),
    'expire_previous=' . sessionConfigTyped($expirePrevious),
    'expire_current=' . sessionConfigTyped($expireCurrent),
    'module_initial=' . sessionConfigTyped($moduleInitial),
    'module_previous=' . sessionConfigTyped($modulePrevious),
    'module_current=' . sessionConfigTyped($moduleCurrent),
    'path_initial=' . sessionConfigTyped($pathInitial),
    'path_previous=' . sessionConfigTyped($pathPrevious),
    'path_current=' . sessionConfigTyped($pathCurrent),
    'status_final=' . sessionConfigTyped($statusFinal),
]);

$actual = sessionConfigRun(
    escapeshellarg($jinx) . ' oracle-session-config-smoke',
    $code
);

if ($code !== 0 || $actual !== $expected) {
    sessionConfigFail(
        "native session configuration parity mismatch\n" .
        "PHP/expected:\n{$expected}\nJINX:\n{$actual}"
    );
}

echo "PASS: native session configuration, ID generation, and cookie getter/setter match PHP\n";
