<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function decodeArg(string $arg): mixed
{
    if ($arg === 'null') return null;
    if (strlen($arg) < 2 || $arg[1] !== ':') fail("invalid typed argument {$arg}");
    return match ($arg[0]) {
        's' => substr($arg, 2),
        'h' => (($decoded = hex2bin(substr($arg, 2))) !== false
            ? $decoded
            : fail("invalid hexadecimal typed argument {$arg}")),
        'i' => (int)substr($arg, 2),
        'f' => (float)substr($arg, 2),
        'b' => substr($arg, 2) === 'true' || substr($arg, 2) === '1',
        'a' => array_fill(0, max(0, (int) substr($arg, 2)), null),
        default => fail("unsupported typed argument {$arg}"),
    };
}

function encodeValue(mixed $value): string
{
    if ($value === null) return 'null';
    if (is_bool($value)) return 'bool:' . ($value ? 'true' : 'false');
    if (is_int($value)) return 'int:' . $value;
    if (is_float($value)) return 'float:' . sprintf('%g', $value);
    if (is_string($value)) return 'string:' . $value;
    fail('unsupported PHP return type ' . get_debug_type($value));
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

$cases = [
    ['base64_encode', ['s:JINX oracle']],
    ['base64_decode', ['s:SklOWCBvcmFjbGU=']],
    ['base64_decode', ['s:SklO$WCBvcmFjbGU=', 'b:false']],
    ['base64_decode', ['s:SklO$WCBvcmFjbGU=', 'b:true']],
    ['urlencode', ['s:a b+c/~']],
    ['urldecode', ['s:a+b%2Bc%2F%7E']],
    ['rawurlencode', ['s:a b+c/~']],
    ['rawurldecode', ['s:a%20b%2Bc%2F~']],
    ['basename', ['s:/var/www/index.php']],
    ['basename', ['s:/var/www/index.php', 's:.php']],
    ['basename', ['s:/var/www/index.php', 's:index.php']],
    ['dirname', ['s:/var/www/index.php']],
    ['dirname', ['s:/var/www/html/index.php', 'i:2']],
    ['base_convert', ['s:ff', 'i:16', 'i:2']],
    ['bindec', ['s:101101']],
    ['hexdec', ['s:ff']],
    ['octdec', ['s:755']],
    ['decbin', ['i:45']],
    ['dechex', ['i:255']],
    ['decoct', ['i:493']],
    ['crc32', ['s:The quick brown fox jumps over the lazy dog']],
    ['checkdate', ['i:2', 'i:29', 'i:2024']],
    ['checkdate', ['i:2', 'i:29', 'i:2023']],
    ['nl2br', ["s:one\ntwo"]],
    ['nl2br', ["s:one\r\ntwo", 'b:false']],
    ['number_format', ['f:1234567.891', 'i:2']],
    ['number_format', ['f:-1234.5', 'i:1', 's:,', 's:_']],
    ['number_format', ['f:1234.5678', 'i:-1']],
    ['number_format', ['f:1234.5678', 'i:-2']],
    ['number_format', ['f:1234.5678', 'i:-3']],
    ['number_format', ['f:-0.01', 'i:0']],
    ['number_format', ['f:1.5', 'i:0']],
    ['addcslashes', ['s:foo.bar', 's:.']],
    ['stripcslashes', ['s:foo\\nbar']],
    ['str_pad', ['s:Alien', 'i:10', 's:-=', 'i:2']],
    ['str_pad', ['s:AlreadyLong', 'i:3', 's:', 'i:99']],
    ['str_replace', ['s:world', 's:JINX', 's:hello world world']],
    ['str_ireplace', ['s:WORLD', 's:JINX', 's:hello World world']],
    ['strtr', ['s:baab', 's:ab', 's:01']],
    ['levenshtein', ['s:kitten', 's:sitting']],
    ['levenshtein', ['s:kitten', 's:sitting', 'i:2', 'i:3', 'i:4']],
    ['levenshtein', ['s:kitten', 's:sitting', 'i:-1', 'i:1', 'i:1']],
    ['levenshtein', ['s:a', 's:abc', 'i:4']],
    ['levenshtein', ['s:a', 's:b', 'i:4', 'i:2']],
    ['levenshtein', ['s:abc', 's:', 'i:2', 'i:3', 'i:4']],
    ['levenshtein', ['s:', 's:abc', 'i:2', 'i:3', 'i:4']],
    ['similar_text', ['s:Hello World!', 's:Hello Peter!']],
    ['similar_text', ['s:', 's:']],
    ['strcoll', ['s:abc', 's:abd']],
    ['strcoll', ['s:same', 's:same']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:0']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:2', 'i:3']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:-3', 'i:2']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:2', 'i:-3']],
    ['htmlspecialchars', ["s:<a href='x'>&\""]],
    ['htmlspecialchars', ["s:<é>&\"", 'i:11', 's:UTF-8']],
    ['htmlspecialchars', ['s:&amp;<', 'i:11', 'null', 'b:false']],
    ['htmlspecialchars_decode', ['s:&lt;b&gt;&quot;x&quot;&#039;y&#039;&lt;/b&gt;']],
    ['sprintf', ['s:There are %u million bicycles in %s.', 'i:7', 's:Amsterdam']],
    ['sprintf', ['s:%2$s %1$d', 'i:42', 's:answer']],
    ['sprintf', ["s:%'.8s", 's:abc']],
    ['sprintf', ['s:%+05d', 'i:42']],
    ['sprintf', ['s:%*s', 'i:5', 's:x']],
    ['sprintf', ['s:%.*f', 'i:2', 'f:1.234']],
    ['sprintf', ['s:%b %o %x %X', 'i:10', 'i:10', 'i:255', 'i:255']],
    ['wordwrap', ['s:The quick brown fox jumped over the lazy dog.', 'i:20', "s:<br />\n"]],
    ['wordwrap', ['s:A very long woooooooooooord.', 'i:8', 's:|', 'b:true']],
    ['wordwrap', ["s:one two\nthree four", 'i:7']],
    ['convert_uuencode', ['s:JINX oracle']],
    ['soundex', ['s:Euler']],
    ['soundex', ['s:123']],
    ['metaphone', ['s:programming']],
    ['metaphone', ['s:knight']],
    ['metaphone', ['s:thought', 'i:4']],
    ['metaphone', ['s:1234']],
    ['quoted_printable_encode', ['s:A=B']],
    ['quoted_printable_decode', ['s:A=3DB']],
    ['quoted_printable_decode', ["s:hello=\r\nworld"]],
    ['utf8_encode', ['s:plain ASCII']],
    ['utf8_encode', ['s:café']],
    ['utf8_decode', ['s:plain ASCII']],
    ['utf8_decode', ['s:café']],
    ['strip_tags', ['s:<p>Test paragraph.</p><!-- Comment --> <a href="#fragment">Other text</a>']],
    ['strip_tags', ['s:<p>Test paragraph.</p><!-- Comment --> <a href="#fragment">Other text</a>', 's:<p><a>']],
    ['strip_tags', ['s:<a title="1>2">quoted</a> tail']],
    ['image_type_to_mime_type', ['i:1']],
    ['image_type_to_mime_type', ['i:2']],
    ['image_type_to_mime_type', ['i:9']],
    ['image_type_to_mime_type', ['i:17']],
    ['image_type_to_mime_type', ['i:18']],
    ['image_type_to_mime_type', ['i:19']],
    ['image_type_to_mime_type', ['i:20']],
    ['image_type_to_extension', ['i:2']],
    ['image_type_to_extension', ['i:2', 'b:false']],
    ['image_type_to_extension', ['i:15']],
    ['image_type_to_extension', ['i:19']],
    ['image_type_to_extension', ['i:20']],
    ['version_compare', ['s:1.0.0', 's:1.0.1']],
    ['version_compare', ['s:1.0RC1', 's:1.0']],
    ['version_compare', ['s:1.0pl1', 's:1.0']],
    ['version_compare', ['s:1.0-dev', 's:1.0-alpha']],
    ['version_compare', ['s:1.0-beta', 's:1.0RC1']],
    ['version_compare', ['s:1.0+meta', 's:1.0.meta']],
    ['version_compare', ['s:', 's:']],
    ['version_compare', ['s:', 's:1']],
    ['version_compare', ['s:2.0', 's:1.9', 's:>=']],
    ['version_compare', ['s:1.0-dev', 's:1.0-alpha', 's:lt']],
    ['version_compare', ['s:1.0', 's:1.0.0', 's:eq']],
    ['version_compare', ['s:1.0pl1', 's:1.0', 's:>']],
    ['version_compare', ['s:1.0', 's:1.0RC1', 's:ne']],
    ['version_compare', ['s:1.0RC1', 's:1.0', 's:<=']],
    ['version_compare', ['s:1.0', 's:1.0', 's:<>']],
    ['gettype', ['null']],
    ['gettype', ['b:true']],
    ['gettype', ['i:42']],
    ['gettype', ['f:1.25']],
    ['gettype', ['s:oracle']],
    ['gettype', ['a:3']],
    ['get_debug_type', ['null']],
    ['get_debug_type', ['b:false']],
    ['get_debug_type', ['i:42']],
    ['get_debug_type', ['f:1.25']],
    ['get_debug_type', ['s:oracle']],
    ['get_debug_type', ['a:3']],
    ['is_countable', ['a:3']],
    ['is_countable', ['s:oracle']],
    ['is_iterable', ['a:3']],
    ['is_iterable', ['i:42']],
    ['is_object', ['s:oracle']],
    ['is_resource', ['a:3']],
    ['getrandmax', []],
    ['mt_getrandmax', []],
    ['ip2long', ['s:127.0.0.1']],
    ['ip2long', ['s:255.255.255.255']],
    ['ip2long', ['s:0.0.0.0']],
    ['ip2long', ['s:777.777.777.777']],
    ['ip2long', ['s:192.168.001.1']],
    ['long2ip', ['i:2130706433']],
    ['long2ip', ['i:4294967295']],
    ['long2ip', ['i:-110000']],
    ['gregoriantojd', ['i:1', 'i:1', 'i:2024']],
    ['gregoriantojd', ['i:10', 'i:15', 'i:1582']],
    ['gregoriantojd', ['i:11', 'i:25', 'i:-4714']],
    ['gregoriantojd', ['i:11', 'i:24', 'i:-4714']],
    ['jdtogregorian', ['i:2460311']],
    ['jdtogregorian', ['i:0']],
    ['juliantojd', ['i:1', 'i:1', 'i:2024']],
    ['juliantojd', ['i:1', 'i:2', 'i:-4713']],
    ['juliantojd', ['i:1', 'i:1', 'i:-4713']],
    ['jdtojulian', ['i:2460311']],
    ['jdtojulian', ['i:0']],
    ['frenchtojd', ['i:1', 'i:1', 'i:1']],
    ['jdtofrench', ['i:2375840']],
    ['jewishtojd', ['i:1', 'i:1', 'i:5771']],
    ['jewishtojd', ['i:7', 'i:1', 'i:5772']],
    ['cal_to_jd', ['i:0', 'i:1', 'i:1', 'i:2024']],
    ['cal_to_jd', ['i:1', 'i:1', 'i:1', 'i:2024']],
    ['cal_to_jd', ['i:2', 'i:1', 'i:1', 'i:5771']],
    ['cal_to_jd', ['i:3', 'i:1', 'i:1', 'i:1']],
    ['cal_days_in_month', ['i:0', 'i:2', 'i:2003']],
    ['cal_days_in_month', ['i:0', 'i:2', 'i:2004']],
    ['cal_days_in_month', ['i:1', 'i:2', 'i:1900']],
    ['cal_days_in_month', ['i:2', 'i:1', 'i:5771']],
    ['cal_days_in_month', ['i:2', 'i:2', 'i:5771']],
    ['cal_days_in_month', ['i:2', 'i:7', 'i:5771']],
    ['cal_days_in_month', ['i:2', 'i:6', 'i:5772']],
    ['cal_days_in_month', ['i:2', 'i:7', 'i:5772']],
    ['cal_days_in_month', ['i:3', 'i:13', 'i:14']],
    ['jdtojewish', ['i:2460311']],
    ['jddayofweek', ['i:2460311']],
    ['jddayofweek', ['i:2460311', 'i:1']],
    ['jddayofweek', ['i:2460311', 'i:2']],
    ['jddayofweek', ['i:2460311', 'i:99']],
    ['jdmonthname', ['i:2460311', 'i:0']],
    ['jdmonthname', ['i:2460311', 'i:1']],
    ['jdmonthname', ['i:2460311', 'i:2']],
    ['jdmonthname', ['i:2460311', 'i:3']],
    ['jdmonthname', ['i:2460311', 'i:4']],
    ['jdmonthname', ['i:2375840', 'i:5']],
    ['jdmonthname', ['i:2460311', 'i:99']],
    ['easter_days', ['i:2024']],
    ['easter_days', ['i:1582', 'i:0']],
    ['easter_days', ['i:1582', 'i:2']],
    ['easter_days', ['i:1700', 'i:0']],
    ['easter_days', ['i:1700', 'i:1']],
    ['easter_days', ['i:2024', 'i:3']],
    ['easter_date', ['i:2024']],
    ['json_validate', ['s:{"a":[1,true,null,"x\\uD83D\\uDE00"],"b":-1.25e+3}']],
    ['json_validate', ['s:["a",{"b":2}]']],
    ['json_validate', ['s:{"a":1,}']],
    ['json_validate', ['s:01']],
    ['json_validate', ['s:"\\uD800"']],
    ['json_validate', ['s:true']],
    ['json_validate', ['s: null ']],
];

