<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/runtime/WebNativeFunctions.php';

use jinx\web\WebNativeFunctionRegistry;
use jinx\web\WebNativeFunctions;

$iterations = max(1, (int) ($argv[1] ?? 1000));
$targetCount = max(1, (int) ($argv[2] ?? 100));

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
    exit(1);
}

/**
 * @return list<mixed>|null
 */
function sampleArgs(string $name): ?array
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
        'addcslashes' => ['abc', 'a..z'],
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
        'array_rand' => [$assoc, 1],
        'array_reduce' => [$list, $reduce, 0],
        'array_reverse' => [$list],
        'array_search' => [2, $assoc],
        'array_slice' => [$list, 1],
        'array_udiff', 'array_uintersect' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_udiff_assoc', 'array_uintersect_assoc' => [$assoc, ['b' => 2, 'd' => 4], $cmp],
        'array_udiff_uassoc', 'array_uintersect_uassoc' => [$assoc, ['b' => 2, 'd' => 4], $cmp, $cmp],
        'array_unique' => [['a', 'a', 'b']],
        'asin' => [1.0],
        'asinh' => [1.0],
        'assert' => [true],
        'assert_options' => [ASSERT_ACTIVE],
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
        'cal_days_in_month' => [CAL_GREGORIAN, 2, 2024],
        'cal_from_jd' => [gregoriantojd(1, 1, 2024), CAL_GREGORIAN],
        'cal_info' => [],
        'cal_to_jd' => [CAL_GREGORIAN, 1, 1, 2024],
        'call_user_func' => ['strlen', 'dompipe'],
        'call_user_func_array' => ['strlen', ['dompipe']],
        'ceil' => [1.2],
        'checkdate' => [2, 29, 2024],
        'checkdnsrr' => ['localhost', 'A'],
        'chop' => ["abc  "],
        'chr' => [65],
        'chunk_split' => ['abcdef', 2, '-'],
        'class_alias' => ['stdClass', 'JinxBenchAlias' . bin2hex(random_bytes(3))],
        'class_exists' => ['stdClass'],
        'class_implements', 'class_parents', 'class_uses' => ['ArrayObject'],
        'compact' => ['name'],
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
        'current' => [$list],
        'date' => ['Y-m-d', 1704067200],
        default => null,
    };
}

/**
 * @param callable():mixed $fn
 */
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

function valueFingerprint(mixed $value): string
{
    if (is_object($value)) {
        return 'object:' . get_class($value);
    }

    return serialize($value);
}

$wrappers = WebNativeFunctionRegistry::wrappers();
$bench = [];
$skipped = [];

foreach ($wrappers as $wrapper) {
    if (($wrapper['worker_callable'] ?? false) !== true) {
        continue;
    }

    $name = (string) $wrapper['name'];
    $args = sampleArgs($name);

    if ($args === null) {
        $skipped[] = [$name, 'no deterministic sample args'];
        continue;
    }

    try {
        $phpValue = quietCall(static fn (): mixed => $name(...$args));
        $jinxValue = quietCall(static fn (): mixed => WebNativeFunctions::call($name, $args));
    } catch (Throwable $e) {
        $skipped[] = [$name, $e->getMessage()];
        continue;
    }

    if (valueFingerprint($phpValue) !== valueFingerprint($jinxValue)) {
        $skipped[] = [$name, 'php and jinx value mismatch'];
        continue;
    }

    $bench[] = [$name, $args, $phpValue];

    if (count($bench) >= $targetCount) {
        break;
    }
}

if (count($bench) < $targetCount) {
    fail("only found " . count($bench) . " benchmarkable wrappers; requested {$targetCount}");
}

$rows = [];
$totalPhpNs = 0;
$totalJinxNs = 0;

foreach ($bench as [$name, $args]) {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        quietCall(static fn (): mixed => $name(...$args));
    }
    $phpNs = hrtime(true) - $start;

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        quietCall(static fn (): mixed => WebNativeFunctions::call($name, $args));
    }
    $jinxNs = hrtime(true) - $start;

    $totalPhpNs += $phpNs;
    $totalJinxNs += $jinxNs;

    $rows[] = [
        'name' => $name,
        'php_us' => $phpNs / $iterations / 1000,
        'jinx_us' => $jinxNs / $iterations / 1000,
        'ratio' => $jinxNs / max($phpNs, 1),
    ];
}

printf("JINX first %d wrapper-function benchmark\n", $targetCount);
printf("Iterations per function: %d\n", $iterations);
printf("Skipped before target: %d\n\n", count($skipped));
printf("%-28s %12s %12s %9s\n", 'function', 'php us/op', 'jinx us/op', 'ratio');
printf("%'-65s\n", '');

foreach ($rows as $row) {
    printf(
        "%-28s %12.3f %12.3f %8.2fx\n",
        $row['name'],
        $row['php_us'],
        $row['jinx_us'],
        $row['ratio']
    );
}

printf("%'-65s\n", '');
printf(
    "%-28s %12.3f %12.3f %8.2fx\n",
    'TOTAL',
    $totalPhpNs / ($iterations * $targetCount) / 1000,
    $totalJinxNs / ($iterations * $targetCount) / 1000,
    $totalJinxNs / max($totalPhpNs, 1)
);

if ($skipped !== []) {
    echo "\nFirst skipped wrappers:\n";
    foreach (array_slice($skipped, 0, 12) as [$name, $reason]) {
        echo "- {$name}: {$reason}\n";
    }
}
