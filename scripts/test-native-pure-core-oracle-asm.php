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
        'i' => (int)substr($arg, 2),
        'f' => (float)substr($arg, 2),
        'b' => substr($arg, 2) === 'true' || substr($arg, 2) === '1',
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
    ['similar_text', ['s:Hello World!', 's:Hello Peter!']],
    ['similar_text', ['s:', 's:']],
    ['strcoll', ['s:abc', 's:abd']],
    ['strcoll', ['s:same', 's:same']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:0']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:2', 'i:3']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:-3', 'i:2']],
    ['substr_replace', ['s:ABCDEFGH:/MNRPQR/', 's:bob', 'i:2', 'i:-3']],
    ['htmlspecialchars', ["s:<a href='x'>&\""]],
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
];

$cases[] = ['convert_uudecode', ['s:' . convert_uuencode('JINX oracle')]];

foreach ($cases as [$function, $args]) {
    $phpArgs = array_map('decodeArg', $args);
    $expected = encodeValue($function(...$phpArgs));

    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($function);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);

    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $actual = rtrim(implode(PHP_EOL, $output), "\r\n");

    if ($code !== 0) fail("oracle-call {$function} failed: {$actual}");
    if ($actual !== $expected) fail("oracle-call {$function} parity mismatch: PHP={$expected}, JINX={$actual}");
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

echo 'PASS: native Oracle ASM pure scalar/string core matches PHP for covered values' . PHP_EOL;