$cases[] = ['convert_uudecode', ['s:' . convert_uuencode('JINX oracle')]];

/*
 * hebrev() operates on legacy single-byte Hebrew data. Transport those
 * fixtures as hex instead of embedding non-UTF-8 bytes in a shell command;
 * escapeshellarg()/the shell may otherwise drop bytes under a non-UTF-8
 * LC_CTYPE before ./jinx ever receives them.
 */
$cases[] = ['hebrev', ['h:e0e1e2']];
$cases[] = ['hebrev', ['h:e0e1e220414243']];
$cases[] = ['hebrev', ['h:28e0e129']];
$cases[] = ['hebrev', ['h:e0e1e220e3e4e520414243', 'i:5']];

foreach ($cases as [$function, $args]) {
    $phpArgs = array_map('decodeArg', $args);
    $phpValue = $function(...$phpArgs);
    $stringResult = is_string($phpValue);
    $expected = $stringResult
        ? 'hex:' . bin2hex($phpValue)
        : encodeValue($phpValue);

    /*
     * String results use the CLI's hex transport so parity is byte-exact.
     * PHP exec() splits stdout into lines and can otherwise erase a CR from
     * CRLF results such as nl2br("one\\r\\ntwo", false), making identical
     * native/PHP strings look unequal after the test harness reconstructs
     * stdout with PHP_EOL.
     */
    $commandName = $stringResult ? 'oracle-call-hex' : 'oracle-call';
    $command = escapeshellarg($jinx) . ' ' . $commandName . ' ' . escapeshellarg($function);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $actual = rtrim(implode(PHP_EOL, $output), "\r\n");

    if ($code !== 0) fail("{$commandName} {$function} failed: {$actual}");
    if ($actual !== $expected) fail("{$commandName} {$function} parity mismatch: PHP={$expected}, JINX={$actual}");
}


