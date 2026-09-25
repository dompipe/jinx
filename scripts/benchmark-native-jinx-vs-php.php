<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$iterations = max(1, (int) ($argv[1] ?? 100000));
$buildFirst = getenv('JINX_SKIP_BUILD') !== '1';

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

function runShell(string $cmd, ?string &$output = null): int
{
    $lines = [];
    exec($cmd . ' 2>&1', $lines, $code);
    $output = implode(PHP_EOL, $lines);
    return (int) $code;
}

function requireOk(string $cmd): string
{
    $code = runShell($cmd, $output);

    if ($code !== 0) {
        fail("command failed ({$code}): {$cmd}" . PHP_EOL . $output);
    }

    return (string) $output;
}

/**
 * These arguments mirror native/jinx_cli.c:first100_args().
 * They are intentionally simple, deterministic sample values so PHP and the
 * native Oracle/PASM dispatcher run the same named builtin set repeatedly.
 *
 * @return list<mixed>
 */
function phpSampleArgs(string $name): array
{
    static $cmp = null;
    static $pred = null;
    static $map = null;
    static $reduce = null;

    $cmp ??= static fn (mixed $a, mixed $b): int => $a <=> $b;
    $pred ??= static fn (mixed $v): bool => (bool) $v;
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
        'addcslashes' => ['dompipe', 'a..z'],
        'addslashes' => ["O'Reilly"],
        'array_all', 'array_any', 'array_find', 'array_find_key' => [$list, $pred],
        'array_change_key_case' => [$assoc],
        'array_chunk' => [$list, 2],
        'array_column' => [$rows, 'name'],
        'array_combine' => [['a', 'b'], [1, 2]],
        'array_count_values' => [['a', 'b', 'a']],
        'array_diff', 'array_diff_assoc', 'array_diff_key',
        'array_intersect', 'array_intersect_assoc', 'array_intersect_key',
        'array_replace', 'array_replace_recursive' => [$assoc, ['b' => 2, 'd' => 4]],
        'array_diff_uassoc', 'array_intersect_uassoc' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_diff_ukey', 'array_intersect_ukey' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_fill' => [0, 3, 'x'],
        'array_fill_keys' => [['a', 'b'], 1],
        'array_filter' => [$list, $pred],
        'array_flip' => [['a' => 'x', 'b' => 'y']],
        'array_is_list' => [$list],
        'array_key_exists' => ['a', $assoc],
        'array_key_first', 'array_key_last', 'array_keys', 'array_values' => [$assoc],
        'array_map' => [$map, $list],
        'array_merge', 'array_merge_recursive' => [$assoc, ['d' => 4]],
        'array_pad' => [$list, 6, 0],
        'array_product', 'array_sum' => [$list],
        'array_reduce' => [$list, $reduce, 0],
        'array_reverse' => [$list],
        'array_search' => [2, $assoc],
        'array_slice' => [$list, 1],
        'array_udiff', 'array_uintersect' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_udiff_assoc', 'array_uintersect_assoc' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_udiff_uassoc', 'array_uintersect_uassoc' => [$assoc, ['b' => 2, 'd' => 4], $cmp, $cmp],
        'asin' => [1.0],
        'asinh' => [1.0],
        'assert' => [true],
        'atan' => [1.0],
        'atan2' => [1.0, 1.0],
        'atanh' => [0.5],
        'base64_decode' => [base64_encode('dompipe')],
        'base64_encode' => ['dompipe'],
        'base_convert' => ['ff', 16, 10],
        'basename' => ['/tmp/dompipe.txt'],
        'bin2hex' => ['AB'],
        'bindec' => ['1010'],
        'boolval' => [1],
        'cal_days_in_month' => defined('CAL_GREGORIAN') ? [CAL_GREGORIAN, 2, 2024] : [],
        'cal_from_jd' => function_exists('gregoriantojd') && defined('CAL_GREGORIAN') ? [gregoriantojd(1, 1, 2024), CAL_GREGORIAN] : [],
        'cal_info' => [],
        'cal_to_jd' => defined('CAL_GREGORIAN') ? [CAL_GREGORIAN, 1, 1, 2024] : [],
        'call_user_func' => ['strlen', 'dompipe'],
        'call_user_func_array' => ['strlen', ['dompipe']],
        'ceil' => [1.2],
        'checkdate' => [2, 29, 2024],
        'checkdnsrr' => ['localhost', 'A'],
        'chop' => ["abc  "],
        'chr' => [65],
        'chunk_split' => ['abcdef', 2, '-'],
        'class_exists' => ['stdClass'],
        'class_implements', 'class_parents', 'class_uses' => ['ArrayObject'],
        'connection_aborted', 'connection_status' => [],
        'constant' => ['PHP_VERSION'],
        'convert_uudecode' => [convert_uuencode('dompipe')],
        'convert_uuencode' => ['dompipe'],
        'cos', 'cosh' => [1.0],
        'count' => [$list],
        'count_chars' => ['abcabc', 1],
        'crc32' => ['dompipe'],
        'crypt' => ['dompipe', 'ab'],
        'ctype_alnum', 'ctype_alpha', 'ctype_digit', 'ctype_graph', 'ctype_lower',
        'ctype_print', 'ctype_punct', 'ctype_space', 'ctype_upper', 'ctype_xdigit' => ['ABC123'],
        'ctype_cntrl' => ["\n"],
        default => [$list, 2, 'name', true, 'callback', 0, 'dompipe', 1],
    };
}

