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
    ['addcslashes', ['s:foo.bar', 's:.']],
    ['stripcslashes', ['s:foo\\nbar']],
    ['str_pad', ['s:Alien', 'i:10', 's:-=', 'i:2']],
    ['str_replace', ['s:world', 's:JINX', 's:hello world world']],
    ['str_ireplace', ['s:WORLD', 's:JINX', 's:hello World world']],
    ['strtr', ['s:baab', 's:ab', 's:01']],
    ['levenshtein', ['s:kitten', 's:sitting']],
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
];

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

echo 'PASS: native Oracle ASM pure scalar/string core matches PHP for covered values' . PHP_EOL;
