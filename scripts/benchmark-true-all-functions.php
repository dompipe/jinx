<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterations = max(1, (int)($argv[1] ?? 1000));
$buildFirst = getenv('JINX_SKIP_BUILD') !== '1';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function runShell(string $cmd, ?string &$output = null): int
{
    $lines = [];
    exec($cmd . ' 2>&1', $lines, $code);
    $output = implode(PHP_EOL, $lines);
    return (int)$code;
}

function requireOk(string $cmd): string
{
    $code = runShell($cmd, $output);
    if ($code !== 0) {
        fail("command failed ({$code}): {$cmd}\n{$output}");
    }
    return (string)$output;
}

/** @param callable():mixed $fn */
function quietCall(callable $fn): mixed
{
    $startedBuffer = false;
    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException($message, $severity);
    });

    try {
        ob_start();
        $startedBuffer = true;
        return $fn();
    } finally {
        if ($startedBuffer && ob_get_level() > 0) {
            ob_end_clean();
        }
        restore_error_handler();
    }
}

/** @return list<mixed> */
function phpSampleArgs(string $name): array
{
    static $cmp = null;
    static $pred = null;
    static $map = null;
    static $reduce = null;

    $cmp ??= static fn (mixed $a, mixed $b): int => $a <=> $b;
    $pred ??= static fn (mixed $v): bool => (bool)$v;
    $map ??= static fn (mixed $v): mixed => is_int($v) ? $v + 1 : $v;
    $reduce ??= static fn (mixed $carry, mixed $v): mixed => $carry + (is_numeric($v) ? $v : 0);

    $list = [1, 2, 3, 4];
    $assoc = ['a' => 1, 'b' => 2, 'c' => 3];
    $rows = [
        ['id' => 1, 'name' => 'Ada'],
        ['id' => 2, 'name' => 'Grace'],
    ];

    return match ($name) {
        'abs' => [-42],
        'acos' => [1.0],
        'acosh' => [2.0],
        'asin' => [1.0],
        'asinh' => [1.0],
        'atan' => [1.0],
        'atan2' => [1.0, 1.0],
        'atanh' => [0.5],
        'ceil' => [1.2],
        'floor' => [1.8],
        'sqrt' => [4.0],
        'sin' => [1.0],
        'sinh' => [1.0],
        'tan' => [1.0],
        'tanh' => [1.0],
        'cos' => [1.0],
        'cosh' => [1.0],
        'exp' => [1.0],
        'expm1' => [1.0],
        'log' => [2.0],
        'log10' => [10.0],

        'strlen' => ['dompipe'],
        'str_contains' => ['dompipe', 'pipe'],
        'str_starts_with' => ['dompipe', 'dom'],
        'str_ends_with' => ['dompipe', 'pipe'],
        'strcmp', 'strcasecmp' => ['abc', 'abd'],
        'substr' => ['dompipe', 3],
        'strpos', 'stripos', 'strrpos', 'strripos' => ['dompipe', 'pipe'],
        'strtolower', 'strtoupper', 'trim', 'ltrim', 'rtrim', 'ucfirst', 'lcfirst', 'ucwords' => [' dompipe '],
        'str_replace' => ['pipe', 'core', 'dompipe'],
        'explode' => [',', 'a,b,c'],
        'implode', 'join' => [',', ['a', 'b', 'c']],
        'basename' => ['/tmp/dompipe.txt'],
        'dirname' => ['/tmp/dompipe.txt'],
        'bin2hex' => ['AB'],
        'chr' => [65],
        'ord' => ['A'],
        'base64_encode' => ['dompipe'],
        'base64_decode' => [base64_encode('dompipe')],
        'base_convert' => ['ff', 16, 10],
        'bindec' => ['1010'],
        'htmlspecialchars', 'htmlentities' => ['<b>dompipe</b>'],
        'nl2br' => ["a\nb"],
        'number_format' => [1234.56, 2],
        'sprintf' => ['%s:%d', 'id', 7],
        'vsprintf' => ['%s:%d', ['id', 7]],
        'wordwrap' => ['abcdef', 3],
        'addslashes' => ["O'Reilly"],
        'addcslashes' => ['dompipe', 'a..z'],
        'chop' => ["abc  "],
        'chunk_split' => ['abcdef', 2, '-'],
        'count_chars' => ['abcabc', 1],
        'convert_uuencode' => ['dompipe'],
        'convert_uudecode' => [convert_uuencode('dompipe')],

        'ctype_alnum' => ['ABC123'],
        'ctype_alpha' => ['ABC'],
        'ctype_cntrl' => ["\n"],
        'ctype_digit' => ['123'],
        'ctype_graph' => ['ABC123!'],
        'ctype_lower' => ['abc'],
        'ctype_print' => ['abc 123'],
        'ctype_punct' => ['!?.'],
        'ctype_space' => [" \t\n"],
        'ctype_upper' => ['ABC'],
        'ctype_xdigit' => ['aF09'],

        'boolval' => [1],
        'floatval' => ['1.25'],
        'intval' => ['42'],
        'strval' => [42],
        'is_array' => [$list],
        'is_bool' => [true],
        'is_float' => [1.0],
        'is_int' => [1],
        'is_numeric' => ['123'],
        'is_string' => ['abc'],
        'is_null' => [null],
        'is_countable' => [$list],
        'is_scalar' => [1],

        'count' => [$list],
        'in_array' => [2, $list, true],
        'array_key_exists' => ['a', $assoc],
        'array_is_list' => [$list],
        'array_key_first', 'array_key_last', 'array_keys', 'array_values' => [$assoc],
        'array_sum', 'array_product' => [$list],
        'array_reverse' => [$list],
        'array_unique' => [[1, 1, 2, 3]],
        'array_slice' => [$list, 1],
        'array_search' => [2, $list, true],
        'array_map' => [$map, $list],
        'array_filter' => [$list, $pred],
        'array_reduce' => [$list, $reduce, 0],
        'array_merge', 'array_merge_recursive' => [$assoc, ['d' => 4]],
        'array_replace', 'array_replace_recursive' => [$assoc, ['b' => 9]],
        'array_pad' => [$list, 6, 0],
        'array_chunk' => [$list, 2],
        'array_column' => [$rows, 'name'],
        'array_combine' => [['a', 'b'], [1, 2]],
        'array_count_values' => [['a', 'b', 'a']],
        'array_diff', 'array_diff_assoc', 'array_diff_key', 'array_intersect', 'array_intersect_assoc', 'array_intersect_key' => [$assoc, ['b' => 2, 'd' => 4]],
        'array_diff_uassoc', 'array_diff_ukey', 'array_intersect_uassoc', 'array_intersect_ukey' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_udiff', 'array_uintersect' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_udiff_assoc', 'array_uintersect_assoc' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_udiff_uassoc', 'array_uintersect_uassoc' => [$assoc, ['b' => 2, 'd' => 4], $cmp, $cmp],
        'array_fill' => [0, 3, 'x'],
        'array_fill_keys' => [['a', 'b'], 1],
        'array_flip' => [['a' => 'x', 'b' => 'y']],

        'json_encode' => [['a' => 1]],
        'json_decode' => ['{"a":1}', true],
        'preg_match' => ['/pipe/', 'dompipe'],
        'preg_replace' => ['/pipe/', 'core', 'dompipe'],

        'md5', 'sha1', 'crc32' => ['dompipe'],
        'hash' => ['sha256', 'dompipe'],
        'hash_hmac' => ['sha256', 'dompipe', 'secret'],
        'password_hash' => ['dompipe-secret', PASSWORD_BCRYPT],
        'password_verify' => ['dompipe-secret', password_hash('dompipe-secret', PASSWORD_BCRYPT)],
        'random_bytes' => [8],
        'random_int' => [1, 100],

        'class_exists' => ['stdClass'],
        'interface_exists' => ['Throwable'],
        'trait_exists' => ['NonexistentTrait'],
        'function_exists' => ['strlen'],
        'defined' => ['PHP_VERSION'],
        'constant' => ['PHP_VERSION'],
        'extension_loaded' => ['json'],
        'method_exists' => [DateTime::class, 'format'],
        'property_exists' => [DateTime::class, 'date'],

        'checkdate' => [2, 29, 2024],
        'checkdnsrr' => ['localhost', 'A'],
        'time', 'getmypid', 'memory_get_usage', 'memory_get_peak_usage', 'connection_status', 'connection_aborted' => [],
        'date' => ['Y-m-d', 1704067200],
        'gmdate' => ['Y-m-d', 1704067200],
        'mktime', 'gmmktime' => [0, 0, 0, 1, 1, 2024],
        'cal_days_in_month' => defined('CAL_GREGORIAN') ? [CAL_GREGORIAN, 2, 2024] : [],
        'cal_info' => [],
        'cal_from_jd' => function_exists('gregoriantojd') && defined('CAL_GREGORIAN') ? [gregoriantojd(1, 1, 2024), CAL_GREGORIAN] : [],
        'cal_to_jd' => defined('CAL_GREGORIAN') ? [CAL_GREGORIAN, 1, 1, 2024] : [],

        'call_user_func' => ['strlen', 'dompipe'],
        'call_user_func_array' => ['strlen', ['dompipe']],

        default => ['dompipe', 2, 'name', true],
    };
}