/** @param callable():mixed $fn */
function quietCall(callable $fn): mixed
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException($message, $severity);
    });

    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

if ($buildFirst) {
    requireOk('cd ' . escapeshellarg($root) . ' && ./scripts/build-native-jinx.sh');
}

if (!is_file($root . '/jinx')) {
    fail('missing native ./jinx; run ./scripts/build-native-jinx.sh first');
}

$namesOutput = requireOk('cd ' . escapeshellarg($root) . ' && ./jinx first100-list');
$names = [];
foreach (preg_split('/\R/', trim($namesOutput)) ?: [] as $line) {
    if (preg_match('/^\s*\d+\s+(.+)$/', $line, $m)) {
        $names[] = trim($m[1]);
    }
}

if ($names === []) {
    fail('native ./jinx first100-list returned no functions');
}

$phpNames = [];
$phpSkipped = [];
foreach ($names as $name) {
    if (!function_exists($name)) {
        $phpSkipped[] = [$name, 'not a global PHP function in this runtime'];
        continue;
    }

    $args = phpSampleArgs($name);

    try {
        quietCall(static fn (): mixed => $name(...$args));
    } catch (Throwable $e) {
        $phpSkipped[] = [$name, $e->getMessage()];
        continue;
    }

    $phpNames[] = [$name, $args];
}

if ($phpNames === []) {
    fail('no PHP-callable benchmark cases survived parameter validation');
}

$phpStart = hrtime(true);
foreach ($phpNames as [$name, $args]) {
    for ($i = 0; $i < $iterations; $i++) {
        quietCall(static fn (): mixed => $name(...$args));
    }
}
$phpNs = hrtime(true) - $phpStart;

$nativeOutput = requireOk('cd ' . escapeshellarg($root) . ' && ./jinx bench-first100 ' . escapeshellarg((string) $iterations));

if (!preg_match('/Per call ns:\s*([0-9.]+)/', $nativeOutput, $m)) {
    fail('could not parse native ./jinx bench-first100 output:' . PHP_EOL . $nativeOutput);
}

$nativePerCallNs = (float) $m[1];
$phpTotalCalls = count($phpNames) * $iterations;
$phpPerCallNs = $phpNs / max(1, $phpTotalCalls);
$ratio = $nativePerCallNs / max($phpPerCallNs, 0.000001);

printf("PHP vs native ./jinx function benchmark\n");
printf("Build path: scripts/build-native-jinx.sh -> ./jinx\n");
printf("PHP cases: %d/%d first native cases parameter-validated in this PHP runtime\n", count($phpNames), count($names));
printf("Iterations per function: %d\n", $iterations);
printf("\n");
printf("%-18s %14s %14s %10s\n", 'engine', 'functions', 'ns/call', 'ratio');
printf("%'-62s\n", '');
printf("%-18s %14d %14.1f %10s\n", 'php', count($phpNames), $phpPerCallNs, '1.00x');
printf("%-18s %14d %14.1f %9.2fx\n", './jinx native', count($names), $nativePerCallNs, $ratio);
printf("\nNative output:\n%s\n", $nativeOutput);

if ($phpSkipped !== []) {
    echo PHP_EOL . 'PHP-side skipped cases:' . PHP_EOL;
    foreach (array_slice($phpSkipped, 0, 20) as [$name, $reason]) {
        echo "- {$name}: {$reason}" . PHP_EOL;
    }
}
