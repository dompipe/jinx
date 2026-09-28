<?php

declare(strict_types=1);

require_once __DIR__ . '/native-oracle-sample-args.php';

$root = dirname(__DIR__);
$jinx = $root . '/jinx';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

$options = [
    'procedural' => false,
    'methods' => false,
    'family' => null,
    'limit' => 100,
    'names_only' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--procedural') {
        $options['procedural'] = true;
        continue;
    }
    if ($arg === '--methods') {
        $options['methods'] = true;
        continue;
    }
    if ($arg === '--names-only') {
        $options['names_only'] = true;
        continue;
    }
    if (str_starts_with($arg, '--family=')) {
        $options['family'] = substr($arg, strlen('--family='));
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $options['limit'] = max(1, (int) substr($arg, strlen('--limit=')));
        continue;
    }

    fail("Unknown option {$arg}; use --procedural, --methods, --family=<text>, --limit=<n>, --names-only");
}

if ($options['procedural'] && $options['methods']) {
    fail('Choose only one of --procedural or --methods');
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
    return jinxNativeOracleSampleArgs($name);
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

function includeName(string $name, array $options): bool
{
    if ($options['procedural'] && str_contains($name, '::')) return false;
    if ($options['methods'] && !str_contains($name, '::')) return false;

    if ($options['family'] !== null) {
        $needle = strtolower((string) $options['family']);
        return str_contains(strtolower(bucket($name)), $needle) || str_contains(strtolower($name), $needle);
    }

    return true;
}

$functionsText = run(escapeshellarg($jinx) . ' functions', $code);
if ($code !== 0) fail("./jinx functions failed:\n{$functionsText}");

$names = [];
foreach (preg_split('/\R/', $functionsText) as $line) {
    if (preg_match('/^\s*\d+\s+(.+)$/', $line, $m)) {
        $name = trim($m[1]);
        if (includeName($name, $options)) {
            $names[] = $name;
        }
    }
}

if ($names === []) fail('No function names parsed from ./jinx functions after filters');

$total = count($names);
$concrete = [];
$unwired = [];
$groups = [];

$contextBacked = [
    'func_num_args' => 'frame',
    'func_get_arg' => 'frame',
    'func_get_args' => 'frame',
    'get_called_class' => 'frame',
    'get_defined_vars' => 'frame',
    'compact' => 'frame',
    'extract' => 'frame',
    'debug_backtrace' => 'frame',
    'debug_print_backtrace' => 'frame',
    'error_get_last' => 'error',
    'error_clear_last' => 'error',
    'get_included_files' => 'script',
    'get_required_files' => 'script',
    'getlastmod' => 'script',
    'getmyinode' => 'script',
];

foreach ($names as $name) {
    if (isset($contextBacked[$name])) {
        $kind = $contextBacked[$name];
        if ($kind === 'frame') {
            $command = escapeshellarg($jinx) . ' oracle-frame-smoke';
        } elseif ($kind === 'error') {
            $command = escapeshellarg($jinx) . ' oracle-error-smoke';
        } else {
            $command = escapeshellarg($jinx)
                . ' oracle-script-context-smoke '
                . escapeshellarg($root . '/scripts/test-native-procedural-needed-200-oracle-asm.php')
                . ' '
                . escapeshellarg($root . '/README.md');
        }
        $out = run($command, $code);
        if ($code === 0 && str_contains($out, 'PASS:')) {
            $concrete[] = $name;
            continue;
        }
    }

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

if ($options['names_only']) {
    echo implode(PHP_EOL, array_slice($unwired, 0, $options['limit'])) . PHP_EOL;
    exit(0);
}

printf("Native Oracle callable audit\n");
printf("Scope: %s%s%s\n",
    $options['procedural'] ? 'procedural' : ($options['methods'] ? 'methods' : 'all'),
    $options['family'] !== null ? ', family=' . $options['family'] : '',
    ', first-unwired-limit=' . $options['limit']
);
printf("Registered names in scope: %d\n", $total);
printf("Concrete with sample args: %d\n", count($concrete));
printf("Null/fault or placeholder with sample args: %d\n", count($unwired));
printf("\nLargest unwired sets:\n");

foreach ($groups as $group => $items) {
    printf("- %s: %d\n", $group, count($items));
    printf("  examples: %s\n", implode(', ', array_slice($items, 0, 12)));
}

printf("\nFirst %d unwired names:\n%s\n", $options['limit'], implode(PHP_EOL, array_slice($unwired, 0, $options['limit'])));

if (count($unwired) > 0) {
    exit(1);
}