function parseJinxBench(string $text): array
{
    $out = [];
    foreach ([
        'functions' => '/Functions:\s*(\d+)/',
        'iterations' => '/Iterations per function:\s*(\d+)/',
        'total_dispatches' => '/Total dispatches:\s*([0-9.]+)/',
        'elapsed_ms' => '/Elapsed ms:\s*([0-9.]+)/',
        'ns_per_call' => '/Per dispatch ns:\s*([0-9.]+)/',
        'concrete' => '/Concrete non-null first-pass returns:\s*(\d+)\/(\d+)/',
        'placeholders' => '/Null\/fault placeholder first-pass returns:\s*(\d+)\/(\d+)/',
    ] as $key => $re) {
        if (preg_match($re, $text, $m)) {
            $out[$key] = count($m) === 3 ? [(float)$m[1], (float)$m[2]] : (float)$m[1];
        }
    }
    return $out;
}

function fmt(float $n): string
{
    return number_format($n, 2, '.', ',');
}

if ($buildFirst) {
    requireOk('cd ' . escapeshellarg($root) . ' && ./scripts/build-native-jinx.sh');
}

if (!is_file($root . '/jinx')) {
    fail('missing native ./jinx; run ./scripts/build-native-jinx.sh first');
}

$namesOutput = requireOk('cd ' . escapeshellarg($root) . ' && ./jinx functions');
$names = [];
foreach (preg_split('/\R/', trim($namesOutput)) ?: [] as $line) {
    if (preg_match('/^\s*\d+\s+(.+)$/', $line, $m)) {
        $names[] = trim($m[1]);
    }
}