$inetAddresses = [
    '127.0.0.1',
    '::1',
    '::2',
    '::35',
    '::255',
    '::1024',
    '2001:0db8:85a3:08d3:1319:8a2e:0370:7344',
    '2001:0db8:1234:0000:0000:0000:0000:0000',
    '2001:0db8:1234:FFFF:FFFF:FFFF:FFFF:FFFF',
    '',
];

foreach ($inetAddresses as $address) {
    $phpPacked = inet_pton($address);
    $expected = is_string($phpPacked)
        ? 'hex:' . bin2hex($phpPacked)
        : encodeValue($phpPacked);

    $command = escapeshellarg($jinx)
        . ' oracle-call-hex inet_pton '
        . escapeshellarg('s:' . $address);

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $actual = rtrim(implode(PHP_EOL, $output), "\r\n");

    if ($code !== 0) fail("oracle-call-hex inet_pton failed for {$address}: {$actual}");
    if ($actual !== $expected) {
        fail("inet_pton parity mismatch for {$address}: PHP={$expected}, JINX={$actual}");
    }

    if (is_string($phpPacked)) {
        $expectedText = encodeValue(inet_ntop($phpPacked));
        $ntopCommand = escapeshellarg($jinx)
            . ' oracle-call inet_ntop '
            . escapeshellarg('h:' . bin2hex($phpPacked));

        $ntopOutput = [];
        $ntopCode = 0;
        exec($ntopCommand . ' 2>&1', $ntopOutput, $ntopCode);
        $actualText = rtrim(implode(PHP_EOL, $ntopOutput), "\r\n");

        if ($ntopCode !== 0) fail("oracle-call inet_ntop failed for {$address}: {$actualText}");
        if ($actualText !== $expectedText) {
            fail("inet_ntop parity mismatch for {$address}: PHP={$expectedText}, JINX={$actualText}");
        }
    }
}

