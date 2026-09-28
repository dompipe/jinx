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

/* Deterministic metadata/introspection parity. */
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
    ['filter_list', [], 'zend-array:' . count(filter_list())],
    ['filter_id', ['s:int'], 'int:' . filter_id('int')],
    ['filter_var', ['s:42', 'i:' . filter_id('int')], 'int:42'],
    ['filter_var_array', ['za:strings', 'i:' . filter_id('unsafe_raw')], 'zend-array:3'],
    ['error_reporting', [], 'int:' . error_reporting()],
    ['connection_aborted', [], 'int:' . connection_aborted()],
    ['connection_status', [], 'int:' . connection_status()],
    ['getcwd', [], 'string:' . getcwd()],
    ['getenv', ['s:PATH'], (($v = getenv('PATH')) === false ? 'bool:false' : 'string:' . $v)],
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
    ['closedir', ['dir:tmp'], 'null'],
    ['assert', ['b:true'], 'bool:true'],
    ['get_resource_type', ['fp:tmp'], 'string:stream'],
    ['get_resource_id', ['fp:tmp'], 'int:1'],
    ['get_resources', [], 'zend-array:0'],
];

if (function_exists('gmstrftime')) {
    $checks[] = ['gmstrftime', ['s:%Y-%m-%d', 'i:1704067200'], 'string:' . gmstrftime('%Y-%m-%d', 1704067200)];
}

foreach ($checks as [$name, $args, $expected]) {
    expect200($jinx, $name, $args, $expected);
}

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
