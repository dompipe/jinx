<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';
$targetFile = $root . '/oracle-sm/zend/procedural_needed_200.osm';

function fail200(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function run200(string $command, ?int &$code = null): string
{
    $out = [];
    $status = 0;
    exec($command . ' 2>&1', $out, $status);
    $code = $status;
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function jinx200(string $jinx, string $name, array $args = [], bool $hex = false, ?int &$code = null): string
{
    $cmd = escapeshellarg($jinx)
        . ' ' . ($hex ? 'oracle-call-hex' : 'oracle-call')
        . ' ' . escapeshellarg($name);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg((string)$arg);
    }
    return run200($cmd, $code);
}

function expect200(string $jinx, string $name, array $args, string $expected, bool $hex = false): void
{
    $actual = jinx200($jinx, $name, $args, $hex, $code);
    if ($code !== 0 || $actual !== $expected) {
        fail200("{$name} parity mismatch\nPHP/expected: {$expected}\nJINX: {$actual}");
    }
}

function expectType200(string $jinx, string $name, array $args, string $prefix): void
{
    $actual = jinx200($jinx, $name, $args, false, $code);
    if ($code !== 0 || !str_starts_with($actual, $prefix)) {
        fail200("{$name} return-contract mismatch\nExpected prefix: {$prefix}\nJINX: {$actual}");
    }
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail200('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}
if (!is_file($targetFile)) {
    fail200('missing procedural-needed-200 target file');
}

$targetText = (string)file_get_contents($targetFile);
if (!preg_match('/targets\s*\{(.*?)\n\}/s', $targetText, $m)) {
    fail200('could not parse second-wave targets block');
}
$targets = [];
foreach (preg_split('/\R/', $m[1]) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $targets[] = $line;
}
if (count($targets) !== 200) {
    fail200('expected exactly 200 second-wave targets, got ' . count($targets));
}

$constantSmoke = run200(
    escapeshellarg($jinx) . ' oracle-constant-smoke',
    $code
);
if ($code !== 0 ||
    !str_contains(
        $constantSmoke,
        'PASS: native define/defined/constant/get_defined_constants share one runtime registry'
    )) {
    fail200("runtime constant registry smoke failed\n{$constantSmoke}");
}

$frameSmoke = run200(
    escapeshellarg($jinx) . ' oracle-frame-smoke',
    $code
);
if ($code !== 0 ||
    !str_contains(
        $frameSmoke,
        'PASS: native Zend frame context drives func_num_args/func_get_arg/func_get_args/get_called_class/get_defined_vars/compact/extract/debug_backtrace/debug_print_backtrace and clears on frame leave'
    )) {
    fail200("native caller-frame smoke failed\n{$frameSmoke}");
}

/* Deterministic metadata/introspection parity. */
$posixChecks = [];
if (function_exists('posix_getuid')) {
    $phpPw = posix_getpwuid(posix_getuid());
    $phpGr = posix_getgrgid(posix_getgid());
    if (!is_array($phpPw) || !isset($phpPw['name']) ||
        !is_array($phpGr) || !isset($phpGr['name'])) {
        fail200('PHP POSIX account fixtures were unavailable');
    }
    $phpLogin = posix_getlogin();
    $phpGroups = posix_getgroups();
    $posixChecks = [
        ['posix_getuid', [], 'int:' . posix_getuid()],
        ['posix_getgid', [], 'int:' . posix_getgid()],
        ['posix_geteuid', [], 'int:' . posix_geteuid()],
        ['posix_getegid', [], 'int:' . posix_getegid()],
        ['posix_getpgrp', [], 'int:' . posix_getpgrp()],
        ['posix_getpgid', ['i:0'], (($v = posix_getpgid(0)) === false ? 'bool:false' : 'int:' . $v)],
        ['posix_getsid', ['i:0'], (($v = posix_getsid(0)) === false ? 'bool:false' : 'int:' . $v)],
        ['posix_getcwd', [], (($v = posix_getcwd()) === false ? 'bool:false' : 'string:' . $v)],
        ['posix_getlogin', [], ($phpLogin === false ? 'bool:false' : 'string:' . $phpLogin)],
        ['posix_getgroups', [], ($phpGroups === false ? 'bool:false' : 'zend-array:' . count($phpGroups))],
        ['posix_getpwnam', ['s:' . $phpPw['name']], 'zend-array:' . count(posix_getpwnam($phpPw['name']) ?: [])],
        ['posix_getpwuid', ['i:' . posix_getuid()], 'zend-array:' . count($phpPw)],
        ['posix_getgrnam', ['s:' . $phpGr['name']], 'zend-array:' . count(posix_getgrnam($phpGr['name']) ?: [])],
        ['posix_getgrgid', ['i:' . posix_getgid()], 'zend-array:' . count($phpGr)],
        ['posix_strerror', ['i:2'], 'string:' . posix_strerror(2)],
        ['posix_access', ['s:' . $root . '/README.md'], 'bool:' . (posix_access($root . '/README.md') ? 'true' : 'false')],
        ['posix_access', ['s:' . $root . '/__jinx_missing_posix__'], 'bool:' . (posix_access($root . '/__jinx_missing_posix__') ? 'true' : 'false')],
        ['posix_ctermid', [], (($v = posix_ctermid()) === false ? 'bool:false' : 'string:' . $v)],
        ['posix_isatty', ['i:1'], 'bool:' . (posix_isatty(1) ? 'true' : 'false')],
        ['posix_ttyname', ['i:1'], (($v = posix_ttyname(1)) === false ? 'bool:false' : 'string:' . $v)],
        ['posix_times', [], (($v = posix_times()) === false ? 'bool:false' : 'zend-array:' . count($v))],
        ['posix_uname', [], (($v = posix_uname()) === false ? 'bool:false' : 'zend-array:' . count($v))],
    ];
    if (function_exists('posix_sysconf') && defined('POSIX_SC_PAGESIZE')) {
        $posixChecks[] = [
            'posix_sysconf',
            ['i:' . constant('POSIX_SC_PAGESIZE')],
            'int:' . posix_sysconf(constant('POSIX_SC_PAGESIZE')),
        ];
    }
    if (function_exists('posix_pathconf') && defined('POSIX_PC_PATH_MAX')) {
        $posixPath = $root;
        $phpPathConf = posix_pathconf($posixPath, constant('POSIX_PC_PATH_MAX'));
        $posixChecks[] = [
            'posix_pathconf',
            ['s:' . $posixPath, 'i:' . constant('POSIX_PC_PATH_MAX')],
            $phpPathConf === false ? 'bool:false' : 'int:' . $phpPathConf,
        ];
    }
    if (function_exists('posix_fpathconf') && defined('POSIX_PC_PIPE_BUF')) {
        $phpFdConf = posix_fpathconf(1, constant('POSIX_PC_PIPE_BUF'));
        $posixChecks[] = [
            'posix_fpathconf',
            ['i:1', 'i:' . constant('POSIX_PC_PIPE_BUF')],
            $phpFdConf === false ? 'bool:false' : 'int:' . $phpFdConf,
        ];
    }
}

$checks = [
    ['function_exists', ['s:strlen'], 'bool:' . (function_exists('strlen') ? 'true' : 'false')],
    ['enum_exists', ['s:__JinxMissingEnum'], 'bool:false'],
    ['interface_exists', ['s:Countable'], 'bool:' . (interface_exists('Countable') ? 'true' : 'false')],
    ['extension_loaded', ['s:Core'], 'bool:' . (extension_loaded('Core') ? 'true' : 'false')],
    ['get_declared_classes', [], 'zend-array:' . count(get_declared_classes())],
    ['get_declared_interfaces', [], 'zend-array:' . count(get_declared_interfaces())],
    ['get_declared_traits', [], 'zend-array:' . count(get_declared_traits())],
    ['get_loaded_extensions', [], 'zend-array:' . count(get_loaded_extensions())],
    ['get_extension_funcs', ['s:Core'], 'zend-array:' . count(get_extension_funcs('Core') ?: [])],
    ['get_defined_functions', [], 'zend-array:2'],
    ['get_class', ['obj:ArrayIterator'], 'string:ArrayIterator'],
    ['get_parent_class', ['obj:ErrorException'], 'string:Exception'],
    ['get_class_methods', ['s:ArrayIterator'], 'zend-array:' . count(get_class_methods('ArrayIterator') ?: [])],
    ['get_class_vars', ['s:stdClass'], 'zend-array:' . count(get_class_vars('stdClass') ?: [])],
    ['get_include_path', [], 'string:' . get_include_path()],
    ['set_include_path', ['s:/tmp/jinx-include'], 'string:' . get_include_path()],
    ['ini_get', ['s:precision'], (($v = ini_get('precision')) === false ? 'bool:false' : 'string:' . $v)],
    ['ini_set', ['s:precision', 's:13'], (($v = ini_get('precision')) === false ? 'bool:false' : 'string:' . $v)],
    ['ini_alter', ['s:precision', 's:13'], (($v = ini_get('precision')) === false ? 'bool:false' : 'string:' . $v)],
    ['ini_restore', ['s:precision'], 'null'],
    ['ini_get_all', [], 'zend-array:' . count(ini_get_all())],
    ['ini_get_all', ['s:date', 'b:false'], 'zend-array:' . count(ini_get_all('date', false) ?: [])],
    ['filter_list', [], 'zend-array:' . count(filter_list())],
    ['filter_id', ['s:int'], 'int:' . filter_id('int')],
    ['filter_var', ['s:42', 'i:' . filter_id('int')], 'int:42'],
    ['filter_var_array', ['za:strings', 'i:' . filter_id('unsafe_raw')], 'zend-array:3'],
    ['error_reporting', [], 'int:' . error_reporting()],
    ['connection_aborted', [], 'int:' . connection_aborted()],
    ['connection_status', [], 'int:' . connection_status()],
    ['getcwd', [], 'string:' . getcwd()],
    ['getenv', ['s:PATH'], (($v = getenv('PATH')) === false ? 'bool:false' : 'string:' . $v)],
    ['putenv', ['s:JINX_NATIVE_DIRECT_SMOKE=present'], 'bool:true'],
    ['ignore_user_abort', [], 'int:' . ignore_user_abort()],
    ['ignore_user_abort', ['b:true'], 'int:' . ignore_user_abort()],
    ['gethostname', [], (($v = gethostname()) === false ? 'bool:false' : 'string:' . $v)],
    ['getprotobyname', ['s:tcp'], (($v = getprotobyname('tcp')) === false ? 'bool:false' : 'int:' . $v)],
    ['getprotobynumber', ['i:6'], (($v = getprotobynumber(6)) === false ? 'bool:false' : 'string:' . $v)],
    ['getservbyname', ['s:http', 's:tcp'], (($v = getservbyname('http', 'tcp')) === false ? 'bool:false' : 'int:' . $v)],
    ['getservbyport', ['i:80', 's:tcp'], (($v = getservbyport(80, 'tcp')) === false ? 'bool:false' : 'string:' . $v)],
    ['fnmatch', ['s:*.md', 's:README.md'], 'bool:' . (fnmatch('*.md', 'README.md') ? 'true' : 'false')],
    ['escapeshellarg', ['s:a b'], 'string:' . escapeshellarg('a b')],
    ['flush', [], 'null'],
    ['closelog', [], 'null'],
    ['chroot', ['s:/__jinx_oracle_missing__'], 'bool:false'],
    ['getdate', ['i:1704067200'], 'zend-array:' . count(getdate(1704067200))],
    ['gmmktime', ['i:0', 'i:0', 'i:0', 'i:1', 'i:1', 'i:2024'], 'int:' . gmmktime(0, 0, 0, 1, 1, 2024)],
    ['hash_equals', ['s:abc', 's:abc'], 'bool:true'],
    ['hash_equals', ['s:abc', 's:abd'], 'bool:false'],
    ['hash_algos', [], 'zend-array:' . count(hash_algos())],
    ['hash_hmac_algos', [], 'zend-array:' . count(hash_hmac_algos())],
    ['closedir', ['dir:tmp'], 'null'],
    ['assert', ['b:true'], 'bool:true'],
    ['get_resource_type', ['fp:tmp'], 'string:stream'],
    ['get_resource_id', ['fp:tmp'], 'int:1'],
    ['get_resources', [], 'zend-array:0'],
    ['sys_get_temp_dir', [], 'string:' . sys_get_temp_dir()],
    ['php_sapi_name', [], (($v = php_sapi_name()) === false ? 'bool:false' : 'string:' . $v)],
    ['php_ini_loaded_file', [], (($v = php_ini_loaded_file()) === false ? 'bool:false' : 'string:' . $v)],
    ['php_ini_scanned_files', [], (($v = php_ini_scanned_files()) === false ? 'bool:false' : 'string:' . rtrim($v, "\r\n"))],
    ['php_uname', [], 'string:' . php_uname()],
    ['php_uname', ['s:s'], 'string:' . php_uname('s')],
    ['php_uname', ['s:n'], 'string:' . php_uname('n')],
    ['php_uname', ['s:r'], 'string:' . php_uname('r')],
    ['php_uname', ['s:v'], 'string:' . php_uname('v')],
    ['php_uname', ['s:m'], 'string:' . php_uname('m')],
    ['phpversion', [], 'string:' . phpversion()],
    ['zend_version', [], 'string:' . zend_version()],
    ['sleep', ['i:0'], 'int:' . sleep(0)],
    ['usleep', ['i:0'], 'null'],
    ['time_nanosleep', ['i:0', 'i:0'], 'bool:true'],
];

array_push($checks, ...$posixChecks);

if (function_exists('gmstrftime')) {
    $checks[] = ['gmstrftime', ['s:%Y-%m-%d', 'i:1704067200'], 'string:' . gmstrftime('%Y-%m-%d', 1704067200)];
}

foreach ($checks as [$name, $args, $expected]) {
    expect200($jinx, $name, $args, $expected);
}

$sleepUntilTarget = microtime(true) + 0.25;
$sleepUntilActual = jinx200(
    $jinx,
    'time_sleep_until',
    ['f:' . sprintf('%.6f', $sleepUntilTarget)],
    false,
    $code
);
if ($code !== 0 || $sleepUntilActual !== 'bool:true') {
    fail200("time_sleep_until positive contract mismatch\nJINX: {$sleepUntilActual}");
}

if (function_exists('sys_getloadavg')) {
    $phpLoad = sys_getloadavg();
    expect200(
        $jinx,
        'sys_getloadavg',
        [],
        $phpLoad === false ? 'bool:false' : 'zend-array:' . count($phpLoad)
    );
}

if (function_exists('posix_getpid')) {
    foreach (['posix_getpid', 'posix_getppid'] as $name) {
        $actual = jinx200($jinx, $name, [], false, $code);
        if ($code !== 0 ||
            !preg_match('/^int:([0-9]+)$/', $actual, $match) ||
            (int)$match[1] <= 0) {
            fail200("{$name} positive-process-id contract mismatch\nJINX: {$actual}");
        }
    }
}

expect200($jinx, 'hrtime', [], 'zend-array:2');
$hrNumber = jinx200($jinx, 'hrtime', ['b:true'], false, $code);
if ($code !== 0 ||
    !preg_match('/^int:([0-9]+)$/', $hrNumber, $match) ||
    (int)$match[1] <= 0) {
    fail200("hrtime numeric contract mismatch\nJINX: {$hrNumber}");
}

$microFloat = jinx200($jinx, 'microtime', ['b:true'], false, $code);
if ($code !== 0 ||
    !preg_match('/^float:([0-9]+(?:\.[0-9]+)?)$/', $microFloat, $match) ||
    abs((float)$match[1] - microtime(true)) > 5.0) {
    fail200("microtime float contract mismatch\nJINX: {$microFloat}");
}
$microString = jinx200($jinx, 'microtime', [], false, $code);
if ($code !== 0 ||
    !preg_match('/^string:0\.[0-9]{8} ([0-9]+)$/', $microString, $match) ||
    abs((int)$match[1] - time()) > 5) {
    fail200("microtime string contract mismatch\nJINX: {$microString}");
}

$randomBytes = jinx200($jinx, 'random_bytes', ['i:16'], true, $code);
if ($code !== 0 || !preg_match('/^hex:[0-9a-f]{32}$/', $randomBytes)) {
    fail200("random_bytes length/binary contract mismatch\nJINX: {$randomBytes}");
}
expect200($jinx, 'random_int', ['i:7', 'i:7'], 'int:' . random_int(7, 7));
for ($i = 0; $i < 16; $i++) {
    $randomInt = jinx200($jinx, 'random_int', ['i:-5', 'i:9'], false, $code);
    if ($code !== 0 ||
        !preg_match('/^int:(-?[0-9]+)$/', $randomInt, $match) ||
        (int)$match[1] < -5 || (int)$match[1] > 9) {
        fail200("random_int inclusive-range contract mismatch\nJINX: {$randomInt}");
    }
}

/* PHP debug_backtrace contract fixture: first frame includes function
 * and args unless DEBUG_BACKTRACE_IGNORE_ARGS is requested. */
$phpTraceProbe = (static function (): array {
    return debug_backtrace(0, 1);
})();
if (!isset($phpTraceProbe[0]['function'], $phpTraceProbe[0]['args']) ||
    !is_array($phpTraceProbe[0]['args'])) {
    fail200('PHP debug_backtrace contract fixture did not expose function/args');
}

/* PHP debug_print_backtrace contract fixture. */
ob_start();
(static function (): void {
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
})();
$phpPrintedTrace = (string)ob_get_clean();
if (!str_contains($phpPrintedTrace, '#0') ||
    !str_contains($phpPrintedTrace, '()')) {
    fail200('PHP debug_print_backtrace contract fixture had unexpected format');
}

/* Native frame smoke verifies function/class/type/file/line/args from the
 * real JinxZendCallFrame chain. */
$frameSmoke = run200(
    escapeshellarg($jinx) . ' oracle-frame-smoke',
    $frameSmokeCode
);
if ($frameSmokeCode !== 0 ||
    !str_contains($frameSmoke, 'debug_backtrace') ||
    !str_contains(
        $frameSmoke,
        '#0 /tmp/jinx-frame-smoke.php(41): JinxFrameScope::jinx_frame_smoke()'
    )) {
    fail200("native frame/debug_backtrace smoke failed\n{$frameSmoke}");
}

if (function_exists('posix_get_last_error') && function_exists('posix_errno')) {
    @posix_access('/__jinx_native_posix_missing__');
    $phpPosixError = posix_get_last_error();
    $posixErrorSmoke = run200(
        escapeshellarg($jinx) . ' oracle-posix-error-smoke',
        $posixErrorCode
    );
    $posixErrorExpected = implode(PHP_EOL, [
        'access=bool:false',
        'last=int:' . $phpPosixError,
        'errno=int:' . $phpPosixError,
    ]);
    if ($posixErrorCode !== 0 || $posixErrorSmoke !== $posixErrorExpected) {
        fail200(
            "native POSIX last-error smoke mismatch\nExpected:\n"
            . $posixErrorExpected
            . "\nJINX:\n"
            . $posixErrorSmoke
        );
    }
}

$phpIniBefore = ini_get('precision');
$phpIniOld = ini_set('precision', '13');
$phpIniDuring = ini_get('precision');
ini_restore('precision');
$phpIniRestored = ini_get('precision');
if ($phpIniBefore === false || $phpIniOld === false ||
    $phpIniDuring === false || $phpIniRestored === false) {
    fail200('PHP INI smoke fixture failed');
}
$iniSmoke = run200(
    escapeshellarg($jinx) . ' oracle-ini-smoke',
    $iniSmokeCode
);
$iniSmokeExpected = implode(PHP_EOL, [
    'before=string:' . $phpIniBefore,
    'set_old=string:' . $phpIniOld,
    'during=string:' . $phpIniDuring,
    'restored=string:' . $phpIniRestored,
]);
if ($iniSmokeCode !== 0 || $iniSmoke !== $iniSmokeExpected) {
    fail200(
        "native INI state smoke mismatch\nExpected:\n"
        . $iniSmokeExpected
        . "\nJINX:\n"
        . $iniSmoke
    );
}

$phpPutSet = putenv('JINX_NATIVE_ENV_SMOKE=present');
$phpEnvDuring = getenv('JINX_NATIVE_ENV_SMOKE');
$phpPutUnset = putenv('JINX_NATIVE_ENV_SMOKE');
$phpEnvAfter = getenv('JINX_NATIVE_ENV_SMOKE');
$phpAbortBefore = ignore_user_abort();
$phpAbortSetOld = ignore_user_abort(true);
$phpAbortDuring = ignore_user_abort();
ignore_user_abort((bool)$phpAbortBefore);

$runtimeStateSmoke = run200(
    escapeshellarg($jinx) . ' oracle-runtime-state-smoke',
    $runtimeStateCode
);
$runtimeStateExpected = implode(PHP_EOL, [
    'put_set=bool:' . ($phpPutSet ? 'true' : 'false'),
    'env_during=' . ($phpEnvDuring === false ? 'bool:false' : 'string:' . $phpEnvDuring),
    'put_unset=bool:' . ($phpPutUnset ? 'true' : 'false'),
    'env_after=' . ($phpEnvAfter === false ? 'bool:false' : 'string:' . $phpEnvAfter),
    'abort_before=int:' . $phpAbortBefore,
    'abort_set_old=int:' . $phpAbortSetOld,
    'abort_during=int:' . $phpAbortDuring,
]);
if ($runtimeStateCode !== 0 || $runtimeStateSmoke !== $runtimeStateExpected) {
    fail200(
        "native runtime-state smoke mismatch\nExpected:\n"
        . $runtimeStateExpected
        . "\nJINX:\n"
        . $runtimeStateSmoke
    );
}

$errorSmoke = run200(
    escapeshellarg($jinx) . ' oracle-error-smoke',
    $errorSmokeCode
);
if ($errorSmokeCode !== 0 ||
    !str_contains(
        $errorSmoke,
        'PASS: native Zend executor last-error state drives error_get_last/error_clear_last'
    )) {
    fail200("native Zend last-error smoke failed\n{$errorSmoke}");
}
$scriptSmoke = run200(
    escapeshellarg($jinx)
        . ' oracle-script-context-smoke '
        . escapeshellarg(__FILE__)
        . ' '
        . escapeshellarg($root . '/README.md'),
    $scriptSmokeCode
);
if ($scriptSmokeCode !== 0 ||
    !str_contains(
        $scriptSmoke,
        'PASS: native script context drives get_included_files/get_required_files/getlastmod/getmyinode'
    )) {
    fail200("native script-context smoke failed\n{$scriptSmoke}");
}


expect200($jinx, 'error_get_last', [], 'null');
expect200($jinx, 'error_clear_last', [], 'null');

expectType200($jinx, 'gettimeofday', ['b:true'], 'float:');
expect200($jinx, 'getrusage', [], 'zend-array:' . count(getrusage()));
expectType200($jinx, 'disk_free_space', ['s:.'], 'float:');
expectType200($jinx, 'disk_total_space', ['s:.'], 'float:');

/* Solar date parity uses explicit location/zenith/offset to avoid host INI differences. */
$solarTimestamp = 1704067200;
$solarLat = 42.3314;
$solarLon = -83.0458;
$solarZenith = 90.83333333333333;
$solarOffset = -5.0;

$phpSunrise = date_sunrise(
    $solarTimestamp,
    SUNFUNCS_RET_TIMESTAMP,
    $solarLat,
    $solarLon,
    $solarZenith,
    $solarOffset
);
$phpSunset = date_sunset(
    $solarTimestamp,
    SUNFUNCS_RET_TIMESTAMP,
    $solarLat,
    $solarLon,
    $solarZenith,
    $solarOffset
);
if ($phpSunrise === false || $phpSunset === false) {
    fail200('PHP solar fixture unexpectedly returned false');
}
expect200(
    $jinx,
    'date_sunrise',
    ['i:' . $solarTimestamp, 'i:0', 'f:' . $solarLat, 'f:' . $solarLon, 'f:' . $solarZenith, 'f:' . $solarOffset],
    'int:' . $phpSunrise
);
expect200(
    $jinx,
    'date_sunset',
    ['i:' . $solarTimestamp, 'i:0', 'f:' . $solarLat, 'f:' . $solarLon, 'f:' . $solarZenith, 'f:' . $solarOffset],
    'int:' . $phpSunset
);
$phpSunInfo = date_sun_info($solarTimestamp, $solarLat, $solarLon);
expect200(
    $jinx,
    'date_sun_info',
    ['i:' . $solarTimestamp, 'f:' . $solarLat, 'f:' . $solarLon],
    'zend-array:' . count($phpSunInfo)
);

/* dns_get_record() no-byref path: reserved .invalid produces an empty array,
 * not a fabricated DNS record. */
$dnsRecordProbe = jinx200(
    $jinx,
    'dns_get_record',
    ['s:__jinx_oracle_no_dns__.invalid'],
    false,
    $dnsRecordCode
);
if ($dnsRecordCode === 0 && !str_starts_with($dnsRecordProbe, 'null/fault:')) {
    $phpDnsRecord = @dns_get_record('__jinx_oracle_no_dns__.invalid');
    if (!is_array($phpDnsRecord) || $dnsRecordProbe !== 'zend-array:' . count($phpDnsRecord)) {
        fail200("dns_get_record parity mismatch\nPHP count: " . (is_array($phpDnsRecord) ? count($phpDnsRecord) : -1) . "\nJINX: {$dnsRecordProbe}");
    }
}

/* Context-aware by-reference DNS path: .invalid must not resolve, but PHP/Jinx
 * both initialize host/weight outputs to arrays before returning false. */
foreach (['dns_get_mx', 'getmxrr'] as $mxName) {
    $cmd = escapeshellarg($jinx)
        . ' oracle-call-refs ' . escapeshellarg($mxName)
        . ' ' . escapeshellarg('s:__jinx_oracle_no_mx__.invalid')
        . ' null null';
    $mxOut = run200($cmd, $mxCode);
    if ($mxCode === 0 && !str_contains($mxOut, 'null/fault:')) {
        $expected = implode(PHP_EOL, [
            'return=bool:false',
            'arg0=string:__jinx_oracle_no_mx__.invalid',
            'arg1=zend-array:0',
            'arg2=zend-array:0',
        ]);
        if ($mxOut !== $expected) {
            fail200("{$mxName} ref-writeback mismatch\nExpected:\n{$expected}\nJINX:\n{$mxOut}");
        }
    }
}

/* get_browser unconfigured parity: configured browscap still fails closed. */
$browscap = get_cfg_var('browscap');
if ($browscap === false || $browscap === '') {
    expect200($jinx, 'get_browser', ['s:JinxAudit'], 'bool:false');
}

/* Stream behavior uses the deterministic fp:tmp fixture ("a,b\nsecond line\n"). */
$streamFixtureBytes = "a,b\nsecond line\n";
$phpStream = tmpfile();
if ($phpStream === false) fail200('PHP tmpfile stream fixture failed');
fwrite($phpStream, $streamFixtureBytes);
rewind($phpStream);
$phpStreamLine = stream_get_line($phpStream, 1024, "\n");
rewind($phpStream);
$phpStreamLocal = stream_is_local($phpStream);
$phpStreamTty = stream_isatty($phpStream);
$phpStreamLocks = stream_supports_lock($phpStream);
$phpStreamBlocking = stream_set_blocking($phpStream, true);
fclose($phpStream);

$phpReadBufferStream = tmpfile();
$phpWriteBufferStream = tmpfile();
if ($phpReadBufferStream === false || $phpWriteBufferStream === false) {
    if (is_resource($phpReadBufferStream)) fclose($phpReadBufferStream);
    if (is_resource($phpWriteBufferStream)) fclose($phpWriteBufferStream);
    fail200('PHP buffer-control stream fixture failed');
}
$phpStreamReadBuffer = stream_set_read_buffer($phpReadBufferStream, 0);
$phpStreamWriteBuffer = stream_set_write_buffer($phpWriteBufferStream, 0);
fclose($phpReadBufferStream);
fclose($phpWriteBufferStream);

expect200($jinx, 'stream_get_wrappers', [], 'zend-array:' . count(stream_get_wrappers()));
expect200($jinx, 'stream_get_transports', [], 'zend-array:' . count(stream_get_transports()));
expect200($jinx, 'stream_get_filters', [], 'zend-array:' . count(stream_get_filters()));
expect200(
    $jinx,
    'stream_get_contents',
    ['fp:tmp'],
    'hex:' . bin2hex($streamFixtureBytes),
    true
);
expect200(
    $jinx,
    'stream_get_contents',
    ['fp:tmp', 'i:3'],
    'hex:' . bin2hex(substr($streamFixtureBytes, 0, 3)),
    true
);
expect200(
    $jinx,
    'stream_get_contents',
    ['fp:tmp', 'null', 'i:4'],
    'hex:' . bin2hex(substr($streamFixtureBytes, 4)),
    true
);
expect200(
    $jinx,
    'stream_get_line',
    ['fp:tmp', 'i:1024', 's:' . "\n"],
    $phpStreamLine === false ? 'bool:false' : 'hex:' . bin2hex($phpStreamLine),
    true
);
expect200($jinx, 'stream_copy_to_stream', ['fp:tmp', 'fp:tmp', 'i:4'], 'int:4');
expect200($jinx, 'stream_is_local', ['fp:tmp'], 'bool:' . ($phpStreamLocal ? 'true' : 'false'));
expect200($jinx, 'stream_isatty', ['fp:tmp'], 'bool:' . ($phpStreamTty ? 'true' : 'false'));
expect200($jinx, 'stream_supports_lock', ['fp:tmp'], 'bool:' . ($phpStreamLocks ? 'true' : 'false'));
expect200($jinx, 'stream_set_blocking', ['fp:tmp', 'b:true'], 'bool:' . ($phpStreamBlocking ? 'true' : 'false'));
expect200($jinx, 'stream_set_read_buffer', ['fp:tmp', 'i:0'], 'int:' . $phpStreamReadBuffer);
expect200($jinx, 'stream_set_write_buffer', ['fp:tmp', 'i:0'], 'int:' . $phpStreamWriteBuffer);

$resolvedReadme = stream_resolve_include_path($root . '/README.md');
expect200(
    $jinx,
    'stream_resolve_include_path',
    ['s:' . $root . '/README.md'],
    $resolvedReadme === false ? 'bool:false' : 'string:' . $resolvedReadme
);

expect200($jinx, 'ftruncate', ['fp:tmp', 'i:2'], 'bool:true');
expect200($jinx, 'fputs', ['fp:tmp', 's:x'], 'int:1');
expect200($jinx, 'fprintf', ['fp:tmp', 's:%s', 's:x'], 'int:1');
expect200($jinx, 'fputcsv', ['fp:tmp', 'za:strings'], 'int:6');
expect200($jinx, 'fscanf', ['fp:tmp', 's:%c,%c'], 'zend-array:2');

/* Current PHP/timelib solar algorithm parity with explicit coordinates. */
$solarTs = 1704067200;
$solarLat = 42.3314;
$solarLon = -83.0458;
$solarZenith = 90.833333;
$solarOffset = -5.0;
$phpSunrise = date_sunrise($solarTs, SUNFUNCS_RET_TIMESTAMP, $solarLat, $solarLon, $solarZenith, $solarOffset);
$phpSunset = date_sunset($solarTs, SUNFUNCS_RET_TIMESTAMP, $solarLat, $solarLon, $solarZenith, $solarOffset);
if ($phpSunrise !== false) {
    expect200(
        $jinx,
        'date_sunrise',
        ['i:' . $solarTs, 'i:' . SUNFUNCS_RET_TIMESTAMP, 'f:' . $solarLat, 'f:' . $solarLon, 'f:' . $solarZenith, 'f:' . $solarOffset],
        'int:' . $phpSunrise
    );
}
if ($phpSunset !== false) {
    expect200(
        $jinx,
        'date_sunset',
        ['i:' . $solarTs, 'i:' . SUNFUNCS_RET_TIMESTAMP, 'f:' . $solarLat, 'f:' . $solarLon, 'f:' . $solarZenith, 'f:' . $solarOffset],
        'int:' . $phpSunset
    );
}
expect200(
    $jinx,
    'date_sun_info',
    ['i:' . $solarTs, 'f:' . $solarLat, 'f:' . $solarLon],
    'zend-array:' . count(date_sun_info($solarTs, $solarLat, $solarLon))
);

/* Native get_meta_tags parser parity on a deterministic local HTML file. */
$metaFile = sys_get_temp_dir() . '/jinx-meta-' . getmypid() . '.html';
file_put_contents(
    $metaFile,
    '<html><head>'
    . '<meta name="DESCRIPTION" content="alpha">'
    . '<meta content="beta" name="geo.position">'
    . '<meta name="duplicate" content="first">'
    . '<meta name="duplicate" content="last">'
    . '</head><body>x</body></html>'
);
$phpMeta = get_meta_tags($metaFile);
if ($phpMeta === false) {
    @unlink($metaFile);
    fail200('PHP get_meta_tags rejected deterministic fixture');
}
expect200(
    $jinx,
    'get_meta_tags',
    ['s:' . $metaFile],
    'zend-array:' . count($phpMeta)
);
@unlink($metaFile);

/* Native gzip carrier and zlib context. */
expect200($jinx, 'gzeof', ['gz:tmp'], 'bool:false');
expect200($jinx, 'gzgetc', ['gz:tmp'], 'string:a');
expect200($jinx, 'gzgets', ['gz:tmp', 'i:64'], 'hex:' . bin2hex("a,b\n"), true);
expect200($jinx, 'gzread', ['gz:tmp', 'i:4'], 'hex:' . bin2hex("a,b\n"), true);
expect200($jinx, 'gzrewind', ['gz:tmp'], 'bool:true');
expect200($jinx, 'gzseek', ['gz:tmp', 'i:0', 'i:0'], 'int:0');
expect200($jinx, 'gztell', ['gz:tmp'], 'int:0');
expect200($jinx, 'gzclose', ['gz:tmp'], 'bool:true');
expect200($jinx, 'gzopen', ['s:/__jinx_oracle_missing__.gz', 's:rb'], 'bool:false');
expect200($jinx, 'gzfile', ['s:/__jinx_oracle_missing__.gz'], 'bool:false');
expectType200($jinx, 'deflate_init', ['i:' . ZLIB_ENCODING_GZIP], 'zend-object:DeflateContext:');

$deflate = deflate_init(ZLIB_ENCODING_GZIP);
if ($deflate !== false) {
    $phpDeflate = deflate_add($deflate, 'hello', ZLIB_SYNC_FLUSH);
    if ($phpDeflate !== false) {
        expect200(
            $jinx,
            'deflate_add',
            ['deflate:gzip', 's:hello', 'i:' . ZLIB_SYNC_FLUSH],
            'hex:' . bin2hex($phpDeflate),
            true
        );
    }
}

/* Common image header parsing without GD. */
$pngHeader = hex2bin('89504e470d0a1a0a0000000d4948445200000001000000010806000000');
if ($pngHeader === false) fail200('could not build PNG header fixture');
$phpImage = getimagesizefromstring($pngHeader);
if ($phpImage === false) fail200('PHP rejected PNG header fixture');
expect200(
    $jinx,
    'getimagesizefromstring',
    ['h:' . bin2hex($pngHeader)],
    'zend-array:' . count($phpImage)
);

if (function_exists('exif_tagname')) {
    expect200($jinx, 'exif_tagname', ['i:274'], (($v = exif_tagname(274)) === false ? 'bool:false' : 'string:' . $v));
}

$tmpImage = sys_get_temp_dir() . '/jinx-batch2-' . getmypid() . '.png';
file_put_contents($tmpImage, $pngHeader);
if (function_exists('exif_imagetype')) {
    $phpType = @exif_imagetype($tmpImage);
    expect200(
        $jinx,
        'exif_imagetype',
        ['s:' . $tmpImage],
        $phpType === false ? 'bool:false' : 'int:' . $phpType
    );
}
@unlink($tmpImage);

/* exec() only claims the no-byref single-argument path. */
expect200($jinx, 'exec', ['s:printf jinx'], 'string:jinx');

/* Optional OpenSSL backend: if it is compiled, its deterministic outputs must
 * match PHP exactly. If unavailable it must remain a fault, not a fake value. */
$hashProbe = jinx200($jinx, 'hash', ['s:sha256', 's:abc'], false, $code);
if ($code === 0 && !str_starts_with($hashProbe, 'null/fault:')) {
    if ($hashProbe !== 'string:' . hash('sha256', 'abc')) {
        fail200("hash sha256 parity mismatch\nPHP: " . hash('sha256', 'abc') . "\nJINX: {$hashProbe}");
    }
    expect200($jinx, 'md5', ['s:abc'], 'string:' . md5('abc'));
    expect200($jinx, 'sha1', ['s:abc'], 'string:' . sha1('abc'));
    expect200($jinx, 'md5_file', ['s:' . $root . '/README.md'], 'string:' . md5_file($root . '/README.md'));
    expect200($jinx, 'sha1_file', ['s:' . $root . '/README.md'], 'string:' . sha1_file($root . '/README.md'));
    expect200($jinx, 'hash_hmac', ['s:sha256', 's:data', 's:key'], 'string:' . hash_hmac('sha256', 'data', 'key'));
    expect200(
        $jinx,
        'hash_pbkdf2',
        ['s:sha256', 's:password', 's:salt', 'i:1000', 'i:32', 'b:false'],
        'string:' . hash_pbkdf2('sha256', 'password', 'salt', 1000, 32, false)
    );
    expect200(
        $jinx,
        'hash_hkdf',
        ['s:sha256', 's:key', 'i:16', 's:info', 's:salt'],
        'hex:' . bin2hex(hash_hkdf('sha256', 'key', 16, 'info', 'salt')),
        true
    );
}

/* Optional libmagic backend follows the same fail-closed rule. */
$finfoProbe = jinx200($jinx, 'finfo_buffer', ['finfo:default', 's:hello'], false, $code);
if ($code === 0 && !str_starts_with($finfoProbe, 'null/fault:') && class_exists('finfo')) {
    $fi = new finfo();
    $phpFinfo = $fi->buffer('hello');
    if ($phpFinfo !== false && $finfoProbe !== 'string:' . $phpFinfo) {
        fail200("finfo_buffer parity mismatch\nPHP: {$phpFinfo}\nJINX: {$finfoProbe}");
    }
}

/* Optional libcrypt backend likewise must either match or remain faulting. */
$cryptProbe = jinx200($jinx, 'crypt', ['s:password', 's:xx'], false, $code);
if ($code === 0 && !str_starts_with($cryptProbe, 'null/fault:')) {
    $phpCrypt = crypt('password', 'xx');
    if ($cryptProbe !== 'string:' . $phpCrypt) {
        fail200("crypt parity mismatch\nPHP: {$phpCrypt}\nJINX: {$cryptProbe}");
    }
}

echo 'PASS: second-wave procedural Oracle handlers preserve deterministic PHP return contracts; optional hash/finfo/crypt backends fail closed when unavailable' . PHP_EOL;