$invalidNtopExpected = encodeValue(inet_ntop("\x00"));
$invalidNtopCommand = escapeshellarg($jinx) . ' oracle-call inet_ntop ' . escapeshellarg('h:00');
$invalidNtopOutput = [];
$invalidNtopCode = 0;
exec($invalidNtopCommand . ' 2>&1', $invalidNtopOutput, $invalidNtopCode);
$invalidNtopActual = rtrim(implode(PHP_EOL, $invalidNtopOutput), "\r\n");
if ($invalidNtopCode !== 0) fail("oracle-call inet_ntop invalid-length case failed: {$invalidNtopActual}");
if ($invalidNtopActual !== $invalidNtopExpected) {
    fail("inet_ntop invalid-length parity mismatch: PHP={$invalidNtopExpected}, JINX={$invalidNtopActual}");
}

$printfArgs = ['printf:%u:%s', 7, 'Amsterdam'];
ob_start();
$printfReturn = printf(...$printfArgs);
$printfText = (string)ob_get_clean();
$printfExpected = $printfText . 'int:' . $printfReturn;

$printfCommand = escapeshellarg($jinx)
    . ' oracle-call printf '
    . escapeshellarg('s:' . $printfArgs[0])
    . ' ' . escapeshellarg('i:' . (string)$printfArgs[1])
    . ' ' . escapeshellarg('s:' . $printfArgs[2]);

