<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

if (!is_file($jinx) || !is_executable($jinx)) {
    fail('repository-root native ./jinx missing or not executable; run ./scripts/build-native-jinx.sh first');
}

function run(string $command, int &$code = null): string
{
    $out = [];
    $code = 0;
    exec($command . ' 2>&1', $out, $code);
    return rtrim(implode(PHP_EOL, $out), "\r\n");
}

function sampleArgs(string $name): array
{
    if (str_contains($name, '::')) {
        return [];
    }

    if ($name === 'abs') return ['i:-42'];
    if (preg_match('/^(acos|asin|atan|ceil|cos|cosh|sin|sinh|sqrt|tan|tanh|log|exp|expm1|log1p)$/', $name)) return ['f:1'];
    if ($name === 'acosh') return ['f:2'];
    if ($name === 'atan2' || $name === 'hypot' || $name === 'fmod' || $name === 'fdiv') return ['f:3', 'f:2'];
    if ($name === 'pow' || $name === 'fpow') return ['i:2', 'i:8'];
    if ($name === 'round') return ['f:1.5'];
    if ($name === 'intdiv') return ['i:7', 'i:2'];
    if ($name === 'pi') return [];

    if (str_starts_with($name, 'array_') || in_array($name, ['count', 'sizeof', 'current', 'key', 'next', 'prev', 'reset', 'end'], true)) {
        return ['za:sample'];
    }

    if (str_starts_with($name, 'ctype_')) return ['s:ABC123'];

    if (preg_match('/^(str|substr|strip|trim|ltrim|rtrim|chop|add|quotemeta|bin2hex|hex2bin|base64|crc32|chr|ord|uc|lc|wordwrap|html|nl2br)/', $name)) {
        return ['s:dompipe'];
    }

    if (preg_match('/^(is_|boolval|intval|floatval|doubleval|strval)$/', $name)) {
        return ['s:42'];
    }

    if (str_contains($name, 'date') || str_contains($name, 'time')) {
        return ['s:Y-m-d', 'i:1704067200'];
    }

    if (str_contains($name, 'json')) {
        return ['s:{"a":1}'];
    }

    return ['s:dompipe', 'i:0', 'b:true'];
}

function bucket(string $name): string
{
    if (str_contains($name, '::')) {
        [$class] = explode('::', $name, 2);
        if (preg_match('/^(AppendIterator|ArrayIterator|ArrayObject|CachingIterator|CallbackFilterIterator|DirectoryIterator|FilesystemIterator|FilterIterator|GlobIterator|InfiniteIterator|IteratorIterator|LimitIterator|MultipleIterator|NoRewindIterator|ParentIterator|Recursive)/', $class)) {
            return 'SPL / iterator class methods';
        }
        if (preg_match('/^(ArgumentCountError|ArithmeticError|AssertionError|CompileError|DivisionByZeroError|Error|ErrorException|Exception|ParseError|TypeError|ValueError)/', $class)) {
            return 'Throwable / error class methods';
        }
        if (preg_match('/^(Reflection|Closure|Generator|WeakMap|WeakReference|Fiber)/', $class)) {
            return 'Reflection / VM object methods';
        }
        if (preg_match('/^(Date|DateTime|DateInterval|DatePeriod|DateTimeZone)/', $class)) {
            return 'Date/time class methods';
        }
        return 'Other class methods';
    }

    return match (true) {
        str_starts_with($name, 'array_') => 'array_* procedural functions',
        str_starts_with($name, 'ctype_') => 'ctype_* functions',
        str_starts_with($name, 'date_') || str_contains($name, 'time') => 'date/time procedural functions',
        str_starts_with($name, 'json_') => 'json_* functions',
        preg_match('/^(file|fopen|fclose|fread|fwrite|fseek|stat|lstat|is_file|is_dir|mkdir|unlink|glob|pathinfo|realpath|scandir)/', $name) === 1 => 'filesystem / stream functions',
        preg_match('/^(curl|dns|socket|stream_socket|fsockopen|pfsockopen|gethost)/', $name) === 1 => 'network / curl / socket functions',
        preg_match('/^(preg_|ereg|mb_|iconv)/', $name) === 1 => 'regex / encoding functions',
        preg_match('/^(image|gd_|exif)/', $name) === 1 => 'graphics / EXIF functions',
        preg_match('/^(openssl|hash|password_|crypt|sodium)/', $name) === 1 => 'crypto / hash functions',
        preg_match('/^(class_|interface_|trait_|method_|property_|function_|get_|defined|constant)/', $name) === 1 => 'introspection functions',
        preg_match('/^(str|substr|strip|trim|ltrim|rtrim|chop|add|quotemeta|bin2hex|hex2bin|base64|crc32|chr|ord|uc|lc|wordwrap|html|nl2br)/', $name) === 1 => 'string functions',
        preg_match('/^(abs|acos|asin|atan|ceil|cos|exp|floor|log|max|min|pow|round|sin|sqrt|tan|is_finite|is_nan|pi|rand|mt_|random_)/', $name) === 1 => 'math / random functions',
        default => 'other procedural functions',
    };
}

$functionsText = run(escapeshellarg($jinx) . ' functions', $code);
if ($code !== 0) fail("./jinx functions failed:\n{$functionsText}");

$names = [];
foreach (preg_split('/\R/', $functionsText) as $line) {
    if (preg_match('/^\s*\d+\s+(.+)$/', $line, $m)) {
        $names[] = trim($m[1]);
    }
}

if ($names === []) fail('No function names parsed from ./jinx functions');

$total = count($names);
$concrete = [];
$unwired = [];
$groups = [];

foreach ($names as $name) {
    $args = sampleArgs($name);
    $command = escapeshellarg($jinx) . ' oracle-call ' . escapeshellarg($name);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $out = run($command, $code);
    if ($code === 0 && $out !== '' && !str_starts_with($out, 'null/fault')) {
        $concrete[] = $name;
        continue;
    }

    $unwired[] = $name;
    $bucket = bucket($name);
    $groups[$bucket][] = $name;
}

uasort($groups, static fn (array $a, array $b): int => count($b) <=> count($a));

printf("Native Oracle callable audit\n");
printf("Registered names: %d\n", $total);
printf("Concrete with sample args: %d\n", count($concrete));
printf("Null/fault or placeholder with sample args: %d\n", count($unwired));
printf("\nLargest unwired sets:\n");

foreach ($groups as $group => $items) {
    printf("- %s: %d\n", $group, count($items));
    printf("  examples: %s\n", implode(', ', array_slice($items, 0, 12)));
}

printf("\nFirst 100 unwired names:\n%s\n", implode(PHP_EOL, array_slice($unwired, 0, 100)));

if (count($unwired) > 0) {
    exit(1);
}