if ($names === []) {
    fail('native ./jinx functions returned no functions');
}

$phpCases = [];
$phpSkipped = [];
foreach ($names as $name) {
    $lower = strtolower($name);
    if (str_contains($lower, '::') || !function_exists($lower)) {
        $phpSkipped[] = [$name, 'not a global PHP function in this runtime'];
        continue;
    }

    $args = phpSampleArgs($lower);
    try {
        quietCall(static fn (): mixed => $lower(...$args));
    } catch (Throwable $e) {
        $phpSkipped[] = [$name, $e::class . ': ' . $e->getMessage()];
        continue;
    }

    $phpCases[] = [$lower, $args];
}

$phpStart = hrtime(true);
$phpCalls = 0;
foreach ($phpCases as [$name, $args]) {
    for ($i = 0; $i < $iterations; $i++) {
        quietCall(static fn (): mixed => $name(...$args));
        $phpCalls++;
    }
}
$phpElapsedNs = hrtime(true) - $phpStart;
$phpElapsedMs = $phpElapsedNs / 1_000_000.0;
$phpNsPerCall = $phpCalls > 0 ? $phpElapsedNs / $phpCalls : 0.0;
$phpCallsPerSec = $phpElapsedNs > 0 ? $phpCalls / ($phpElapsedNs / 1_000_000_000.0) : 0.0;

$nativeOutput = requireOk('cd ' . escapeshellarg($root) . ' && ./jinx bench-all-functions ' . escapeshellarg((string)$iterations));
$native = parseJinxBench($nativeOutput);

foreach (['functions', 'iterations', 'total_dispatches', 'elapsed_ms', 'ns_per_call'] as $required) {
    if (!array_key_exists($required, $native)) {
        fail("could not parse native field {$required} from ./jinx output:\n{$nativeOutput}");
    }
}

$nativeElapsedMs = (float)$native['elapsed_ms'];
$nativeCalls = (float)$native['total_dispatches'];
$nativeNsPerCall = (float)$native['ns_per_call'];
$nativeCallsPerSec = $nativeElapsedMs > 0.0 ? $nativeCalls / ($nativeElapsedMs / 1000.0) : 0.0;
$ratio = $phpNsPerCall > 0.0 ? $nativeNsPerCall / $phpNsPerCall : 0.0;
$speed = $nativeNsPerCall > 0.0 ? $phpNsPerCall / $nativeNsPerCall : 0.0;

printf("JINX true all-functions benchmark\n");
printf("Iterations per function: %d\n", $iterations);
printf("Native function names: %d\n", count($names));
printf("PHP benchmarkable global functions: %d\n", count($phpCases));
printf("PHP skipped/unavailable: %d\n", count($phpSkipped));
printf("\n");
printf("%-18s %12s %14s %14s %14s %12s\n", 'engine', 'functions', 'total calls', 'elapsed ms', 'ns/call', 'calls/sec');
printf("%'-94s\n", '');
printf("%-18s %12d %14s %14s %14s %12s\n", 'php direct', count($phpCases), number_format($phpCalls), fmt($phpElapsedMs), fmt($phpNsPerCall), fmt($phpCallsPerSec));
printf("%-18s %12d %14s %14s %14s %12s\n", './jinx native', (int)$native['functions'], number_format($nativeCalls), fmt($nativeElapsedMs), fmt($nativeNsPerCall), fmt($nativeCallsPerSec));
printf("\n");
printf("Native/PHP ns-per-call ratio: %.3fx\n", $ratio);
printf("Native speed versus PHP direct: %.3fx\n", $speed);

if (isset($native['concrete'])) {
    [$concrete, $total] = $native['concrete'];
    printf("Native concrete first-pass returns: %d/%d\n", (int)$concrete, (int)$total);
}
if (isset($native['placeholders'])) {
    [$nullFault, $total] = $native['placeholders'];
    printf("Native null/fault first-pass returns: %d/%d\n", (int)$nullFault, (int)$total);
}

printf("\nNative raw benchmark output:\n%s\n", $nativeOutput);

if ($phpSkipped !== []) {
    echo PHP_EOL . 'First PHP-side skipped/unavailable cases:' . PHP_EOL;
    foreach (array_slice($phpSkipped, 0, 30) as [$name, $reason]) {
        echo "- {$name}: {$reason}" . PHP_EOL;
    }
}