$printfOutput = [];
$printfCode = 0;
exec($printfCommand . ' 2>&1', $printfOutput, $printfCode);
$printfActual = rtrim(implode(PHP_EOL, $printfOutput), "\r\n");

if ($printfCode !== 0) fail("oracle-call printf failed: {$printfActual}");
if ($printfActual !== $printfExpected) {
    fail("oracle-call printf parity mismatch: PHP={$printfExpected}, JINX={$printfActual}");
}

// These are valid PHP overloads, but the scalar native helpers do not implement
// their semantics. They must fault instead of silently ignoring arguments.
$unsupportedCases = [
    ['str_replace', ['s:world', 's:JINX', 's:hello world world', 'i:0'], 'replacement count'],
    ['str_ireplace', ['s:WORLD', 's:JINX', 's:hello World world', 'i:0'], 'replacement count'],
    ['str_replace', ['a:0', 's:JINX', 's:hello world'], 'array/coercion'],
    ['strtr', ['s:abc', 'a:0'], 'three-string overload'],
    ['levenshtein', ['s:kitten', 's:sitting', 'i:9223372036854775807'], 'cost overflow'],
    ['htmlspecialchars', ['h:e93c', 'i:11', 's:ISO-8859-1'], 'non-UTF-8'],
    ['htmlspecialchars', ["s:'", 'i:51'], 'non-HTML401'],
    ['htmlspecialchars', ['h:ff3c'], 'invalid UTF-8'],
];
foreach ($unsupportedCases as [$function, $args, $reason]) {
    $phpArgs = array_map('decodeArg', $args);
    $function(...$phpArgs);
    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($function);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $actual = implode(PHP_EOL, $output);
    if ($code === 0 || !str_contains($actual, 'null/fault:')) {
        fail("oracle-call {$function} must explicitly fault for {$reason}: {$actual}");
    }
}

echo 'PASS: native Oracle ASM pure scalar/string core matches PHP for covered values; unsupported overloads fault' . PHP_EOL;
